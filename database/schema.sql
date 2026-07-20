-- ══════════════════════════════════════════════════════════════════════════
-- P3A Helpdesk — schema (§7). 15 tables. InnoDB / utf8mb4 / utf8mb4_unicode_ci.
-- All datetimes stored UTC; rendered in APP_TIMEZONE.
-- Forward-only. Schema changes run under a separate migration credential (§5).
-- ══════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── users ──────────────────────────────────────────────────
CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id      VARCHAR(16) UNIQUE NOT NULL,              -- "AG-0001" / "CU-0001" (GAS-compatible)
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(254) UNIQUE NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,                    -- password_hash(); accepts legacy "v2$salt$hash"
  role           ENUM('admin','org_admin','agent','customer') NOT NULL DEFAULT 'customer',
  active         TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret    VARBINARY(255) NULL,                      -- encrypted with APP_KEY; NULL = not enrolled
  totp_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  totp_last_step BIGINT NULL,                              -- last consumed TOTP time-step (replay reject, D8)
  must_change_pw TINYINT(1) NOT NULL DEFAULT 0,            -- set on admin-set passwords
  organization_id VARCHAR(16) NULL,                        -- agent's organization (tenant); NULL = admin/cross-org or general
  last_login_at  DATETIME NULL,
  created_at     DATETIME NOT NULL,
  INDEX idx_users_email_active (email, active),
  INDEX idx_users_role_active (role, active),
  INDEX idx_users_org (organization_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── totp_backup_codes ──────────────────────────────────────
CREATE TABLE totp_backup_codes (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id   INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,                         -- password_hash of the code
  used_at   DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_backup_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── categories (two-level) ─────────────────────────────────
CREATE TABLE categories (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id VARCHAR(16) UNIQUE NOT NULL,                 -- "CAT-001"
  name        VARCHAR(60) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  color       VARCHAR(7) NOT NULL DEFAULT '#4057F5',       -- validated /^#[0-9a-fA-F]{6}$/
  active      TINYINT(1) NOT NULL DEFAULT 1,
  parent_id   VARCHAR(16) NULL,                            -- NULL = top level. Two levels ONLY.
  created_at  DATETIME NOT NULL,
  FOREIGN KEY (parent_id) REFERENCES categories(category_id) ON DELETE RESTRICT,
  INDEX idx_cat_parent (parent_id),
  INDEX idx_cat_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Organizations (tenants). A client picks their organization on the ticket form and
-- the ticket is routed to that organization's agents (multi-tenancy). Each agent is
-- linked to one organization (users.organization_id); admins are cross-organization.
CREATE TABLE organizations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  organization_id VARCHAR(16) UNIQUE NOT NULL,             -- "ORG-0001"
  name            VARCHAR(120) NOT NULL,
  active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL,
  INDEX idx_org_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── tickets ────────────────────────────────────────────────
CREATE TABLE tickets (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id               VARCHAR(20) UNIQUE NOT NULL,     -- "TKT-2026-0001"
  subject                 VARCHAR(200) NOT NULL,
  description             TEXT NOT NULL,
  customer_name           VARCHAR(120) NOT NULL DEFAULT '',
  customer_email          VARCHAR(254) NOT NULL,
  customer_user_id        INT UNSIGNED NULL,               -- set when the customer has an account (D2)
  organization_id         VARCHAR(16) NULL,                -- client's organization (tenant); NULL = general queue
  priority                ENUM('urgent','high','normal','low') NOT NULL DEFAULT 'normal',
  status                  ENUM('open','pending','resolved','closed') NOT NULL DEFAULT 'open',
  category_id             VARCHAR(16) NULL,
  tags                    VARCHAR(255) NOT NULL DEFAULT '',
  channel                 ENUM('web_form','agent','email','status_page','widget') NOT NULL,
  assigned_to             VARCHAR(254) NULL,               -- agent email; NULL = unassigned
  created_at              DATETIME NOT NULL,
  updated_at              DATETIME NOT NULL,
  first_response_at       DATETIME NULL,
  resolved_at             DATETIME NULL,
  sla_response_deadline   DATETIME NOT NULL,
  sla_resolution_deadline DATETIME NOT NULL,
  sla_response_status     ENUM('pending','met','breached') NOT NULL DEFAULT 'pending',
  sla_resolution_status   ENUM('pending','met','breached') NOT NULL DEFAULT 'pending',
  csat_rating             TINYINT UNSIGNED NULL,
  csat_comment            VARCHAR(500) NULL,
  FOREIGN KEY (category_id)      REFERENCES categories(category_id) ON DELETE SET NULL,
  FOREIGN KEY (customer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (organization_id)  REFERENCES organizations(organization_id) ON DELETE SET NULL,
  INDEX idx_tk_org            (organization_id, status),
  INDEX idx_tk_status_updated (status, updated_at),
  INDEX idx_tk_assigned       (assigned_to, status),
  INDEX idx_tk_customer_email (customer_email),
  INDEX idx_tk_customer_user  (customer_user_id),
  INDEX idx_tk_created        (created_at),
  INDEX idx_tk_sla            (sla_response_status, sla_resolution_status, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NOTE: assigned_to is deliberately NOT a FK to users(email). Agents get deleted; their
-- historical tickets must keep the email for the audit trail (GAS behaviour: shows "(inactive)").

-- ── messages ───────────────────────────────────────────────
CREATE TABLE messages (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id  VARCHAR(16) UNIQUE NOT NULL,
  ticket_id   VARCHAR(20) NOT NULL,
  from_type   ENUM('customer','agent','note','system') NOT NULL,
  from_name   VARCHAR(120) NOT NULL DEFAULT '',
  from_email  VARCHAR(254) NOT NULL DEFAULT '',
  text        TEXT NOT NULL,
  is_internal TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE,
  INDEX idx_msg_ticket (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── attachments ────────────────────────────────────────────
CREATE TABLE attachments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id     VARCHAR(20) NOT NULL,
  message_id    VARCHAR(16) NULL,
  original_name VARCHAR(255) NOT NULL,                     -- DISPLAY ONLY. Never a filesystem path.
  stored_name   CHAR(32) NOT NULL,                         -- random_bytes(16) hex
  mime_type     VARCHAR(120) NOT NULL,                     -- from finfo, NEVER from $_FILES['type']
  size_bytes    INT UNSIGNED NOT NULL,
  sha256        CHAR(64) NOT NULL,                         -- integrity + dedupe
  is_internal   TINYINT(1) NOT NULL DEFAULT 0,             -- attached to an internal note -> hidden from customers
  uploaded_by   VARCHAR(254) NOT NULL,
  uploaded_at   DATETIME NOT NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE,
  INDEX idx_att_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── canned_responses ───────────────────────────────────────
CREATE TABLE canned_responses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  response_id VARCHAR(16) UNIQUE NOT NULL,
  title       VARCHAR(120) NOT NULL,
  body        TEXT NOT NULL,
  category    VARCHAR(60) NOT NULL DEFAULT '',
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_by  VARCHAR(254) NOT NULL,
  created_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── config ─────────────────────────────────────────────────
CREATE TABLE config (
  `key`  VARCHAR(60) PRIMARY KEY,
  value  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── audit_log (hash-chained; excluded from ticket reset) ────
CREATE TABLE audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor      VARCHAR(254) NOT NULL,
  action     VARCHAR(60) NOT NULL,
  target     VARCHAR(60) NOT NULL DEFAULT '',
  details    VARCHAR(500) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  prev_hash  CHAR(64) NOT NULL DEFAULT '',                 -- §10.11 tamper evidence
  row_hash   CHAR(64) NOT NULL DEFAULT '',
  INDEX idx_audit_created (created_at),
  INDEX idx_audit_actor   (actor),
  INDEX idx_audit_action  (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── knowledge_base ─────────────────────────────────────────
CREATE TABLE knowledge_base (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  article_id VARCHAR(16) UNIQUE NOT NULL,
  title      VARCHAR(150) NOT NULL,
  category   VARCHAR(60) NOT NULL DEFAULT '',
  body       TEXT NOT NULL,
  visibility ENUM('public','internal') NOT NULL DEFAULT 'internal',
  author     VARCHAR(254) NOT NULL,
  view_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FULLTEXT KEY ft_kb (title, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── routing_rules ──────────────────────────────────────────
CREATE TABLE routing_rules (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rule_id    VARCHAR(16) UNIQUE NOT NULL,
  name       VARCHAR(100) NOT NULL,
  enabled    TINYINT(1) NOT NULL DEFAULT 1,
  conditions JSON NOT NULL,                                -- [{field,operator,value}] — validated on write
  actions    JSON NOT NULL,                                -- [{type,value}] — validated on write
  sort_order INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_rules_order (enabled, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── notifications ──────────────────────────────────────────
CREATE TABLE notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notif_id    VARCHAR(16) UNIQUE NOT NULL,
  agent_email VARCHAR(254) NOT NULL,
  type        VARCHAR(40) NOT NULL,
  message     VARCHAR(300) NOT NULL,
  ticket_id   VARCHAR(20) NULL,
  is_read     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  INDEX idx_notif_agent (agent_email, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── sessions (token stored HASHED — §10.4) ─────────────────
CREATE TABLE sessions (
  token_hash   CHAR(64) PRIMARY KEY,                       -- sha256 of the raw cookie value
  user_id      INT UNSIGNED NOT NULL,
  email        VARCHAR(254) NOT NULL,
  role         ENUM('admin','org_admin','agent','customer') NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',
  mfa_verified TINYINT(1) NOT NULL DEFAULT 0,              -- admin sessions unusable until 1
  created_at   DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sess_email  (email, created_at),
  INDEX idx_sess_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── rate_limits ────────────────────────────────────────────
CREATE TABLE rate_limits (
  bucket       VARCHAR(30) NOT NULL,                       -- SUBMIT|SUBMITGLOBAL|STATUS|PWRESET|LOGINFAIL|LOGINLOCK|TOTPFAIL
  bucket_key   VARCHAR(254) NOT NULL,                      -- email, IP, or 'all'
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  PRIMARY KEY (bucket, bucket_key),
  INDEX idx_rl_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── password_resets (token stored HASHED) ──────────────────
CREATE TABLE password_resets (
  token_hash CHAR(64) PRIMARY KEY,
  email      VARCHAR(254) NOT NULL,
  name       VARCHAR(120) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  INDEX idx_pwr_email  (email),
  INDEX idx_pwr_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── mail_log (deliverability + abuse forensics) ────────────
CREATE TABLE mail_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient  VARCHAR(254) NOT NULL,
  subject    VARCHAR(255) NOT NULL,
  status     ENUM('sent','suppressed','failed') NOT NULL,
  error      VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_mail_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
