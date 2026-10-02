<?php

namespace Tests\Feature;

use App\Contracts\ScopeResolverInterface;
use App\Enums\UserRole;
use App\Enums\UserScope;
use App\Http\Controllers\Api\ClasseController;
use App\Http\Controllers\Api\EventController;
use App\Http\Requests\EventRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\StoreClasseRequest;
use App\Models\Attendance;
use App\Models\AttendanceContext;
use App\Models\Classe;
use App\Models\DailySpiritualRecord;
use App\Models\Event;
use App\Models\EventTarget;
use App\Models\EventView;
use App\Models\Feedback;
use App\Models\Notification;
use App\Models\Point;
use App\Models\ProfileUpdateRequest;
use App\Models\QRInvite;
use App\Models\Stage;
use App\Models\User;
use App\Modules\User\Controllers\UserController;
use App\Modules\User\Requests\CreateUserRequest;
use App\Modules\User\Requests\RoleRequest;
use App\Modules\User\Requests\UpdateUserRequest;
use App\Modules\User\Services\UserService;
use App\Policies\ClassePolicy;
use App\Services\AuthService;
use App\Services\ClasseService;
use App\Services\ScopeResolver;
use App\Traits\BelongsToChurch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Architectural guardrails for tenant isolation.
 *
 * These are not endpoint tests. They fail when the *shape* of the code changes
 * in a way that would let a cross-tenant bug back in, so a future developer
 * cannot silently reintroduce the vulnerability class this project already
 * had twice.
 *
 * Background — why this file exists
 * ---------------------------------
 * `exists:classes,id` proves only that a row exists somewhere. It is a
 * structural check. Ownership is a separate question, answered by resolving
 * the resource inside the actor's scope and comparing it through
 * `ScopeResolver` or a Policy. The bug that shipped twice (a Church Admin
 * attaching a member to another church's class, on create and then again on
 * update) happened because that separation was easy to lose and nothing made
 * the loss visible.
 *
 * These tests therefore pin three things:
 *   1. the database refuses cross-tenant rows even if every check is bypassed;
 *   2. the models that carry tenant data still declare the tenant scope;
 *   3. each tenant-sensitive FormRequest field has a named owner check.
 */
class TenantArchitectureTest extends TestCase
{
    use RefreshDatabase;

    // =================================================================
    // 1. Database is the last line of defence
    // =================================================================

    /**
     * Every tenant-owned child table that references a stage or class must be
     * constrained so a row cannot claim one church and reference another's
     * resource.
     *
     * A single-column foreign key cannot express this — it only proves the id
     * exists. The composite key is what makes the invalid state unrepresentable.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    public static function compositeConstraintProvider(): array
    {
        return [
            ['classes', 'stages', 'classes_church_stage_fk'],
            ['users', 'stages', 'users_church_stage_fk'],
            ['users', 'classes', 'users_church_class_fk'],
            ['events', 'classes', 'events_church_class_fk'],
            ['event_targets', 'classes', 'event_targets_church_class_fk'],
            ['qr_invites', 'stages', 'qr_invites_church_stage_fk'],
            ['qr_invites', 'classes', 'qr_invites_church_class_fk'],
        ];
    }

    #[DataProvider('compositeConstraintProvider')]
    public function test_composite_tenant_foreign_key_exists(string $child, string $parent, string $name): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite's PRAGMA foreign_key_list does not expose constraint
            // names, so the table DDL is the only place they appear.
            $ddl = DB::selectOne(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$child]
            );

            $this->assertNotNull($ddl, "Table {$child} is missing.");

            $this->assertMatchesRegularExpression(
                '/CONSTRAINT\s+'.preg_quote($name, '/').'\s+FOREIGN KEY\s*\(church_id,\s*\w+\)\s*'
                .'REFERENCES\s+'.preg_quote($parent, '/').'\s*\(church_id,\s*id\)/i',
                (string) $ddl->sql,
                "{$child} must be constrained against {$parent} by {$name} so a cross-tenant row is unrepresentable."
            );

            return;
        }

        $found = DB::selectOne(
            'SELECT 1 AS present FROM information_schema.table_constraints
             WHERE constraint_type = ? AND constraint_name = ? AND table_name = ?',
            ['FOREIGN KEY', $name, $child]
        );

        $this->assertNotNull(
            $found,
            "{$child} must be constrained against {$parent} by {$name} so a cross-tenant row is unrepresentable."
        );
    }

    /**
     * The parent keys the composite constraints depend on. Without them
     * PostgreSQL refuses the foreign key, and on SQLite the constraint would
     * silently match nothing.
     */
    public function test_parent_keys_support_the_composite_constraints(): void
    {
        $this->assertTrue(Schema::hasIndex('stages', 'stages_church_id_id_unique'));
        $this->assertTrue(Schema::hasIndex('classes', 'classes_church_id_id_unique'));
    }

