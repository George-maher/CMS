<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill church_id on qr_invites where church_id is null
        $nullChurchInvites = DB::table('qr_invites')->whereNull('church_id')->get();
        foreach ($nullChurchInvites as $invite) {
            $creator = User::find($invite->created_by);
            if ($creator && $creator->church_id) {
                DB::table('qr_invites')
                    ->where('id', $invite->id)
                    ->update(['church_id' => $creator->church_id]);
            }
        }

        // 2. Add composite unique indexes for attendance performance
        try {
            Schema::table('attendances', function (Blueprint $t) {
                $t->unique(['user_id', 'attended_at', 'church_id'], 'attendances_user_date_church_unique');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        try {
            Schema::table('points', function (Blueprint $t) {
                $t->index(['user_id', 'church_id', 'created_at'], 'points_user_church_date_idx');
            });
        } catch (\Exception $e) {
            // Index may already exist
        }

        // 3. Add indexes on membership_requests
        try {
            Schema::table('membership_requests', function (Blueprint $t) {
                $t->index(['church_id', 'status'], 'membership_req_church_status_idx');
                $t->index('email', 'membership_req_email_idx');
                $t->index('created_at', 'membership_req_created_at_idx');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }

        // 4. Add email verification columns to users if not present
        if (!Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $t) {
                $t->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (!Schema::hasColumn('users', 'email_verification_token')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('email_verification_token', 64)->nullable()->unique()->after('email_verified_at');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'attendances' => 'attendances_user_date_church_unique',
            'points' => 'points_user_church_date_idx',
            'membership_requests' => ['membership_req_church_status_idx', 'membership_req_email_idx', 'membership_req_created_at_idx'],
        ];

        foreach ($tables as $table => $indexes) {
            $indexes = (array) $indexes;
            foreach ($indexes as $index) {
                try {
                    Schema::table($table, function (Blueprint $t) use ($index) {
                        $t->dropIndex($index);
                    });
                } catch (\Exception $e) {
                    // Index may not exist
                }
            }
        }

        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'email_verification_token')) {
                $t->dropColumn('email_verification_token');
            }
        });
    }
};
