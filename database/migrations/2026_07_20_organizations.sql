-- Migration: multi-tenancy via Organizations (renames the earlier `companies` list).
--
-- Organizations are tenants: a client picks their organization on the ticket form and
-- the ticket routes to that organization's agents. Each agent is linked to one org.
--
-- Run once on a database that already has the `companies` table + tickets.company.
-- Fresh installs get everything from schema.sql and do NOT run this file.
--
--   mysql -u <user> -p <db> < database/migrations/2026_07_20_organizations.sql

RENAME TABLE companies TO organizations;
ALTER TABLE organizations CHANGE COLUMN company_id organization_id VARCHAR(16) NOT NULL;

ALTER TABLE users
  ADD COLUMN organization_id VARCHAR(16) NULL AFTER must_change_pw,
  ADD INDEX idx_users_org (organization_id);

ALTER TABLE tickets
  ADD COLUMN organization_id VARCHAR(16) NULL AFTER customer_user_id,
  ADD INDEX idx_tk_org (organization_id, status),
  ADD CONSTRAINT fk_tk_org FOREIGN KEY (organization_id) REFERENCES organizations(organization_id) ON DELETE SET NULL;

ALTER TABLE tickets DROP COLUMN company;
