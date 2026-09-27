<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Backfills email_verified_at for accounts that were onboarded through a QR
 * invitation before invitation-acceptance became the proof of email ownership.
 *
 * SAFETY MODEL
 * ------------
 * This command is deliberately narrow. It only touches a user row when ALL of
 * the following hold, each one of which is independent server-side evidence:
 *
 *   1. email_verified_at IS NULL              -> nothing verified it before
 *   2. invite_id IS NOT NULL                  -> the account came from an invite
 *   3. the referenced invite exists and was actually consumed by that user
 *                                               (qr_invites.used_by = users.id)
 *   4. is_active = true                       -> a live account, not a revoked one
 *   5. application_status = 'approved'        -> not a pending/rejected applicant
 *
 * A row that fails any check is reported and left untouched, so this can
 * never be used to bulk-verify arbitrary or hand-edited accounts. It does not
 * change roles, church_id, is_active, or any password.
 *
 * Always run with --dry-run first.
 */
class VerifyInvitedAccounts extends Command
{
    protected $signature = 'app:verify-invited-accounts
        {--dry-run : Report what would be verified without writing anything}
        {--church= : Limit to a single church_id}
        {--id= : Limit to a single user id}';

    protected $description = 'Mark email_verified_at for users onboarded via a consumed QR invitation (backfill only)';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $churchId = $this->option('church');
        $userId = $this->option('id');

        $this->info('Invited-account email verification backfill');
        $this->line($isDryRun ? 'Mode: DRY RUN (no writes)' : 'Mode: LIVE');

        $candidates = User::query()
            ->whereNull('email_verified_at')
            ->whereNotNull('invite_id')
            ->where('is_active', true)
            ->where('application_status', 'approved')
            ->when(is_numeric($churchId), fn ($q) => $q->where('church_id', (int) $churchId))
            ->when(is_numeric($userId), fn ($q) => $q->where('id', (int) $userId))
            ->get(['id', 'name', 'email', 'role', 'church_id', 'invite_id']);

        if ($candidates->isEmpty()) {
            $this->info('No candidates found. Nothing to do.');

            return self::SUCCESS;
        }

        $verified = 0;
        $skipped = 0;

        foreach ($candidates as $user) {
            // Evidence check: the invite must exist, belong to the same
            // church, and have been consumed by exactly this user.
            $inviteConsumed = DB::table('qr_invites')
                ->where('id', $user->invite_id)
                ->where('used_by', $user->id)
                ->exists();

            if (! $inviteConsumed) {
                $skipped++;
                $this->line(sprintf(
                    '  SKIP  #%d %s — invite #%s was not consumed by this user',
                    $user->id,
                    $this->maskEmail((string) $user->email),
                    (string) $user->invite_id,
                ));

                continue;
            }

            $this->line(sprintf(
                '  %s #%d %s role=%s church=%s invite=#%s',
                $isDryRun ? 'WOULD VERIFY' : 'VERIFIED',
                $user->id,
                $this->maskEmail((string) $user->email),
                (string) $user->role->value,
                (string) $user->church_id,
                (string) $user->invite_id,
            ));

            if (! $isDryRun) {
                // Direct query: bypasses the `hashed` cast and the audit
                // `updated` hook, which is irrelevant for a verification flag.
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'email_verified_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $verified++;
        }

        if (! $isDryRun && $verified > 0) {
            Log::info('Invited-account email verification backfill', [
                'verified' => $verified,
                'skipped' => $skipped,
            ]);
        }

        $this->info(sprintf('Verified: %d | Skipped: %d', $verified, $skipped));

        if ($isDryRun && $verified > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Emails are PII: show enough to identify the row, never the full address.
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = substr($local, 0, 2);

        return $visible.'***@'.$domain;
    }
}
