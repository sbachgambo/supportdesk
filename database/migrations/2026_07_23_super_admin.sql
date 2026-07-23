-- Migration: System Super Admin role (protected, hidden owner account).
--
-- Adds 'super_admin' to the users + sessions role ENUMs and seeds ONE hidden super
-- admin. This account never appears in the Agents list and cannot be edited,
-- deactivated, deleted or password-reset through the app (enforced in AdminActions) —
-- it is the break-glass owner. Change its password on first login (must_change_pw = 1).
--
-- Run once on a database that predates the super_admin role. Fresh installs get
-- everything from schema.sql + seed.sql and do NOT run this file.
--
--   mysql -u <user> -p <db> < database/migrations/2026_07_23_super_admin.sql

ALTER TABLE users
  MODIFY COLUMN role ENUM('super_admin','admin','org_admin','agent','customer') NOT NULL DEFAULT 'customer';

ALTER TABLE sessions
  MODIFY COLUMN role ENUM('super_admin','admin','org_admin','agent','customer') NOT NULL;

-- Seed the hidden super admin only if one does not already exist. Uses the shared seed
-- password hash (= the documented seed password); must_change_pw forces a reset on first
-- login. CHANGE the email below to your real owner address before running if you prefer.
INSERT INTO users (public_id, name, email, password_hash, role, active, must_change_pw, created_at)
SELECT 'SA-0001', 'System Super Admin', 'superadmin@p3a-support.com.ng',
       '$2y$12$8xBEdTyz2WvCEtUH6HV4MONhzj2yVdJ2GX02kPAnGK2eZPROI.3uu', 'super_admin', 1, 1, UTC_TIMESTAMP()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM users WHERE role = 'super_admin');
