-- Migration: client Company/Institution support.
-- Adds a `company` free-text field to tickets and an admin-managed `companies`
-- suggestion list. Safe to run once on an existing database.
--
--   mysql -u <user> -p <db> < database/migrations/2026_07_19_add_companies.sql

ALTER TABLE tickets
  ADD COLUMN company VARCHAR(120) NOT NULL DEFAULT '' AFTER customer_user_id;

CREATE TABLE IF NOT EXISTS companies (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id  VARCHAR(16) UNIQUE NOT NULL,
  name        VARCHAR(120) NOT NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL,
  INDEX idx_co_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