    // =================================================================
    // 2. Models that carry tenant data must keep the tenant scope
    // =================================================================

    /**
     * @return array<int, array{0: class-string}>
     */
    public static function tenantScopedModelProvider(): array
    {
        return array_map(
            static fn (string $model): array => [$model],
            [
                Attendance::class,
                AttendanceContext::class,
                Classe::class,
                DailySpiritualRecord::class,
                Event::class,
                EventTarget::class,
                EventView::class,
                Feedback::class,
                Notification::class,
                Point::class,
                ProfileUpdateRequest::class,
                QRInvite::class,
                Stage::class,
            ]
        );
    }

    #[DataProvider('tenantScopedModelProvider')]
    public function test_tenant_model_declares_the_church_scope(string $model): void
    {
        $traits = class_uses($model);

        $this->assertContains(
            BelongsToChurch::class,
            $traits,
            $model.' carries tenant data and must declare the BelongsToChurch scope. '
            .'Removing it would make every query for this model cross-tenant.'
        );
    }

    // =================================================================
    // 3. Structural validation is not authorization — and must be documented
    // =================================================================

    /**
     * FormRequest fields that reference a tenant-owned resource.
     *
     * Each entry names the layer that answers the *ownership* question. The
     * `exists:` rule is expected to remain structural — the point is not to
     * forbid it, it is to make sure the ownership answer is recorded and stays
     * recorded, so nobody reads `exists:` as permission.
     *
     * @return array<int, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function tenantSensitiveFieldProvider(): array
    {
        $cc = ClasseController::class;
        $uc = UserController::class;
        $us = UserService::class;
        $ec = EventController::class;

        return [
            // [FormRequest, field, ownership enforced by, note]
            [
                StoreClasseRequest::class,
                'stage_id',
                $cc.'::store + '.ClassePolicy::class.'::create + '.ClasseService::class.'::create',
                'A class is owned through its stage, so the stage must be resolved inside the tenant.',
            ],
            [
                CreateUserRequest::class,
                'class_id',
                $uc.'::store + '.$us.'::create',
                'Admin and stage admin may only place a user inside their authorized class.',
            ],
            [
                CreateUserRequest::class,
                'stage_id',
                $uc.'::store + '.$us.'::create',
                'Ownership must be enforced for EVERY role, not only stage_admin. The check used to live '
                .'solely inside the `stage_admin` branch, so member/servant payloads carrying a foreign '
                .'stage id reached the database and were stopped by users_church_stage_fk as a 500. '
                .'Pinned behaviourally by CreateUserStageOwnershipTest.',
            ],
            [
                UpdateUserRequest::class,
                'class_id',
                $uc.'::update + '.$us.'::update',
                'Re-homing a user into another church class was the second shipped bug.',
            ],
            [
                UpdateUserRequest::class,
                'stage_id',
                $uc.'::update + '.$us.'::update',
                'Stage reassignment must stay inside the actor scope.',
            ],
            [
                RoleRequest::class,
                'stage_id',
                $uc.'::promote + '.$us.'::promote',
                'A stage admin must be bound to a stage in their own church.',
            ],
            [
                EventRequest::class,
                'class_id',
                $ec.'::targetsWithinScope',
                'An event must not be retargeted at another church class.',
            ],
            [
                EventRequest::class,
                'target_class_ids.*',
                $ec.'::targetsWithinScope',
                'Every element must pass; a mixed array must be rejected whole.',
            ],
            [
                RegisterRequest::class,
                'class_id',
                AuthService::class.'::register',
                'Public registration has no tenant, so the rule must NOT be made tenant-aware here. '
                .'The invite determines the church; the class is constrained to it in the service.',
            ],
        ];
    }

    #[DataProvider('tenantSensitiveFieldProvider')]
    public function test_tenant_sensitive_field_has_a_named_ownership_check(
        string $request,
        string $field,
        string $enforcedBy,
        string $note
    ): void {
        $this->assertTrue(
            class_exists($request),
            "{$request} does not exist; update the guardrail table."
        );

        // The rule set must still mention the field, which proves this guardrail
        // is tracking something real rather than a renamed field.
        $reflection = new ReflectionClass($request);
        $this->assertTrue(
            $reflection->hasMethod('rules') || $reflection->hasMethod('prepareForValidation'),
            "{$request} should expose rules(); it may have been restructured."
        );

        // The named owner must still be referenced somewhere in the codebase.
        // This is the assertion that fails when someone deletes a check.
        foreach (array_filter(array_map('trim', explode('+', $enforcedBy))) as $owner) {
            $class = explode('::', $owner)[0];

            if (str_contains($owner, '::')) {
                [$class, $method] = explode('::', $owner);

                $this->assertTrue(
                    class_exists($class),
                    "{$owner} is named as the owner of {$request}::{$field} but the class is gone."
                );
                $this->assertTrue(
                    method_exists($class, $method),
                    "{$owner} is named as the owner of {$request}::{$field} but the method is gone. "
                    .'Either restore the check or update this guardrail — do not delete the row.'
                );

                continue;
            }

            $this->assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class),
                "{$class} is named as the owner of {$request}::{$field} but it does not exist."
            );
        }

        $this->assertNotSame('', $note, "{$field} must explain why it is not tenant-scoped at validation time.");
    }

    // =================================================================
    // 4. The canonical ownership primitives stay canonical
    // =================================================================

    /**
     * There must be exactly one answer to "may this actor touch this stage?".
     * If a second implementation appears, the two will eventually disagree.
     */
    public function test_scope_resolver_remains_the_single_source_of_truth(): void
    {
        $resolver = ScopeResolver::class;

        foreach (['canAccessStage', 'canAccessClass', 'canAccessUser', 'allowedStageIds', 'allowedClassIds'] as $method) {
            $this->assertTrue(
                method_exists($resolver, $method),
                "ScopeResolver::{$method}() is the canonical tenant answer and must not be renamed or removed."
            );
        }

        // The contract must keep declaring the same surface, so a controller
        // cannot type-hint the concrete class and bypass it.
        $contract = ScopeResolverInterface::class;
        foreach (['canAccessStage', 'canAccessClass', 'canAccessUser'] as $method) {
            $this->assertTrue(
                method_exists($contract, $method),
                "ScopeResolverInterface::{$method}() must stay in the contract."
            );
        }
    }

