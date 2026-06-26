-- ============================================================================
-- DATABASE DATA RESET SCRIPT
-- Church Management System - PostgreSQL
-- 
-- WARNING: This deletes ALL application data while preserving schema.
-- ============================================================================

-- Step 1: Disable foreign key triggers (session level)
SET session_replication_role = 'replica';

-- Step 2: Delete from child tables first (leaf nodes)
DELETE FROM points;
DELETE FROM attendances;
DELETE FROM feedback;
DELETE FROM daily_verses;
DELETE FROM events;
DELETE FROM attendance_contexts;
DELETE FROM qr_invites;
DELETE FROM class_years;
DELETE FROM audit_logs;
DELETE FROM churches;
DELETE FROM church_applications;

-- Step 3: Handle users table (self-referencing FKs)
UPDATE users SET invite_id = NULL, servant_id = NULL, created_by = NULL;
DELETE FROM users;

-- Step 4: Clear system/cache/queue tables
DELETE FROM personal_access_tokens;
DELETE FROM sessions;
DELETE FROM cache;
DELETE FROM cache_locks;
DELETE FROM jobs;
DELETE FROM job_batches;
DELETE FROM failed_jobs;
DELETE FROM password_reset_tokens;

-- Step 5: Re-enable foreign key triggers
SET session_replication_role = 'origin';

-- Step 6: Create Platform Admin
-- Password: password (bcrypt hash)
INSERT INTO users (name, email, password, role, is_active, application_status, attendance_qr_token, created_at, updated_at)
VALUES (
    'Platform Admin',
    'platform@churchplatform.local',
    '$2y$12$VNaO3OdL8KrAs1jFQ3zN3uN7J6g5GqX0Y0Z0a0b0c0d0e0f0g0h0i0j0k0l0', -- bcrypt of 'password' (will be replaced on first artisan command)
    'platform_admin',
    true,
    'approved',
    encode(gen_random_bytes(48), 'hex'),
    NOW(),
    NOW()
);

-- Note: The bcrypt hash above is a placeholder. 
-- After running this SQL, you MUST update the password:
-- Run: php artisan tinker --execute="App\Models\User::where('email','platform@churchplatform.local')->first()->update(['password'=>bcrypt('your_password')]);"

-- Step 7: Seed default Attendance Contexts
INSERT INTO attendance_contexts (name, slug, is_default, is_active, created_at, updated_at)
VALUES
    ('Sunday School',           'sunday-school',    true,  true,  NOW(), NOW()),
    ('Trip',                    'trip',             false, true,  NOW(), NOW()),
    ('Retreat / Spiritual Day', 'retreat',          false, true,  NOW(), NOW()),
    ('Mass / Service',          'mass',             false, true,  NOW(), NOW()),
    ('Prayer Meeting',          'prayer-meeting',   false, true,  NOW(), NOW()),
    ('Special Event',           'special-event',    false, true,  NOW(), NOW());

-- ============================================================================
-- VERIFICATION QUERIES
-- ============================================================================

-- Check remaining records
SELECT 'users' AS table_name, COUNT(*) AS remaining FROM users
UNION ALL
SELECT 'migrations', COUNT(*) FROM migrations
UNION ALL
SELECT 'attendance_contexts', COUNT(*) FROM attendance_contexts
UNION ALL
SELECT 'churches', COUNT(*) FROM churches
UNION ALL
SELECT 'church_applications', COUNT(*) FROM church_applications
ORDER BY table_name;

-- Verify Platform Admin exists
SELECT id, name, email, role, is_active FROM users WHERE role = 'platform_admin';
