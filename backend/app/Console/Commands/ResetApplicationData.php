<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AttendanceContext;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetApplicationData extends Command
{
    protected $signature = 'app:reset-data
        {--force : Skip confirmation prompt}
        {--platform-email=platform@churchplatform.local : Platform admin email}
        {--platform-password=password : Platform admin password}';

    protected $description = 'Delete all application data while preserving schema, then create a fresh Platform Admin';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('WARNING: This will delete ALL application data. Are you sure?')) {
                $this->info('Operation cancelled.');
                return self::FAILURE;
            }
        }

        $startTime = microtime(true);
        $recordsRemoved = [];

        $this->info('Starting application data reset...');
        $this->newLine();

        $this->line('Step 1/4: Counting records before deletion...');

        $tables = [
            'points'                 => 'Points',
            'attendances'            => 'Attendances',
            'feedback'               => 'Feedback',
            'daily_verses'           => 'Daily Verses',
            'events'                 => 'Events',
            'attendance_contexts'    => 'Attendance Contexts',
            'qr_invites'             => 'QR Invites',
            'class_years'            => 'Class Years',
            'audit_logs'             => 'Audit Logs',
            'churches'               => 'Churches',
            'church_applications'    => 'Church Applications',
            'personal_access_tokens' => 'Personal Access Tokens',
            'sessions'               => 'Sessions',
            'cache'                  => 'Cache',
            'cache_locks'            => 'Cache Locks',
            'jobs'                   => 'Jobs',
            'job_batches'            => 'Job Batches',
            'failed_jobs'            => 'Failed Jobs',
            'password_reset_tokens'  => 'Password Reset Tokens',
        ];

        foreach ($tables as $table => $label) {
            $recordsRemoved[$label] = DB::table($table)->count();
        }

        $userCount = DB::table('users')->count();
        $recordsRemoved['Users'] = $userCount;

        $totalToRemove = array_sum($recordsRemoved);
        $this->line("  Found {$totalToRemove} application records to remove.");

        $this->newLine();
        $this->line('Step 2/4: Disabling foreign key constraints...');

        DB::statement('SET session_replication_role = replica');

        $this->line('Step 3/4: Deleting application data...');
        $bar = $this->output->createProgressBar(count($tables));
        $bar->start();

        $deleted = [];

        foreach ($tables as $table => $label) {
            $count = DB::table($table)->count();
            if ($count > 0) {
                DB::table($table)->delete();
            }
            $deleted[$label] = $count;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('  Cleaning users table (self-referencing FKs)...');
        $deletedUsers = 0;
        if ($userCount > 0) {
            DB::table('users')->update([
                'invite_id'  => null,
                'servant_id' => null,
                'created_by' => null,
            ]);
            DB::table('users')->delete();
            $deletedUsers = $userCount;
        }
        $deleted['Users'] = $deletedUsers;

        $this->line('  Re-enabling foreign key constraints...');
        DB::statement('SET session_replication_role = origin');

        $this->newLine();
        $this->line('Step 4/4: Creating Platform Admin and seeding defaults...');

        $platformEmail = $this->option('platform-email');
        $platformPassword = $this->option('platform-password');

        $platformAdmin = User::create([
            'name'               => 'Platform Admin',
            'email'              => $platformEmail,
            'password'           => Hash::make($platformPassword),
            'role'               => UserRole::PlatformAdmin,
            'is_active'          => true,
            'application_status' => 'approved',
            'attendance_qr_token'=> User::generateAttendanceQrToken(),
        ]);

        $this->newLine();
        $this->line('  Platform Admin:');
        $this->line("    Email:    {$platformEmail}");
        $this->line("    Password: {$platformPassword}");

        $contexts = [
            ['name' => 'Sunday School',           'slug' => 'sunday-school',    'is_default' => true,  'is_active' => true],
            ['name' => 'Trip',                    'slug' => 'trip',             'is_default' => false, 'is_active' => true],
            ['name' => 'Retreat / Spiritual Day', 'slug' => 'retreat',          'is_default' => false, 'is_active' => true],
            ['name' => 'Mass / Service',          'slug' => 'mass',             'is_default' => false, 'is_active' => true],
            ['name' => 'Prayer Meeting',          'slug' => 'prayer-meeting',   'is_default' => false, 'is_active' => true],
            ['name' => 'Special Event',           'slug' => 'special-event',    'is_default' => false, 'is_active' => true],
        ];

        foreach ($contexts as $context) {
            AttendanceContext::create($context);
        }
        $this->line('  Attendance Contexts: 6 defaults seeded.');
        $this->line('  Migrations table: preserved (' . DB::table('migrations')->count() . ' migrations).');

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine(2);
        $this->info('========================================');
        $this->info('  RESET COMPLETE');
        $this->info('========================================');
        $this->line('');
        $this->line("  Duration: {$duration}s");
        $this->line('');
        $this->line('  Tables cleaned:        ' . count($deleted));
        $this->line('  Total records removed: ' . array_sum($deleted));
        $this->line('');
        $this->line('  Platform Admin:');
        $this->line("    Email:    {$platformEmail}");
        $this->line("    Password: {$platformPassword}");
        $this->line('');
        $this->line('  Remaining system records:');
        $this->line('    Migrations: ' . DB::table('migrations')->count());
        $this->line('    Platform Admins: 1');
        $this->line('');
        $this->info('  System is fully operational.');

        return self::SUCCESS;
    }
}
