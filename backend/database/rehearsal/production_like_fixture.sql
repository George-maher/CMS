-- Realistic production-like fixture for the migration rehearsal.
--
-- Deliberately includes the legacy states a real pre-constraint database can
-- hold, and nothing that a correct database could hold:
--   * a clean tenant (church 1) that must be left completely untouched
--   * LEGACY 1  cross-church: users.church_id = 1 but class 3 belongs to 2
--   * LEGACY 2  church-less : class 1 belongs to church 1, church_id is NULL
--   * LEGACY 3  orphan     : class 999 does not exist; church_id MUST survive
--
-- The single-column foreign keys are dropped for the load and restored
-- NOT VALID afterwards. That is what a genuinely inconsistent legacy
-- database looks like: the constraint either was not present, or was added
-- with ADD CONSTRAINT ... NOT VALID, or rows were written before it existed.
-- NOT VALID still enforces the constraint on all future writes.
--
-- Verification is performed by `php artisan tenant:audit` and by the
-- composite-FK migration's own guard — never by assertions in this file.

BEGIN;

TRUNCATE users, classes, stages, churches, attendance_contexts, qr_invites RESTART IDENTITY CASCADE;

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_class_id_foreign;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_class_year_id_foreign;
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_stage_id_foreign;
ALTER TABLE qr_invites DROP CONSTRAINT IF EXISTS qr_invites_created_by_foreign;

INSERT INTO churches (id, name, slug, priest_name, is_active, created_at, updated_at) VALUES
  (1, 'St Mary Coptic Orthodox',  'st-mary',  'Fr. Botros', true, now(), now()),
  (2, 'St George Greek Orthodox', 'st-george','Fr. Nader',  true, now(), now()),
  (3, 'Holy Trinity Coptic',      'trinity',  'Fr. Wagih',  true, now(), now());

INSERT INTO stages (id, church_id, name, display_order, created_at, updated_at) VALUES
  (1, 1, 'Primary',   1, now(), now()),
  (2, 1, 'Secondary', 2, now(), now()),
  (3, 2, 'Primary',   1, now(), now()),
  (4, 3, 'Primary',   1, now(), now());

INSERT INTO classes (id, church_id, stage_id, name, display_order, created_at, updated_at) VALUES
  (1, 1, 1, 'P-1', 1, now(), now()),
  (2, 1, 2, 'S-1', 1, now(), now()),
  (3, 2, 3, 'P-1', 1, now(), now()),
  (4, 3, 4, 'P-1', 1, now(), now());

INSERT INTO attendance_contexts (id, church_id, name, slug, is_active, created_at, updated_at) VALUES
  (1, 1, 'Sunday School', 'sunday-school', true, now(), now()),
  (2, 2, 'Sunday School', 'sunday-school', true, now(), now());

-- id 1-3 are clean and correctly owned; they must be untouched by any repair.
INSERT INTO users (id, church_id, name, email, password, role, application_status, scope, class_id, stage_id, created_at, updated_at) VALUES
  (1, 1, 'Clean Member', 'clean@church1.test',    'x', 'member', 'approved', 'self',   1,    1, now(), now()),
  (2, 1, 'Clean Admin',  'admin@church1.test',    'x', 'admin',  'approved', 'church', NULL, NULL, now(), now()),
  (3, 2, 'Other Church', 'admin@church2.test',    'x', 'admin',  'approved', 'church', NULL, NULL, now(), now()),
  -- LEGACY 1: church_id says 1, but class 3 and stage 3 belong to church 2.
  (4, 1, 'Cross Church', 'cross@church1.test',    'x', 'member', 'approved', 'self',   3,    3, now(), now()),
  -- LEGACY 2: no church at all, but class 1 and stage 1 belong to church 1.
  (5, NULL, 'No Church', 'nochurch@church1.test', 'x', 'member', 'approved', 'self',   1,    1, now(), now()),
  -- LEGACY 3: orphan. class 999 and stage 1; the church_id must survive.
  (6, 1, 'Orphaned',     'orphan@church1.test',   'x', 'member', 'approved', 'self',   999,  1, now(), now());

INSERT INTO qr_invites (id, church_id, created_by, token, type, class_id, stage_id, expires_at, is_revoked, is_single_use, use_count, created_at, updated_at) VALUES
  (1, 1, 2, 'rehearsal-token-1', 'servant_to_member_invite', 1, 1, now() + interval '4 hours', false, true, 0, now(), now());

-- Restore the constraints WITHOUT validating the rows that already exist.
ALTER TABLE users ADD CONSTRAINT users_class_id_foreign
  FOREIGN KEY (class_id) REFERENCES classes (id) ON DELETE SET NULL NOT VALID;
ALTER TABLE users ADD CONSTRAINT users_stage_id_foreign
  FOREIGN KEY (stage_id) REFERENCES stages (id) ON DELETE SET NULL NOT VALID;
ALTER TABLE qr_invites ADD CONSTRAINT qr_invites_created_by_foreign
  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE NOT VALID;

-- Advance every sequence past the explicit ids inserted above.
--
-- Without this, users_id_seq is still at 1 and the first generated user
-- collides on the primary key. That failure is unrelated to tenant isolation
-- and would be easy to misread as one.
SELECT setval(pg_get_serial_sequence('churches', 'id'),         (SELECT max(id) FROM churches));
SELECT setval(pg_get_serial_sequence('stages', 'id'),            (SELECT max(id) FROM stages));
SELECT setval(pg_get_serial_sequence('classes', 'id'),           (SELECT max(id) FROM classes));
SELECT setval(pg_get_serial_sequence('users', 'id'),             (SELECT max(id) FROM users));
SELECT setval(pg_get_serial_sequence('attendance_contexts', 'id'), (SELECT max(id) FROM attendance_contexts));
SELECT setval(pg_get_serial_sequence('qr_invites', 'id'),        (SELECT max(id) FROM qr_invites));

COMMIT;
