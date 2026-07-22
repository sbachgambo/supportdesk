-- Migration: Products / Projects.
--
-- A shared, admin-managed list of products/projects. A client picks one on the ticket
-- form; it is stored on the ticket for filtering and reporting. Products behave like the
-- organizations list but carry NO tenancy/routing meaning — they are a labelling field.
--
-- Run once on a database that already has the tickets table. Fresh installs get
-- everything from schema.sql and do NOT run this file.
--
--   mysql -u <user> -p <db> < database/migrations/2026_07_22_products.sql

CREATE TABLE products (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id VARCHAR(16) UNIQUE NOT NULL,             -- "PRD-0001"
  name       VARCHAR(120) NOT NULL,
  active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  INDEX idx_prod_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets
  ADD COLUMN product_id VARCHAR(16) NULL AFTER category_id,
  ADD INDEX idx_tk_product (product_id),
  ADD CONSTRAINT fk_tk_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE SET NULL;
