<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string|null $group
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Permission extends Model
{
    protected $fillable = [
        'key',
        'name',
        'group',
        'description',
    ];

    protected $casts = [
        'key' => 'string',
    ];

    /** @return array<int, string> */
    public static function getPermissionsForRole(string $roleName): array
    {
        return Cache::remember("permissions_role_{$roleName}", 86400 * 30, function () use ($roleName) {
            $result = DB::table('role_permission as rp')
                ->join('permissions as p', 'rp.permission_key', '=', 'p.key')
                ->where('rp.role_name', $roleName)
                ->pluck('p.key')
                ->toArray();

            /** @var array<int, string> $result */
            return $result;
        });
    }

    public static function roleHasPermission(string $roleName, string $permissionKey): bool
    {
        return in_array($permissionKey, self::getPermissionsForRole($roleName), true);
    }

    public static function userHasPermission(User $user, string $permissionKey): bool
    {
        return self::roleHasPermission($user->role->value, $permissionKey);
    }

    public static function clearCache(): void
    {
        foreach (UserRole::cases() as $role) {
            Cache::forget("permissions_role_{$role->value}");
        }
    }

    /** @return array<int, array{key: string, name: string, group: string|null}> */
    public static function defaultPermissions(): array
    {
        return [
            ['key' => 'manage_members', 'name' => 'Manage Members', 'group' => 'users'],
            ['key' => 'manage_servants', 'name' => 'Manage Servants', 'group' => 'users'],
            ['key' => 'manage_users', 'name' => 'Manage All Users', 'group' => 'users'],
            ['key' => 'view_users', 'name' => 'View Users', 'group' => 'users'],
            ['key' => 'manage_events', 'name' => 'Manage Events', 'group' => 'events'],
            ['key' => 'view_events', 'name' => 'View Events', 'group' => 'events'],
            ['key' => 'manage_class_years', 'name' => 'Manage Class Years', 'group' => 'classes'],
            ['key' => 'view_class_years', 'name' => 'View Class Years', 'group' => 'classes'],
            ['key' => 'manage_attendance', 'name' => 'Manage Attendance', 'group' => 'attendance'],
            ['key' => 'record_attendance', 'name' => 'Record Attendance', 'group' => 'attendance'],
            ['key' => 'view_attendance', 'name' => 'View Attendance', 'group' => 'attendance'],
            ['key' => 'manage_invites', 'name' => 'Manage Invites', 'group' => 'invites'],
            ['key' => 'view_invites', 'name' => 'View Invites', 'group' => 'invites'],
            ['key' => 'manage_feedback', 'name' => 'Manage Feedback', 'group' => 'feedback'],
            ['key' => 'view_feedback', 'name' => 'View Feedback', 'group' => 'feedback'],
            ['key' => 'manage_verses', 'name' => 'Manage Daily Verses', 'group' => 'verses'],
            ['key' => 'view_verses', 'name' => 'View Daily Verses', 'group' => 'verses'],
            ['key' => 'manage_attendance_contexts', 'name' => 'Manage Attendance Contexts', 'group' => 'attendance'],
            ['key' => 'view_analytics', 'name' => 'View Analytics', 'group' => 'analytics'],
            ['key' => 'manage_church_settings', 'name' => 'Manage Church Settings', 'group' => 'settings'],
            ['key' => 'manage_points', 'name' => 'Manage Points', 'group' => 'points'],
            ['key' => 'view_points', 'name' => 'View Points', 'group' => 'points'],
            ['key' => 'manage_membership_requests', 'name' => 'Manage Membership Requests', 'group' => 'users'],
            ['key' => 'view_membership_requests', 'name' => 'View Membership Requests', 'group' => 'users'],
            ['key' => 'submit_feedback', 'name' => 'Submit Feedback', 'group' => 'feedback'],
        ];
    }

    /** @return array<string, array<int, string>> */
    public static function defaultRolePermissions(): array
    {
        return [
            UserRole::Admin->value => [
                'manage_members', 'manage_servants', 'manage_users', 'view_users',
                'manage_events', 'view_events',
                'manage_class_years', 'view_class_years',
                'manage_attendance', 'record_attendance', 'view_attendance',
                'manage_invites', 'view_invites',
                'manage_feedback', 'view_feedback',
                'manage_verses', 'view_verses',
                'manage_attendance_contexts',
                'view_analytics',
                'manage_church_settings',
                'manage_points', 'view_points',
                'manage_membership_requests', 'view_membership_requests',
            ],
            UserRole::AssistantAdmin->value => [
                'manage_members', 'manage_servants', 'manage_users', 'view_users',
                'manage_events', 'view_events',
                'manage_class_years', 'view_class_years',
                'manage_attendance', 'record_attendance', 'view_attendance',
                'manage_invites', 'view_invites',
                'manage_feedback', 'view_feedback',
                'manage_verses', 'view_verses',
                'manage_attendance_contexts',
                'view_analytics',
                'manage_church_settings',
                'manage_points', 'view_points',
                'manage_membership_requests', 'view_membership_requests',
            ],
            UserRole::Servant->value => [
                'view_users',
                'view_events', 'manage_events',
                'view_class_years',
                'record_attendance', 'view_attendance',
                'manage_invites', 'view_invites',
                'view_feedback',
                'view_verses', 'manage_verses',
                'manage_attendance_contexts',
                'view_points',
            ],
            UserRole::Member->value => [
                'view_events',
                'view_class_years',
                'view_attendance',
                'view_verses',
                'view_points',
                'submit_feedback',
            ],
        ];
    }
}
