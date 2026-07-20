-- Migration: add the Organization Admin role.
--
-- System Admin ('admin')      — super admin, cross-organization, all system config.
-- Organization Admin ('org_admin') — their organization's data + manages their own
--                                     org's agents; no system config or other orgs.
-- Agent ('agent')             — their organization's tickets only.
--
--   mysql -u <user> -p <db> < database/migrations/2026_07_20_org_admin_role.sql

ALTER TABLE users
  MODIFY COLUMN role ENUM('admin','org_admin','agent','customer') NOT NULL DEFAULT 'customer';

-- The sessions table stores the role too; keep the enums in sync.
ALTER TABLE sessions
  MODIFY COLUMN role ENUM('admin','org_admin','agent','customer') NOT NULL;