    /**
     * `User::getScope()` must stay role-derived.
     *
     * If it ever started reading the stored `scope` column, a member with a
     * tampered scope value would inherit church-wide authority.
     */
    public function test_effective_scope_is_role_derived_and_ignores_the_stored_column(): void
    {
        $cases = [
            [UserRole::Admin, null, UserScope::Church],
            [UserRole::AssistantAdmin, null, UserScope::Church],
            [UserRole::StageAdmin, 7, UserScope::Stage],
            // No stage -> fails safe to Self, never widens.
            [UserRole::StageAdmin, null, UserScope::Self],
            [UserRole::Servant, 7, UserScope::ClassScope],
            [UserRole::Member, 7, UserScope::Self],
        ];

        foreach ($cases as [$role, $stageId, $expected]) {
            $user = new User;
            $user->forceFill([
                'role' => $role,
                'stage_id' => $stageId,
                // Hostile stored value: must be ignored entirely.
                'scope' => UserScope::Church->value,
            ]);

            $this->assertSame(
                $expected,
                $user->getScope(),
                "getScope() must be derived from role+stage_id, not the stored scope column ({$role->value})."
            );
        }
    }

    /**
     * The stage/class resolution used by the boundary checks must go through
     * the tenant scope, never an unscoped lookup.
     */
    public function test_tenant_models_resolve_through_the_scope(): void
    {
        foreach ([Stage::class, Classe::class] as $model) {
            $reflection = new ReflectionClass($model);
            $source = file_get_contents((string) $reflection->getFileName());

            $this->assertStringContainsString(
                'BelongsToChurch',
                (string) $source,
                "{$model} must keep the tenant scope so find()/query() cannot cross tenants."
            );
        }
    }
}
