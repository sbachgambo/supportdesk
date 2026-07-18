# P3A Helpdesk — Build Brief (v2, security-hardened)

**Status:** Authoritative build specification. Supersedes `SUPPORTDESK_BUILD_BRIEF.md` v1.
**Companion document:** the P3A Development Process Flow (weeks, owners, sign-offs). That document governs *project management*; this one governs *what gets built*. Where they conflict on technical matters, **this document wins** — §2 lists every conflict and its resolution.

> **Read this document in full before writing any code.** Every design decision here was either proven in a working Google Apps Script prototype (11 phases, comprehensive self-tests, live-tested) or is a deliberate hardening decision recorded in §2 with rationale. Do not improvise around it. If a section is ambiguous, contradictory, or wrong — **say so and stop**. Silently papering over ambiguity is how the prototype's predecessor accumulated the bugs that forced a full rebuild.

---

## 1. Project identity

| Item | Value |
| :--- | :--- |
| System name | **P3A** |
| Production domain | `p3a-support.com.ng` (addon domain on Go54 Supreme shared hosting) |
| Stack | PHP **8.1+**, MySQL (InnoDB, utf8mb4), Apache with `.htaccess` |
| Origin | Port of the SupportDesk GAS prototype — feature parity is a hard requirement |
| Security target | **OWASP ASVS 4.0 Level 2**, OWASP Top 10 (2021) coverage, NIST SP 800-63B password guidance |
| Display branding | Config-driven (`COMPANY_NAME` key). The codebase is `P3A`; the UI shows whatever config says. |

---

## 2. Decisions register

These were open questions. Each is now **decided**, with rationale. If you disagree with a decision, raise it before Phase 1 — changing them later is expensive.

### D1 — PHP version: **8.1 minimum, 8.2+ preferred**
The P3A process doc says PHP 7.4. **Overruled.** PHP 7.4 reached end-of-life in November 2022 and receives no security patches. Shipping a new security-sensitive application on an unmaintained runtime is indefensible and would fail any competent security review on its own. Enable 8.2 via cPanel → MultiPHP Manager. If Go54 cannot provide ≥8.1, **escalate before building** — do not proceed on 7.4.

### D2 — Customer accounts: **yes, three roles — and the anonymous path stays**
The brief states users are "customers, agents, and admins," admin-created, no public registration. The GAS prototype had **no customer logins at all** (customers were anonymous, identified by ticket ID + email). Both are now supported:

- `users.role` is `admin | agent | customer`.
- **Customers with accounts** log in, see only their own tickets (ownership-checked on every read), and have zero admin surface.
- **Anonymous customers** keep the GAS ticket-ID + email status lookup. It is not removed. The public submit form must work for people with no account — that requirement is locked.
- Customer accounts are created by admins or imported. No self-registration endpoint exists anywhere in the codebase.

**Architectural consequence (important):** internal notes must now be filtered on **two** paths — the authenticated customer view and the anonymous lookup. Duplicate filtering logic is exactly how internal notes leak. There is to be **one** function, `MessageVisibility::for(string $viewerRole, string $ticketId): array`, used by every path that returns messages. No ad-hoc `if ($msg->is_internal) continue;` anywhere else. This is tested once and trusted everywhere.

### D3 — Email ingestion: **IMAP polling against the P3A mailbox**
The P3A doc specifies Gmail API + OAuth. **Overruled**, for three reasons:

1. **It reintroduces the dependency we are migrating away from.** A stated goal of leaving GAS was that "the owner Google account is a single point of compromise." Gmail API + OAuth refresh token puts a Google account back in the trust chain, plus a Google Cloud project to maintain and a refresh token to protect.
2. **OAuth refresh tokens for apps left in "Testing" publishing status expire every 7 days.** This is a well-known operational landmine that silently breaks ingestion a week after launch. Avoiding it requires publishing the app through Google's verification process.
3. `support@p3a-support.com.ng` will exist as a Go54 mailbox anyway. IMAP against your own mailbox uses credentials you control, has no third-party outage surface, and needs no OAuth dance.

Gmail API remains available as a feature-flagged alternative (`INBOUND_MAIL_MODE=imap|gmail_api|webhook`) if the support inbox must stay on Gmail. **Build `imap` only.** Do not build the others speculatively.

### D4 — Data migration: **build the lazy-hash path; support both fresh and imported**
`bin/import_gas.php` reads CSV exports of the GAS sheets. Imported users carry `v2$salt$hash` values from the prototype's hashing scheme; these verify via the legacy path and are **transparently rewritten with `password_hash()` on the user's next successful login**. No forced password reset, no user-visible migration. Fresh installs simply never exercise the path. It is ~20 lines and it is the difference between a smooth cutover and a support incident.

### D5 — CSP vs inline event handlers: **refactor to event delegation**
*This is a contradiction in brief v1 that I am correcting.* The GAS frontend is built on inline handlers (`onclick="toggleTheme()"`, `onchange="modalStatusChange(this)"` — several hundred of them). A strict `script-src 'self'` CSP **forbids inline handlers**, and CSP nonces do not apply to attribute handlers. v1 demanded both a strict CSP and a "don't modernize the JS" 90%-preserved port. Those are mutually exclusive.

Resolution, in preference order:
- ❌ `script-src 'unsafe-inline'` — discards most of CSP's XSS value. Rejected.
- ❌ `'unsafe-hashes'` + a SHA-256 per handler — unmaintainable at this scale. Rejected.
- ✅ **Refactor all inline handlers to delegated `addEventListener` bindings** using `data-action` attributes and a single delegated listener per view. Real work — budget it in Phase 4 — but it is the only correct answer and it makes the CSP genuinely protective.

`style-src` is a different matter: the ported markup uses inline `style="..."` attributes extensively, which require `style-src 'unsafe-inline'` (nonces don't cover attributes either). CSS injection is a real but far narrower threat than script injection. **Accepted trade-off**, recorded here deliberately: `style-src 'self' 'unsafe-inline'`, `script-src 'self'`.

### D6 — CSRF on public forms: **stateless HMAC tokens**
The embeddable widget iframes `/submit` into third-party sites. With `SameSite=Lax` cookies (D7), **no cookie is sent in a cross-site iframe** — so a session-bound CSRF token cannot work there. Also, third-party cookie phase-out and storage partitioning make cookie-based approaches in embedded contexts increasingly fragile.

Resolution: **two CSRF mechanisms.**
- **Authenticated forms** → session-bound token, rotated on login and privilege change.
- **Public forms** (submit, status lookup, password reset request, widget) → **stateless signed token**: `base64(expiry . '|' . hash_hmac('sha256', $purpose . '|' . $expiry, APP_KEY))`. Validated by recomputing the HMAC with `hash_equals()` and checking expiry (10 minutes). No cookie or session required; works identically inside a third-party iframe.

### D7 — Session cookie: **`SameSite=Lax`**
`Strict` is marginally stronger but breaks a real flow: assignment-notification emails link agents to the dashboard, and under `Strict` the first click arrives cookieless and appears logged out. `Lax` still withholds cookies on cross-site POST, iframe, and XHR — which is the CSRF-relevant surface. Combined with mandatory CSRF tokens on every state-changing request, `Lax` is the correct engineering choice and the framework-standard default.

### D8 — Admin MFA: **required (TOTP)** — *addition beyond GAS parity*
OWASP ASVS Level 2 requires multi-factor authentication for administrative access. The GAS prototype had none. Given the explicit instruction to apply best practice with attention to security hardening, TOTP (RFC 6238) is included:
- **Required** for `admin` role; **optional** (self-enrolled) for `agent`; **unavailable** for `customer`.
- Secret encrypted at rest with `APP_KEY`; 10 single-use backup codes stored hashed.
- TOTP verification attempts rate-limited identically to password attempts.
- ±1 time-step tolerance (30s window); used codes rejected for replay within the window.

This is the single highest-value security addition available to this system. It is marked clearly as scope beyond parity — if you want it dropped, say so now, not in Week 11.

### D9 — Priority tiers: **four (urgent / high / normal / low)**
The P3A doc says Low/Medium/High. **Overruled** — the entire SLA configuration (eight config keys: response + resolution minutes × four tiers) is keyed to four tiers, as are the reports, routing-rule actions, and the customer-facing priority ceiling. Three tiers is a silent data-model regression that breaks SLA config, seeded data, and the GAS import path.

### D10 — Security is continuous, not Week 10
The P3A plan schedules security hardening in Week 10, after eight weeks of building, and describes it as *"Review ALL queries → PDO prepared statements"* — conceding they weren't prepared statements when written. **Overruled.** §10 of this document is binding from the first line of code in Phase 1. Every query is prepared, every output escaped, every mutation CSRF-checked, from the start.

**Week 10 is repurposed:** from *"add security"* to *"verify security, add MFA, and attempt to break it."* Verification and adversarial testing, not retrofitting.

### Adopted from the P3A process doc (better than brief v1)
- **Database backup cron** — v1 had no DB backup job. Genuine gap. Now §14, with hardening the P3A version lacked (see the warning there).
- **Landing page** — the GAS prototype had none; a public domain needs one.
- **Monitoring with email alerting** on errors — v1 specified logging only.
- **Per-task owners, weekly cadence, UAT sign-off, and the test-case table** — better governance than v1 had. The test-case table is absorbed into §17.

---

## 3. Feature inventory (parity contract)

Everything below exists in the GAS prototype and **must exist in P3A**. The P3A process doc omits the items marked ⚠ — they are not optional.

**Auth & users** — login only (no registration); admin/agent/customer roles; admin-created users; brute-force lockout (5 fails / 15 min / email, applied identically to unknown emails); concurrent session cap (5/user, oldest evicted); session termination on deactivation, password reset, and password change (own session survives a self-change); lazy hash migration; self-service password reset (60-min single-use token); admin password reset for any user; ⚠ admin TOTP MFA (D8, new).

**Tickets** — create (agent + public + email channels); four priorities; two-level categories; tags; status open/pending/resolved/closed; assignment with auto-assign (least-busy active agent); reply (customer-visible) and internal note (never customer-visible); ⚠ canned responses with `{customerName}` / `{agentName}` / `{ticketId}` substitution; SLA response + resolution deadlines calculated from config at create time and **recalculated from original creation time on priority change**, with already-passed milestones re-graded; first-response stamping; resolve/reopen semantics (reopen clears `resolved_at` and resets resolution SLA to pending); CSAT rating + comment display; ⚠ attachments (new — not in GAS).

**Admin** — agents CRUD with lockout guards (cannot deactivate/delete yourself; cannot deactivate/delete the last active admin); SLA targets (4 tiers × response/resolution minutes, validated positive and resolution ≥ response); system config allowlist (company name, support email, portal title/tagline, brand color, ticket prefix, business hours); ⚠ two-level category CRUD with referential integrity; ⚠ routing rules engine; ⚠ backup + ticket-data reset with typed confirmation.

**Public surfaces** — submit form (honeypot, rate limit 5/hr/email + 30/hr global, priority ceiling: customers may not select `urgent`, input length caps, category validated against DB); status lookup (ticket ID **and** matching email both required; wrong-email and unknown-ID return *byte-identical* generic errors; internal notes and system messages never exposed; rate limit 20/hr/email); password reset page; ⚠ embeddable widget; ⚠ landing page (new).

**Reports** — 4 KPI cards (created, resolved, avg resolution hours, SLA compliance %); 7/30/90-day switcher; volume-by-day line (empty days filled with zeros); status/priority/channel distributions; agent performance table; CSV export (RFC 4180 quoting).

**Automation** — inbound email → ticket or threaded reply; SLA breach monitor (idempotent — a second run must be a no-op); daily digest at **08:00** (not midnight — a digest nobody reads at midnight is theatre) to active admins; ⚠ DB backup; ⚠ cleanup.

**UX** — ⚠ dark/light mode (dashboard: system-preference default + toggle; public pages: light default + toggle, shared persistence key); ⚠ pagination at 10 items on every list (tickets ×4 views, agents, rules, categories, KB, agent-performance) with independent page state per list and clamping when data shrinks; ⚠ in-app notifications (bell, unread badge, assignment / SLA breach / customer reply, ownership-scoped).

**Knowledge base** ⚠ — agent publish/edit, admin-only delete, search, category chips, view counts, public/internal visibility.

**Audit** — every security-relevant and lifecycle event; **survives ticket-data reset by design**; ⚠ hash-chained for tamper evidence (§10.11, new).

---

## 4. Threat model

Written so the build has an adversary in mind, and so a security reviewer knows what we considered.

**Assets, in order of value:** (1) the user credential store; (2) customer PII in tickets and messages, and attachment contents; (3) the audit log's integrity as a forensic record; (4) admin session tokens (full system control); (5) system availability; (6) the mail-sending capability (a compromised host that can send from your domain damages your sending reputation).

**Actors:** unauthenticated internet (largest surface — landing, submit, status, reset, widget); authenticated customer (may attempt IDOR into other customers' tickets, or privilege escalation); authenticated agent (legitimate access to all tickets; may attempt admin actions); authenticated admin (trusted — but must be MFA-protected because their credential is the crown jewel); malicious insider with admin (mitigated by tamper-evident audit + admin alerting, not prevented); the shared-hosting neighbourhood (other tenants on the same box — assume the filesystem is not private to you).

**Trust boundaries:** internet → Apache/PHP; PHP → MySQL (separate credential, least-privilege grant); PHP → filesystem (`storage/` writable, never executable, never web-reachable); PHP → SMTP/IMAP; inbound email → ingestion (**untrusted input**, treated as hostile).

**Primary attack scenarios and their controls:**

| Scenario | Control |
| :--- | :--- |
| Credential stuffing on `/login` | Per-email lockout, generic errors, constant-time response, admin MFA, breached-password denylist |
| SQL injection via any input | PDO prepared statements exclusively, `ATTR_EMULATE_PREPARES=false` |
| Stored XSS via ticket subject/body/message | Output escaping by default, strict CSP without `script-src 'unsafe-inline'` |
| RCE via attachment upload | Storage outside webroot, MIME sniffing, extension allowlist, random names, no PHP execution possible even if `.htaccess` fails |
| IDOR — customer reads another's ticket | Ownership check on every read path; single `MessageVisibility` gate |
| Ticket-thread injection via forged inbound email | **Sender must match the ticket's `customer_email`**; mismatch creates a new ticket instead of threading |
| Account enumeration | Byte-identical responses + normalized timing on login, status lookup, and reset request |
| Reset-token theft via Referer leakage | Token consumed on load, bound to session, URL rewritten tokenless; `Referrer-Policy: no-referrer` on that route |
| Session hijack via DB read | Session tokens **stored hashed**; a leaked DB grants no sessions |
| CSRF on any state change | Session-bound tokens (authed) + stateless HMAC tokens (public), `SameSite=Lax` |
| Clickjacking | `frame-ancestors 'self'` everywhere except the widget route |
| Insider erasing tracks via "cleanup" | Audit log excluded from reset; hash-chained; reset alerts all admins |
| Config/credential disclosure if PHP stops executing | Everything except `public/` lives **outside the webroot** — `.htaccess` is the second line, not the first |
| Mail relay abuse | Recipient validation, header-injection rejection, seed-domain suppression, send volume logged |

**Accepted risks (documented, not solved):** shared hosting means no host-level isolation and no ClamAV — compensating controls are the upload restrictions in §10.7. Local backup encryption keys live on the same host as the backups; the protection is against backup *exfiltration*, not host compromise. Rate limiting fails open by design (§10.6) — availability is preferred over lockout when the limiter itself is broken.

---

## 5. Hosting & runtime

- **Host:** Go54 Supreme (120GB shared), addon domain `p3a-support.com.ng`.
- **PHP:** 8.1 minimum, 8.2+ preferred (cPanel → MultiPHP Manager). See D1.
- **Required extensions:** `pdo_mysql`, `mbstring`, `openssl`, `imap`, `fileinfo`, `curl`, `json`, `gd` (image re-encoding), `zlib`.
- **Composer:** used locally; `vendor/` is **committed** (shared hosting rarely permits shell composer). Dependencies stay minimal: `phpmailer/phpmailer` only. Every added dependency is attack surface — justify any addition in `DEV_NOTES.md`.
- **Cron:** cPanel Cron Jobs, 5-minute minimum granularity.
- **MySQL user:** application-specific, granted only `SELECT, INSERT, UPDATE, DELETE` on the P3A schema. **Not** `ALL PRIVILEGES`. Schema changes run under a separate migration credential, used manually. (`DROP`/`ALTER` in the app's own grant means one SQLi bug becomes total data loss.)

---

## 6. Folder structure

```
p3a/
├── public/                        ← THE ONLY web-accessible directory. Addon domain DocumentRoot → here.
│   ├── index.php                    Front controller. Every request routes through this.
│   ├── .htaccess                    Rewrite to index.php; static asset caching
│   ├── widget.js.php                Widget loader (serves JS; own header profile)
│   ├── assets/{css,js,img}/
│   ├── assets/vendor/chart.min.js   Chart.js pinned + self-hosted. NO CDN in production.
│   └── favicon.ico
│
├── app/                           ← Application code. NEVER web-accessible.
│   ├── bootstrap.php
│   ├── Core/                        Config, Db, Router, Dispatch, Request, Response, View, Csrf,
│   │                                Session, SecurityHeaders, ErrorHandler, Logger
│   ├── Security/                    Auth, Rbac, RateLimit, Totp, PasswordPolicy, MessageVisibility,
│   │                                Upload, AuditChain
│   ├── Controllers/
│   ├── Services/                    TicketService, RoutingRules, SlaCalculator, KbService,
│   │                                NotificationService, ReportService, BackupService
│   ├── Models/                      Thin repositories over Db
│   ├── Views/                       layouts/, partials/, pages/
│   ├── Cron/                        ingest_email.php, sla_monitor.php, daily_digest.php,
│   │                                backup_db.php, cleanup.php
│   └── helpers.php
│
├── storage/                       ← Writable at runtime. NEVER web-accessible. NEVER executable.
│   ├── uploads/                     Random 32-hex names. Original names live only in the DB.
│   ├── backups/                     Encrypted DB dumps. chmod 600.
│   ├── logs/                        error.log, audit.log, ingest.log, mail.log, security.log
│   └── cache/
│
├── database/
│   ├── schema.sql
│   ├── seed.sql
│   └── migrations/                  Numbered, forward-only
│
├── tests/
│   ├── run_all.php
│   ├── StaticSuite.php              Runs without a DB — see §17.2
│   ├── SecuritySuite.php            The adversarial cases — see §17.3
│   └── phases/PhaseNTest.php
│
├── bin/
│   ├── check_env.php                Diagnoses hosting readiness, red/green
│   ├── generate_key.php             APP_KEY
│   ├── install.php                  schema + seed
│   ├── import_gas.php               CSV import from the GAS sheets (D4)
│   └── restore_backup.php           Decrypt + restore. Tested, not theoretical.
│
├── .env                           ← NOT committed. chmod 600.
├── .env.example
├── .htaccess                      ← Top-level: Deny from all (belt-and-braces; see §10.13)
├── .gitignore
├── composer.json
├── README.md                      Install + deploy runbook
├── SECURITY.md                    Control inventory + ASVS mapping + accepted risks
├── P3A_BUILD_BRIEF.md             ← this document
└── DEV_NOTES.md                   Running decision log
```

**Non-negotiable:** the addon domain's DocumentRoot points at `p3a/public/`, **not** at `p3a/`. If you ever find yourself putting a `.php` file with logic in it anywhere under `public/` other than `index.php` and `widget.js.php`, stop — the design is being violated.

*Why this matters, concretely:* the P3A process doc places `config/`, `includes/`, and `uploads/` inside `public_html/`, protected by `<Directory>` blocks in `.htaccess`. Two failures there: (1) `<Directory>` **is not a valid `.htaccess` directive** — it only works in main server config, and in `.htaccess` it produces an HTTP 500, meaning the control either breaks the site or was never active; (2) even written correctly as `<Files>`/`Require all denied`, it is a *single* control — if PHP execution ever stops (a mis-clicked MultiPHP version change, a handler misconfiguration), `config/database.php` is served as plaintext with your DB credentials, and anything uploaded to `uploads/` becomes executable. Placement outside the webroot means there is no URL that maps to those files at all. `.htaccess` then becomes the *second* line of defence, which is what it should be.

---

## 7. Database schema

`utf8mb4` / `utf8mb4_unicode_ci`, InnoDB throughout. **All datetimes stored UTC**; rendered in `APP_TIMEZONE`. 15 tables.

```sql
-- ── users ──────────────────────────────────────────────────
CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id      VARCHAR(16) UNIQUE NOT NULL,              -- "AG-0001" / "CU-0001" (GAS-compatible)
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(254) UNIQUE NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,                    -- password_hash(); accepts legacy "v2$salt$hash"
  role           ENUM('admin','agent','customer') NOT NULL DEFAULT 'customer',
  active         TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret    VARBINARY(255) NULL,                      -- encrypted with APP_KEY; NULL = not enrolled
  totp_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  must_change_pw TINYINT(1) NOT NULL DEFAULT 0,            -- set on admin-set passwords
  last_login_at  DATETIME NULL,
  created_at     DATETIME NOT NULL,
  INDEX idx_users_email_active (email, active),
  INDEX idx_users_role_active (role, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── totp_backup_codes ──────────────────────────────────────
CREATE TABLE totp_backup_codes (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id   INT UNSIGNED NOT NULL,
  code_hash VARCHAR(255) NOT NULL,                         -- password_hash of the code
  used_at   DATETIME NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_backup_user (user_id, used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── tickets ────────────────────────────────────────────────
CREATE TABLE tickets (
  id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id               VARCHAR(20) UNIQUE NOT NULL,     -- "TKT-2026-0001"
  subject                 VARCHAR(200) NOT NULL,
  description             TEXT NOT NULL,
  customer_name           VARCHAR(120) NOT NULL DEFAULT '',
  customer_email          VARCHAR(254) NOT NULL,
  customer_user_id        INT UNSIGNED NULL,               -- set when the customer has an account (D2)
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
  INDEX idx_tk_status_updated (status, updated_at),
  INDEX idx_tk_assigned       (assigned_to, status),
  INDEX idx_tk_customer_email (customer_email),
  INDEX idx_tk_customer_user  (customer_user_id),
  INDEX idx_tk_created        (created_at),
  INDEX idx_tk_sla            (sla_response_status, sla_resolution_status, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  is_internal   TINYINT(1) NOT NULL DEFAULT 0,             -- attached to an internal note → hidden from customers
  uploaded_by   VARCHAR(254) NOT NULL,
  uploaded_at   DATETIME NOT NULL,
  FOREIGN KEY (ticket_id) REFERENCES tickets(ticket_id) ON DELETE CASCADE,
  INDEX idx_att_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── config ─────────────────────────────────────────────────
CREATE TABLE config (
  `key`  VARCHAR(60) PRIMARY KEY,
  value  TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── sessions (token stored HASHED — §10.4) ─────────────────
CREATE TABLE sessions (
  token_hash   CHAR(64) PRIMARY KEY,                       -- sha256 of the raw cookie value
  user_id      INT UNSIGNED NOT NULL,
  email        VARCHAR(254) NOT NULL,
  role         ENUM('admin','agent','customer') NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',
  mfa_verified TINYINT(1) NOT NULL DEFAULT 0,              -- admin sessions unusable until 1
  created_at   DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sess_email  (email, created_at),
  INDEX idx_sess_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── rate_limits ────────────────────────────────────────────
CREATE TABLE rate_limits (
  bucket       VARCHAR(30) NOT NULL,                       -- SUBMIT|SUBMITGLOBAL|STATUS|PWRESET|LOGINFAIL|LOGINLOCK|TOTPFAIL
  bucket_key   VARCHAR(254) NOT NULL,                      -- email, IP, or 'all'
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  expires_at   DATETIME NOT NULL,
  PRIMARY KEY (bucket, bucket_key),
  INDEX idx_rl_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── password_resets (token stored HASHED) ──────────────────
CREATE TABLE password_resets (
  token_hash CHAR(64) PRIMARY KEY,
  email      VARCHAR(254) NOT NULL,
  name       VARCHAR(120) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  INDEX idx_pwr_email  (email),
  INDEX idx_pwr_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── mail_log (deliverability + abuse forensics) ────────────
CREATE TABLE mail_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  recipient  VARCHAR(254) NOT NULL,
  subject    VARCHAR(255) NOT NULL,
  status     ENUM('sent','suppressed','failed') NOT NULL,
  error      VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_mail_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Mutual exclusion:** GAS used `LockService`. PHP uses MySQL `SELECT GET_LOCK('p3a_ingest', 0)` for cron mutual exclusion (connection-scoped, auto-released on disconnect — no stale lock files to clean up after a crash).

---

## 8. Configuration & secrets

`.env` at project root — **outside the webroot**, `chmod 600`, never committed, never logged.

```
APP_ENV=production
APP_URL=https://p3a-support.com.ng          # MUST be https:// — validated at bootstrap
APP_KEY=                                     # bin/generate_key.php — 32 random bytes, base64
APP_TIMEZONE=Africa/Lagos

DB_HOST=localhost
DB_NAME=cpuser_p3a
DB_USER=cpuser_p3aapp                        # least-privilege: SELECT/INSERT/UPDATE/DELETE only
DB_PASS=
DB_CHARSET=utf8mb4

MAIL_HOST=mail.p3a-support.com.ng
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=support@p3a-support.com.ng
MAIL_PASS=
MAIL_FROM=support@p3a-support.com.ng
MAIL_FROM_NAME="P3A Support"

INBOUND_MAIL_MODE=imap                       # imap | gmail_api | webhook  (build imap only — D3)
IMAP_HOST=mail.p3a-support.com.ng
IMAP_PORT=993
IMAP_ENCRYPTION=ssl
IMAP_USER=support@p3a-support.com.ng
IMAP_PASS=
IMAP_MAX_PER_RUN=20

SESSION_LIFETIME_HOURS=12
MAX_SESSIONS_PER_USER=5
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCK_MINS=15
PASSWORD_MIN_LENGTH=12
PASSWORD_BREACH_CHECK=local                  # local | hibp | off   (§10.3)

RATE_SUBMIT_PER_HOUR=5
RATE_SUBMIT_GLOBAL_PER_HOUR=30
RATE_STATUS_PER_HOUR=20
RATE_PWRESET_PER_HOUR=3
RATE_IP_MULTIPLIER=3                         # per-IP allowance = per-email limit × this

UPLOAD_MAX_BYTES=10485760
UPLOAD_MAX_PER_TICKET=20

BACKUP_RETAIN_DAYS=14
BACKUP_ENCRYPT=1

SUPPRESS_EMAIL_DOMAINS=example.com,test.com  # seed/demo addresses never receive real mail
ALERT_EMAIL=itadmin@p3a-support.com.ng       # error + security alerting
```

`Core\Config` loads once at bootstrap and exposes typed accessors (`Config::string()`, `::int()`, `::bool()`). **Missing required keys throw at bootstrap** — fail loudly and immediately rather than at 2am in a cron job. `APP_URL` not starting with `https://` is a fatal bootstrap error.

**APP_KEY rotation:** documented in `SECURITY.md`. Rotating it invalidates all stateless CSRF tokens (harmless — 10-minute lifetime) and breaks TOTP secret decryption unless re-encrypted, so the rotation script must re-encrypt `users.totp_secret` in the same transaction.

---

## 9. Dispatch & routing

Keep the GAS single-gateway pattern. It made security review tractable: one place to audit, one place where auth/CSRF/rate-limit cannot be forgotten.

```
GET  /                     → landing page (public)
GET  /login                → login form (public)
POST /login                → authenticate (public, rate-limited, CSRF)
GET  /mfa                  → TOTP challenge (session exists, mfa_verified = 0)
POST /mfa                  → verify TOTP (rate-limited, CSRF)
POST /logout               → destroy session (CSRF)
GET  /dashboard            → agent/admin dashboard | customer portal (role-branched)
POST /api                  → THE ACTION GATEWAY — Dispatch::handle()
GET  /submit               → public submit form
GET  /status               → public status lookup
GET  /reset                → password reset (token consumed on load — §10.9)
GET  /download/{id}        → attachment stream, access-checked
GET  /widget.js            → widget loader (public; its own header profile)
```

`Dispatch::handle(string $action, array $payload): array` executes in this fixed order — **no handler may run before all five gates pass**:

1. **Action allowlist** — unknown action → generic error, logged. Never dynamic dispatch to a method name derived from user input.
2. **Rate limit** — bucket by action class + email/IP (§10.6).
3. **CSRF** — stateless HMAC for `PUBLIC_ACTIONS`; session token otherwise (D6).
4. **Authentication** — if not in `PUBLIC_ACTIONS`, a valid session is required; if the session's role is `admin`, `mfa_verified` must be 1.
5. **Authorization** — the handler's declared requirement (`agent+`, `admin`, `owner`) is enforced *by the gateway*, from a static map, not by the handler remembering to check.

```php
private const PUBLIC_ACTIONS = [
    'getPortalData', 'submitTicket', 'checkTicketStatus',
    'requestPasswordReset', 'completePasswordReset',
];
private const REQUIRES = [           // authorization is data, not discipline
    'sendReply'          => 'agent',
    'addInternalNote'    => 'agent',
    'deleteAgent'        => 'admin',
    'resetTicketData'    => 'admin',
    'getMyTickets'       => 'customer',
    'openTicket'         => 'owner',  // agent/admin: any; customer: own only
    // ...
];
```

Every handler returns an array; the gateway JSON-encodes it. A global try/catch logs the exception with a request ID and returns a generic error — **never** the exception message.

**Cron scripts** start with `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`. They are unreachable over HTTP even if someone places them in the webroot by accident.

---

## 10. Security controls

**Binding from Phase 1, line 1.** Each control states the rule, why it exists, and how it is verified. The verification column is what §17 tests assert.

### 10.1 SQL injection — OWASP A03
- **Every** query is a PDO prepared statement with bound parameters. No string concatenation into SQL. Ever. Not for "known safe" values, not for table names, not for `ORDER BY`.
- PDO options are mandatory and set in one place:
  ```php
  PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES   => false,   // ← critical: emulation reintroduces injection edge cases
  PDO::ATTR_STRINGIFY_FETCHES  => false,
  ```
  DSN carries `charset=utf8mb4`.
- With emulation off, `LIMIT ?` requires `bindValue($i, $v, PDO::PARAM_INT)`. Pagination must do this; a string-bound LIMIT throws.
- Dynamic sort/filter columns come from a **hardcoded allowlist map**, never from the request: `$col = self::SORTABLE[$req] ?? 'created_at';`
- `Core\Db` exposes `query()`, `queryOne()`, `queryAll()`, `insert()`, `update()`, `delete()`, `transaction(callable)`. No other class touches PDO. If a `Services/` or `Controllers/` file contains the string `PDO`, it's a review failure.
- **Verify:** static scan finds zero SQL-adjacent string interpolation; SecuritySuite fires classic payloads (`' OR 1=1--`, `'; DROP TABLE`, `1' UNION SELECT`) at login, search, status lookup, and pagination params.

### 10.2 XSS — OWASP A03
- All output goes through `e()`: `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8')`. `ENT_SUBSTITUTE` matters — invalid UTF-8 otherwise yields an empty string and can break escaping assumptions.
- Views escape by default. Raw output requires the `raw()` helper **and** an adjacent comment justifying it. There should be almost none.
- JSON responses: `json_encode($d, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE)`.
- Never interpolate PHP values into inline `<script>` blocks. Data reaches JS via `data-*` attributes (escaped) or a fetch call.
- Attachment filenames are escaped on display and never used as a path (§10.7).
- **The real defence is layered:** escaping + CSP without `script-src 'unsafe-inline'` (D5). Escaping alone has a bad historical record; CSP alone doesn't stop DOM-XSS. Both.
- **Verify:** SecuritySuite creates a ticket with subject `<script>alert(1)</script>"><img src=x onerror=alert(1)>` and asserts the rendered dashboard, ticket detail, status lookup, CSV export, and digest email all contain the escaped form and no live tag.

### 10.3 Authentication — OWASP A07, NIST SP 800-63B
- **Hashing:** `PASSWORD_ARGON2ID` when `defined('PASSWORD_ARGON2ID')` (memory 19 MiB, time 2, parallelism 1 — the OWASP minimum), else `PASSWORD_BCRYPT` cost 12. Never hand-roll. `password_needs_rehash()` on every successful login upgrades parameters over time.
- **bcrypt's 72-byte truncation is real:** cap password input at 72 bytes. NIST requires accepting ≥64 characters; 72 bytes satisfies that for any realistic passphrase. Reject longer with a clear message rather than silently truncating.
- **Legacy migration (D4):** a stored value starting `v2$` verifies via the prototype's salted-iterated-SHA256 path, then is immediately rewritten with `password_hash()`. Invisible to the user.
- **Password policy — NIST-aligned, not folklore:** minimum 12 characters; **no composition rules** (no forced uppercase/number/symbol — they measurably reduce entropy by pushing users to `Password1!`); **no periodic forced rotation** (only on evidence of compromise); allow all printable characters including spaces and Unicode; **allow paste** (password managers are a security feature); no password hints; no knowledge-based recovery questions.
- **Breached-password check:** `PASSWORD_BREACH_CHECK=local` uses a bundled top-10k common-password list. `hibp` uses the Have I Been Pwned **k-anonymity range API** — SHA-1 the candidate, send only the first 5 hex characters, compare suffixes locally. The password itself never leaves the server. Fail-open on network error.
- **Timing-attack resistance:** when the email doesn't exist, still execute a `password_verify()` against a fixed dummy hash before returning. Otherwise response time distinguishes "no such user" from "wrong password" and enumeration returns through the back door after we closed the front.
- **Enumeration:** wrong password and unknown email return **byte-identical** strings. Same for a locked account — the lockout message must not confirm the account exists. (GAS proved this by asserting `wrongPw.error === unknown.error` — port that assertion.)
- **Lockout:** 5 failures per email per 15 minutes → locked. Applies to unknown emails identically. During lockout, even a correct password is refused. Lock events audit-log and alert admins.
- **Layered lockout:** additionally rate-limit by IP (`LOGINFAIL` bucket keyed by IP at `RATE_IP_MULTIPLIER` × the per-email allowance). Apps Script exposed no client IP — PHP does. This closes distributed-email/single-source credential stuffing.
- **MFA (D8):** admins must complete TOTP before their session is usable. The session row exists but carries `mfa_verified = 0`; the gateway rejects every action except the MFA challenge until it flips. That's the correct shape — it means a stolen password alone yields a session that can do nothing.
- **Verify:** the lockout test, the enumeration-parity test, the timing test (unknown-email vs wrong-password response times within a tolerance band), the migration test (seed a `v2$` user, log in, assert the hash is rewritten and login still works), and the MFA gate test (session with `mfa_verified=0` cannot call any action).

### 10.4 Session management — OWASP A07
- Token = `bin2hex(random_bytes(32))`. Sent as a cookie; **stored in the DB as `hash('sha256', $token)`**. A leaked database therefore grants no sessions. (Improvement on the prototype, which stored raw tokens.)
- Cookie flags: `HttpOnly`, `Secure`, `SameSite=Lax` (D7), `Path=/`, no `Domain` attribute (host-only — don't share the cookie with sibling subdomains).
- `session_regenerate_id(true)` on login and on any privilege change — session fixation.
- 12-hour absolute lifetime; `last_seen_at` updated per request (throttled to once/60s to avoid a write per request).
- **Concurrent cap:** 5 per user; the oldest is deleted on the 6th login. Prevents indefinite accumulation of forgotten sessions.
- **Termination:** deactivation, admin password reset, self-service reset → **all** the user's sessions die. Self-service password change → all sessions **except the current one** die (so changing your password doesn't log you out of the tab you're using).
- Sessions are DB-backed rather than file-backed: on shared hosting the default session save path may be readable by other tenants.
- **Verify:** cookie flags asserted from response headers; a DB-read of `sessions` cannot be replayed as a cookie; the cap evicts oldest; each termination rule tested.

### 10.5 Access control — OWASP A01 (the #1 risk)
- **Default deny.** Every action's requirement lives in `Dispatch::REQUIRES` (§9). A handler with no entry is unreachable, not public.
- Three guards: `Rbac::requireAuth()`, `Rbac::requireRole('admin'|'agent')`, `Rbac::requireOwnership($ticketId)`.
- **Ownership** (`customer` role): a customer may read a ticket only where `customer_user_id = session.user_id` **or** `customer_email = session.email` (covers tickets raised anonymously before their account existed). Agents and admins bypass ownership by role.
- **The `MessageVisibility` gate (D2):** one function returns the visible message set for a viewer. Customers and anonymous lookups never receive `is_internal = 1` or `from_type = 'note'` or `from_type = 'system'`. **Nothing else filters messages.** Same for `attachments.is_internal`.
- UI hiding is UX, never security. Every action is re-authorized server-side regardless of what the frontend rendered.
- **Mass assignment:** never iterate `$_POST` into an update. Each endpoint declares an explicit field allowlist. A customer POSTing `role=admin` must hit a wall built out of "that field isn't in the allowlist," not out of luck.
- **Verify:** SecuritySuite logs in as customer A and attempts to read customer B's ticket by ID, download B's attachment, mark B's notification read, and call `deleteAgent` — all must fail closed. Plus: an agent calling every admin action; a `role=admin` field injected into profile update.

### 10.6 Rate limiting
- DB-backed sliding window in `rate_limits`. Buckets and defaults (all `.env`-tunable): `SUBMIT` 5/hr/email, `SUBMITGLOBAL` 30/hr, `STATUS` 20/hr/email, `PWRESET` 3/hr/email, `LOGINFAIL` 5/15min/email + IP variant, `TOTPFAIL` 5/15min/user.
- **Fail-open**, by deliberate design carried from the prototype: if the limiter's own query throws, the request proceeds. Rationale — a limiter outage must not become a self-inflicted denial of service. Note the login case is moot anyway: without a DB you cannot verify a password, so no login succeeds regardless.
- Per-email is the primary key (parity with GAS and with how email ingestion identifies people); per-IP is layered on top where an attacker can rotate emails freely (login, submit).
- Expired rows purged nightly by `cleanup.php`.
- **Verify:** the 6th submission in an hour is refused; the global cap refuses even a fresh email; a simulated DB error on the limiter lets the request through.

### 10.7 File uploads — the highest-severity surface
Every one of these is required. Uploads are the most common path from "website" to "attacker owns the server."
- **Stored outside the webroot** (`storage/uploads/`). There is no URL that resolves to an uploaded file. This alone defeats the entire class of `.php`-upload RCE, independent of `.htaccess`.
- **Random names:** `bin2hex(random_bytes(16))`, no extension on disk. `original_name` lives in the DB for display only and is **never** used to build a path — path traversal has nowhere to land.
- **Dual validation:** extension allowlist **AND** `finfo_file()` content sniffing **must agree**. `$_FILES['type']` is client-supplied and is ignored entirely.
- **Allowlist:** `png jpg jpeg gif webp pdf txt csv doc docx xls xlsx zip`. **SVG is excluded** — it is an XML document that can carry script. If SVG becomes a requirement, it must be sanitized server-side, not merely sniffed.
- **Images are re-encoded** through GD on upload. Strips EXIF, strips appended payloads (the polyglot JPEG/PHP trick), normalizes the file. If re-encoding fails, reject.
- **Caps:** 10 MB/file, 20 files/ticket, and `UPLOAD_MAX_BYTES` enforced in PHP *and* checked before move — plus a `php_value upload_max_filesize` guard in `.htaccess`. Reject zip files whose uncompressed size exceeds a threshold (zip-bomb).
- **Serving** via `/download/{id}` only: verify session → verify ownership/role → verify `attachments.is_internal` against viewer → stream with `Content-Disposition: attachment; filename="<sanitized>"`, `X-Content-Type-Options: nosniff`, and `Content-Type: application/octet-stream` for everything except a small map of known-safe image types. **Never echo the stored MIME back as the Content-Type** — that's how an uploaded `text/html` becomes stored XSS on your own origin.
- `sha256` recorded for integrity and duplicate detection.
- **No AV scanning is available on shared hosting.** Accepted risk, recorded in `SECURITY.md`; the controls above are the compensating set. Uploaded files are never executed and never served inline, so an infected file can only harm someone who downloads and opens it deliberately.
- **Verify:** upload `shell.php` renamed `photo.jpg` → rejected by MIME sniff. Upload a valid JPEG with PHP appended → re-encode strips it; the stored file contains no `<?php`. Request `storage/uploads/<name>` directly → 403/404. Request another customer's attachment ID → denied. Attempt `../../` in every filename field → no effect (name is never a path).

### 10.8 CSRF — OWASP A01
- Every POST/PUT/PATCH/DELETE requires a token. Comparison uses `hash_equals()`.
- **Authenticated:** per-session token, rotated on login and privilege change.
- **Public (D6):** stateless HMAC token, 10-minute expiry, bound to a form purpose, verified with `hash_equals()`. Works inside the widget's third-party iframe where no cookie is sent.
- The gateway enforces this (§9 step 3) — a handler cannot forget.
- **Verify:** every mutating action rejected without a token, with another session's token, and with an expired/tampered HMAC.

### 10.9 Password reset — hardened beyond the prototype
- Token = 32 random bytes hex; **stored hashed**; 60-minute expiry; **single use** (`used_at` stamped, then the row is dead).
- Request endpoint is enumeration-safe: identical response for known and unknown emails, and **no token row is created** for unknown emails. Rate-limited 3/hr/email.
- Tokens issue only for `active` users.
- **Referer-leak fix (new):** the prototype left the token in the URL for the life of the page. Now: `GET /reset?token=X` → validate → bind to a short-lived server-side reset session → **302 to `/reset`** with no token in the URL. The token never enters browser history and cannot leak via `Referer` to any third-party resource. That route additionally sends `Referrer-Policy: no-referrer`.
- Completing a reset kills **all** the user's sessions and emails the account owner ("your password was changed — wasn't you? contact an admin").
- **Verify:** token single-use; expired token rejected and purged; unknown email creates no row and returns the identical message; the final URL contains no token.

### 10.10 Error handling & logging — OWASP A09
- Production: `display_errors=Off`, `log_errors=On`, `error_reporting=E_ALL`. Set in `bootstrap.php` — never trusting `php.ini` on shared hosting.
- Custom exception + error + shutdown handlers. Users see a generic page with a **request ID**; the log holds the detail. The request ID is what turns a user's "it broke" into a two-second log lookup.
- Every log line: ISO-8601 UTC, request ID, user ID (if any), IP, method, path, event, detail.
- **Never logged:** passwords, session tokens, reset tokens, TOTP secrets/codes, `.env` values, full request bodies of auth endpoints. A log that contains credentials is a breach waiting for a log-reader.
- **Log injection:** user-supplied values are sanitized (`\r`/`\n` stripped) before logging. Otherwise an attacker forges log entries.
- `security.log` is separate: auth failures, lockouts, authz denials, upload rejections, CSRF failures. It should be short and boring; when it isn't, something is happening.
- **Alerting:** `ALERT_EMAIL` receives (a) any uncaught exception, throttled to one per unique signature per hour, and (b) security thresholds — ≥10 auth failures across the system in 5 minutes, any authz denial by an authenticated user (that's either a bug or an attack; both need eyes).
- **Verify:** a triggered exception shows no stack trace in the response, writes to `error.log`, and returns a request ID that appears in the log.

### 10.11 Audit log — integrity, not just presence
- Logged: login success/fail/lock, MFA success/fail, logout; user CRUD, role change, activation/deactivation, password reset (self + admin), password change; ticket create/reply/note/status/priority/assign/delete; category CRUD; rule CRUD + enable/disable; config + SLA change; KB publish/edit/delete; backup; ticket-data reset; every authz denial.
- **Survives ticket-data reset by design.** Non-negotiable and adversarial in intent: a malicious insider must not be able to erase their tracks under cover of "cleanup."
- **Hash chain (new):** `row_hash = sha256(prev_hash || actor || action || target || details || created_at)`. `bin/verify_audit.php` walks the chain and reports the first broken link. Row-level tampering or deletion becomes detectable rather than silent. This does not *prevent* tampering by someone with DB access — it makes it evident, which is the achievable goal.
- **Verify:** reset preserves the audit table and appends the reset event; `verify_audit.php` passes on a clean chain and pinpoints a manually edited row.

### 10.12 HTTP security headers — OWASP Secure Headers Project
Set **in PHP** (`Core\SecurityHeaders`) on every application response, so per-route overrides are possible. `.htaccess` sets them only for static asset directories.

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Frame-Options: SAMEORIGIN
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=()
Cross-Origin-Opener-Policy: same-origin
Content-Security-Policy:
  default-src 'self';
  script-src 'self';
  style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
  font-src 'self' https://fonts.gstatic.com;
  img-src 'self' data:;
  connect-src 'self';
  frame-ancestors 'self';
  form-action 'self';
  base-uri 'self';
  object-src 'none'
```

Route-specific overrides:
- **`/widget.js` and the widget's iframed `/submit`:** must be embeddable, so they send **no `X-Frame-Options`** (there is no "allow any" value — `ALLOW-FROM` is dead and `ALLOWALL` was never real) and use `frame-ancestors *` instead. This is precisely why headers are set in PHP: a blanket `.htaccess` `X-Frame-Options: DENY` — as the P3A process doc specifies — would silently kill the widget, which exists to be iframed.
- **`/reset`:** adds `Referrer-Policy: no-referrer` (§10.9).

**Deliberately omitted:** `X-XSS-Protection`. It is deprecated, removed from modern browsers, and its legacy filter introduced vulnerabilities of its own. The correct modern answer is CSP. (The P3A process doc specifies it; do not include it.)

**HSTS preload:** not initially. Preloading is effectively irreversible and should wait until HTTPS is proven stable for months.

- **Verify:** SecuritySuite asserts each header's presence and value on a normal route; asserts XFO absent and `frame-ancestors *` present on the widget route; asserts `script-src` contains no `unsafe-inline`.

### 10.13 HTTPS & transport
- `.htaccess` 301s HTTP → HTTPS before anything else runs.
- `APP_URL` must be `https://` or bootstrap fails.
- Cookies `Secure`.
- **Defence in depth:** if `bootstrap.php` sees a non-HTTPS request in production, it refuses to serve — protects against a hosting misconfiguration silently disabling the rewrite.
- SMTP uses TLS; IMAP uses SSL; certificate validation is **on** (never `verify_peer => false`, not even "temporarily" — temporary becomes permanent).

### 10.14 `.htaccess` — second line, not first
```apache
# ── p3a/.htaccess (project root, outside webroot) ──
# Belt-and-braces only. The real control is that this directory is not under DocumentRoot.
Require all denied
```
```apache
# ── p3a/public/.htaccess ──
Options -Indexes -MultiViews
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
<FilesMatch "^\.">
  Require all denied
</FilesMatch>
```
Note `Require all denied` (Apache 2.4), **not** `<Directory>` blocks — those are invalid in `.htaccess` and produce a 500. Also not `Deny from all` (2.2 syntax, deprecated). Verify which Apache version Go54 runs and confirm the directive takes effect rather than assuming.

### 10.15 Dependencies & supply chain — OWASP A06
- PHPMailer only. Every further dependency must be justified in `DEV_NOTES.md`.
- Versions pinned; `composer.lock` committed; `composer audit` before each release.
- Chart.js pinned and self-hosted — a CDN is a third party that can serve arbitrary script to your admins, and it forces a CSP hole.
- No Google Fonts is preferable (self-host); if kept, the CSP already scopes it narrowly.

### 10.16 Mail security
- Recipients validated with `filter_var($e, FILTER_VALIDATE_EMAIL)`; any address containing `\r` or `\n` is rejected outright (header injection).
- `SUPPRESS_EMAIL_DOMAINS` prevents seed/demo addresses (`*.example.com`) ever receiving real mail — the prototype's safety net, and it means the test suite can exercise the full mail path safely.
- All sends recorded in `mail_log`. A sudden spike is either a bug or an abuse of your host as a relay; you want to see it.
- SPF, DKIM, and DMARC configured for `p3a-support.com.ng` at go-live. Without them, every notification lands in spam and — more to the point — anyone can spoof your support address.

### 10.17 Inbound email — untrusted input
- Ingested mail is hostile until proven otherwise. Subject and body are truncated (200 / 5000), stored via prepared statements, escaped on output like any other user input.
- **The sender-match rule:** an inbound message whose subject contains a ticket ID threads onto that ticket **only if the sender address matches that ticket's `customer_email`.** Any mismatch creates a new ticket instead. Without this, anyone who can guess `TKT-2026-0001` can post into a stranger's ticket by putting it in a subject line. This is a security control, not a convenience.
- Skip our own address and `mailer-daemon@` / `no-reply@` / `postmaster@` senders (mail loops).
- HTML mail: extract the plain-text part; if absent, strip tags. Never store or render raw inbound HTML.
- `GET_LOCK('p3a_ingest', 0)` prevents overlapping cron runs from double-processing; failure to acquire exits cleanly (the next tick handles it).
- Messages are marked `\Seen` only after successful processing — a crash mid-run must not silently drop mail.

---

## 11. OWASP Top 10 (2021) coverage map

For `SECURITY.md` and for whoever reviews this.

| Risk | Controls |
| :--- | :--- |
| **A01 Broken Access Control** | §10.5 default-deny gateway map, ownership checks, `MessageVisibility` single gate, mass-assignment allowlists, §10.8 CSRF |
| **A02 Cryptographic Failures** | §10.3 Argon2id/bcrypt, §10.4 hashed session tokens, §10.9 hashed reset tokens, encrypted TOTP secrets, §10.13 TLS everywhere, encrypted backups (§14) |
| **A03 Injection** | §10.1 prepared statements + emulation off, §10.2 escape-by-default + CSP, §10.10 log-injection sanitization, §10.16 mail header injection |
| **A04 Insecure Design** | §4 threat model, single dispatch gateway, fail-open rate limits as a *considered* decision, sender-match rule, audit excluded from reset |
| **A05 Security Misconfiguration** | §6 code outside webroot, §10.12 headers, §10.10 `display_errors` off, least-privilege DB grant, `bin/check_env.php` |
| **A06 Vulnerable Components** | §10.15 minimal pinned deps, `composer audit`, self-hosted Chart.js, PHP 8.1+ (D1) |
| **A07 Auth Failures** | §10.3 lockout + MFA + NIST policy + breach check + timing normalization + enumeration parity, §10.4 session lifecycle |
| **A08 Integrity Failures** | §10.11 audit hash chain, §10.7 `sha256` on attachments, no `unserialize()` of untrusted data anywhere (JSON only), signed stateless CSRF |
| **A09 Logging & Monitoring Failures** | §10.10 structured logs + request IDs + `security.log` + threshold alerting, §10.11 audit coverage |
| **A10 SSRF** | Minimal surface by design: no user-supplied URL is ever fetched. The only outbound calls are SMTP/IMAP to configured hosts and the optional fixed HIBP endpoint. |

---

## 12. Frontend port strategy

The prototype's five HTML files are vanilla JS + Chart.js — no framework. Everything server-bound goes through one `call(action, payload)` wrapper.

1. Copy all five into `app/Views/` first. Do not rewrite from scratch.
2. **Rewrite the `call()` wrapper only** → `fetch('/api', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action, payload, csrf})})`.
3. **Delete the two iframe workarounds** — they were GAS-specific scar tissue: `google.script.url.getLocation()` becomes `new URLSearchParams(location.search)`; `window.open(url,'_top')` becomes `location.href = url`.
4. **Refactor inline handlers to event delegation (D5).** Mechanical: `onclick="foo('X')"` → `data-action="foo" data-arg="X"`, plus one delegated listener per view that maps `data-action` to a function in a registry. This is the largest single piece of frontend work and it is not optional — it's what makes `script-src 'self'` possible.
5. Everything else transfers: theming (dashboard = system default + toggle; public = light default + toggle; shared `sd_theme` key), pagination (10/list, per-list state, clamping), modals, Chart.js rendering with theme-aware colors read from CSS variables, notifications bell, KB, rules editor, portal, status checker.

Do not modernize beyond items 2–4. Every gratuitous rewrite is a chance to reintroduce a bug the prototype already fixed.

---

## 13. Email ingestion (IMAP — D3)

`app/Cron/ingest_email.php`, every 5 minutes:

1. `PHP_SAPI !== 'cli'` → exit.
2. `GET_LOCK('p3a_ingest', 0)` → if not acquired, exit clean.
3. Connect via `imap_open` with SSL + certificate validation. Fetch up to `IMAP_MAX_PER_RUN` unseen messages.
4. Per message: parse From (address + display name), Subject, plain-text body (falling back to stripped HTML).
5. Skip our own address and machine senders (`mailer-daemon@`, `no-reply@`, `postmaster@`).
6. Extract a ticket ID from the subject using the configured prefix (`/TKT-\d{4}-\d{4}/i`).
   - Found **and** sender matches the ticket's `customer_email` → thread as a customer reply; reopen if resolved/closed (clear `resolved_at`, resolution SLA → pending); notify the assignee (in-app + email).
   - Found but sender **mismatches** → **create a new ticket.** Never thread. Log the attempt to `security.log` — repeated mismatches are someone probing.
   - Not found → create a new ticket; apply routing rules; auto-assign; send the acknowledgement.
7. Mark `\Seen` only after the DB write commits.
8. Log a summary line always: `created=N threaded=N skipped=N mismatched=N`.
9. Release the lock; close IMAP.

Errors go to `storage/logs/ingest.log` and, if the run fails entirely, to `ALERT_EMAIL` (throttled). **A silently broken ingestion is a support inbox nobody is reading** — that failure must be loud.

---

## 14. Automation (cron)

```
*/5  * * * *  cd /home/USER/p3a && /usr/local/bin/php app/Cron/ingest_email.php  >> storage/logs/ingest.log 2>&1
*/30 * * * *  cd /home/USER/p3a && /usr/local/bin/php app/Cron/sla_monitor.php   >> storage/logs/sla.log 2>&1
0 8  * * *    cd /home/USER/p3a && /usr/local/bin/php app/Cron/daily_digest.php  >> storage/logs/digest.log 2>&1
0 2  * * *    cd /home/USER/p3a && /usr/local/bin/php app/Cron/backup_db.php     >> storage/logs/backup.log 2>&1
15 3 * * *    cd /home/USER/p3a && /usr/local/bin/php app/Cron/cleanup.php       >> storage/logs/cleanup.log 2>&1
```

- **`sla_monitor.php`** — grades overdue *pending* milestones as breached, notifies the assignee once per new breach. **Idempotent: a second run in the same window must be a no-op** (the prototype asserted this by comparing audit-row counts across two consecutive runs — port that test).
- **`daily_digest.php`** — 08:00, not midnight (D-adopted from parity, contra the P3A doc). New/resolved in 24h, open now, active breaches, per-agent workload → active admins.
- **`backup_db.php`** — ⚠ **the P3A process doc's version has a serious flaw: it does not say where dumps land.** A dump written anywhere under `public_html/` is a downloadable copy of your entire database — every password hash, every customer's PII — sitting at a guessable URL. Requirements here: dump to `storage/backups/` (outside webroot), gzip, encrypt with `openssl enc -aes-256-cbc -pbkdf2 -salt` using a key derived from `APP_KEY`, `chmod 600`, retain `BACKUP_RETAIN_DAYS`, prune older, log size and duration, alert on failure. Be honest about the limit: local encryption protects a backup that gets *exfiltrated or mis-served*, not one taken by an attacker who already owns the host and can read `.env`. **Off-site copies are the real control** — document the manual/scripted export path in `README.md`.
- **`restore_backup.php`** — a backup you have never restored is a hope, not a backup. Restoring into a scratch DB is part of the Phase 12 checklist and the quarterly maintenance routine.
- **`cleanup.php`** — purge expired rate-limit rows, expired sessions, used/expired reset tokens, notifications beyond the 300/user cap or older than 30 days, log files older than 90 days.

---

## 15. Phased build plan

**Build in order. Each phase must verify clean before the next begins.** Commit at each phase boundary with the phase number in the message. The right-hand column maps to the companion P3A process doc so both track together.

| Phase | Content | Exit criteria | P3A week |
| :--- | :--- | :--- | :--- |
| **1 — Foundation** | Folder tree (§6), `composer.json`, `.env.example`, both `.htaccess` files, `schema.sql` (§7), `seed.sql`, `bin/check_env.php`, `bin/generate_key.php`, `bin/install.php` | `check_env` all green on Go54; `install.php` creates 15 tables and seeds; `SELECT COUNT(*) FROM users` = 4 (1 admin, 2 agents, 1 customer) | W1 |
| **2 — Core** | `bootstrap.php`, `Config`, `Db` (§10.1 options), `Logger`, `ErrorHandler`, `SecurityHeaders`, `Router`, `Request`/`Response`, `View`, `helpers.php` | A scratch route returns JSON; all §10.12 headers present; a thrown exception yields a generic page + request ID in `error.log`, no stack trace | W2 |
| **3 — Auth** | `Auth`, `Session`, `Rbac`, `Csrf`, `PasswordPolicy`, `RateLimit`, login/logout, password reset (§10.9) | Every §10.3/10.4/10.9 verify item passes, including enumeration parity, timing normalization, lockout, session cap, hashed tokens, tokenless reset URL | W2 |
| **4 — Gateway & shell** | `Dispatch` (§9) with the `REQUIRES` map, dashboard shell, layout port, **event-delegation refactor (D5)**, self-hosted Chart.js, theming, pagination component | Unauthed → login; authed → dashboard; CSP with `script-src 'self'` and **zero console errors** (proves the refactor is complete); no CDN requests | W3 |
| **5 — Tickets** | Full lifecycle: create/reply/note/status/priority/assign/canned responses/SLA calculation + recalculation/auto-assign; `MessageVisibility` gate | Phase 5 test passes: first-response stamping, SLA re-grade on priority change, reopen semantics, internal-note isolation | W3 |
| **6 — Customers, comments, uploads, categories** | Customer role + portal + ownership (D2); attachments (§10.7); two-level categories with referential integrity | IDOR suite passes; `shell.php`→`photo.jpg` rejected; polyglot JPEG neutralized; category delete blocked by child/ticket/rule references | W4 |
| **7 — Admin panel** | Agents CRUD + lockout guards; SLA targets; config allowlist; routing rules engine; backup + ticket-data reset with typed phrase | Guards proven; **disabled rules provably do not fire**; reset preserves audit + config and requires the exact phrase server-side | W5 |
| **8 — Reports** | KPIs, 4 charts, agent performance, CSV export, period switcher | Aggregate consistency: status/priority/volume distributions each sum exactly to the created count | W6 |
| **9 — Public surfaces** | Landing page; submit (honeypot, ceiling, caps); status lookup; widget (§10.12 header profile); stateless CSRF (D6) | Internal-note leak test; byte-identical errors for wrong-email vs unknown-ID; widget embeds successfully in a third-party test page | W7 |
| **10 — Automation** | PHPMailer wrapper + branded template + suppression + `mail_log`; IMAP ingestion; SLA monitor; digest; backup; cleanup | Sender-mismatch creates a new ticket (never threads); SLA monitor second run = no-op; backup encrypts, and `restore_backup.php` restores into a scratch DB | W8–W9 |
| **11 — Modules** | KB; in-app notifications; routing-rule editor UI; canned response management | Ownership isolation on notifications; KB delete admin-only; rule editor rebuilds the action-value control on type change (see §18) | W10 |
| **12 — Security verification & MFA** | TOTP (D8); full `SecuritySuite`; `SECURITY.md` with the §11 map and accepted risks; adversarial pass | Every §10 verify item green; `SecuritySuite` 100%; `verify_audit.php` clean; **`bin/check_env.php` green on production** | W11 |
| **13 — Deploy & docs** | README runbook; go-live checklist; user guides; monitoring | The runbook works end-to-end on a fresh cPanel account by someone who didn't build it | W12 |

**Security is not a phase.** §10 is binding from Phase 1. Phase 12 verifies and adds MFA; it does not retrofit (D10).

---

## 16. Coding standards

- `declare(strict_types=1);` at the top of **every** PHP file.
- PSR-4 autoloading, `App\` → `app/`. PSR-12 formatting.
- Parameter and return types on every function. No exceptions.
- No globals except `helpers.php` (`e`, `raw`, `url`, `asset`, `redirect`, `now`, `csrf_field`, `old`).
- **Layering:** Controllers → Services → Models → `Db`. A controller containing SQL, or a model containing business rules, is a review failure.
- Controllers do not `echo`. They return `View::render()` or `Response::json()`.
- `$_GET` / `$_POST` / `$_FILES` / `$_SERVER` are touched **only** inside `Core\Request`. Everything else receives validated input. This is what makes "is every input validated?" an answerable question.
- Comments explain **why**, not what. Business rules and security controls get a one-line reason (`// Sender-match: prevents ticket-ID guessing from injecting into a stranger's thread`).
- ~400 lines/file ceiling. Beyond that, split.
- No dead code, no commented-out blocks, no `var_dump`/`print_r`/`dd()` left behind. Git remembers; the codebase shouldn't.
- Never `unserialize()` untrusted input. JSON only.
- Never suppress errors with `@`.

---

## 17. Testing

The prototype's per-phase self-tests caught real bugs — a rule toggle that didn't gate behaviour, an enumeration leak, a broken hash migration. The same discipline applies here, in three layers.

### 17.1 Phase tests — `tests/phases/PhaseNTest.php`
Runnable scripts (simplicity beats a framework here). Each: point at a **separate test database** via `.env.testing`, load fresh schema + seed, run the phase's assertions echoing `✓`/`✗`, clean up its scratch data, print a summary count. `php tests/run_all.php` runs everything. Each phase's exit criteria in §15 are the minimum assertions — if the test doesn't cover them, the test is incomplete.

### 17.2 Static suite — `tests/StaticSuite.php` (no DB, runs on every commit)
- `php -l` on **every** `.php` file. *(The prototype once shipped a file with a single bad escape that broke the whole app — chat delivery has no parser. This check is why that can't recur.)*
- No SQL string interpolation anywhere (regex scan).
- No `PDO` usage outside `Core\Db`.
- No `$_GET`/`$_POST`/`$_FILES` outside `Core\Request`.
- Every view output passes through `e()` or an annotated `raw()`.
- Every action in `Dispatch::REQUIRES` has a handler; every handler has a `REQUIRES` entry or is in `PUBLIC_ACTIONS`. **No orphans in either direction** — an unmapped handler is an unauthenticated endpoint.
- No `display_errors`, `var_dump`, `print_r`, `@` suppression, `unserialize(`, `eval(`.
- No secrets committed (entropy + pattern scan).
- `.env` is in `.gitignore`.

### 17.3 Security suite — `tests/SecuritySuite.php`
The adversarial pass. Absorbs and extends the P3A test-case table.

| Attack | Expected |
| :--- | :--- |
| 5 wrong passwords | Locked 15 min; 6th refused *even with the correct password* |
| Wrong password vs unknown email | **Byte-identical** message; response times within tolerance |
| Login on 6th device | Oldest session evicted; 5 remain |
| Admin logs in without TOTP | Session exists but **every** action rejected until MFA verified |
| `' OR 1=1--` in login / search / status / sort params | No injection; no SQL error surfaced |
| `<script>alert(1)</script>` as ticket subject | Escaped in dashboard, detail, status page, CSV, and digest email |
| Customer A requests customer B's ticket / attachment / notification | Denied, logged to `security.log` |
| Agent calls `deleteAgent` / `resetTicketData` | Denied |
| `role=admin` injected into a profile update | Ignored (not in the field allowlist) |
| Any mutating POST without / with a foreign / with an expired CSRF token | Rejected |
| `shell.php` renamed `photo.jpg` | Rejected by MIME sniff |
| JPEG with appended PHP | Re-encoded; stored file contains no `<?php` |
| Direct GET on `storage/uploads/<name>` | 403/404 |
| `../../etc/passwd` in every filename field | No effect (name is never a path) |
| 6th submission in an hour / 31st globally | Rate-limited |
| Rate-limiter DB error simulated | Request **proceeds** (fail-open, by design) |
| Inbound email citing a stranger's ticket ID | **New ticket created; never threaded** |
| Reset token reused | Rejected |
| Reset URL after load | Contains no token |
| Session token read from DB, replayed as cookie | Rejected (stored hashed) |
| Audit row edited by hand | `verify_audit.php` names the broken link |
| Ticket-data reset | Audit log intact, reset event appended, config/users/KB/rules untouched |
| Response headers on a normal route | All §10.12 present; `script-src` has no `unsafe-inline` |
| Widget route | No XFO; `frame-ancestors *`; embeds in a third-party page |

---

## 18. Lessons from the prototype — do not re-learn these

Each cost real debugging time. They are carried forward as requirements.

- **A toggle must actually gate behaviour.** The predecessor system had a rule toggle the engine never consulted. The routing test explicitly proves a *disabled* rule does not fire — same submission, enabled → priority high; disabled → priority stays low. Port that test verbatim.
- **The rule editor must rebuild its value control when the action type changes.** A stale dropdown from the previous type silently submitted the wrong value.
- **Sender-mismatch creates a ticket; it never threads.** (§10.17.)
- **Enumeration parity means identical *strings*, not similar ones.** Assert equality between the two error messages in the test, don't eyeball them.
- **Rate limits fail open.** Deliberate. Don't "fix" it into fail-closed.
- **Backup first, and only delete if the backup succeeded.** If the backup throws, the response is "nothing was deleted" and nothing was.
- **Legacy hashes upgrade on next login.** No forced reset, no user-visible migration.
- **Escaping is assumed until verified.** The one XSS in the prototype lived where escaping was assumed. Verify it in the static suite.
- **Every file gets `php -l`.** A single mis-escaped quote once broke a whole deployment.
- **Self-tests clean up after themselves.** They run against real data; a test that leaves scratch rows behind is a test nobody will run twice.
- **Charts, pagination, and themes are already solved.** Port them; don't redesign them.

---

## 19. Deployment runbook (expand into README during Phase 13)

1. Create the addon domain. **Set its DocumentRoot to `.../p3a/public/`, not `.../p3a/`.** (Everything in §6 depends on this.)
2. Upload the tree. Verify dotfiles (`.htaccess`, `.env.example`) actually transferred — many FTP clients hide them.
3. Create the MySQL database and an application user with `SELECT, INSERT, UPDATE, DELETE` only. Keep a separate credential for migrations.
4. `cp .env.example .env`, fill it in, **`chmod 600 .env`**.
5. `php bin/generate_key.php`.
6. `chmod 750 storage storage/{uploads,backups,logs,cache}`.
7. `php bin/check_env.php` — must be all green before proceeding.
8. `php bin/install.php` — schema + seed.
9. Log in as the seeded admin. **Change the password immediately and enrol TOTP.** The seeded credential is public knowledge (it's in this document).
10. Add the five cron jobs (§14). Confirm each writes to its log on the first tick.
11. Configure SPF, DKIM, DMARC for the domain. Send a test email; verify it arrives and is not spam-foldered.
12. **Post-deploy verification — every one of these:**
    - `https://p3a-support.com.ng` loads; `http://` 301s to it.
    - `/app/Core/Config.php` → 404 (there must be no URL that maps there at all).
    - `/storage/logs/error.log` → 403/404.
    - `/.env` → 403/404.
    - Response headers match §10.12.
    - An uploaded attachment is **not** reachable by direct URL.
    - Login, create a ticket, reply, add an internal note, upload a file, log out.
    - Public: submit a ticket, look it up by ID + email, confirm the internal note is invisible.
    - Widget embeds in an external test page.
    - `php tests/SecuritySuite.php` against the production database in read-only mode where possible, or a staging clone.
    - `php bin/restore_backup.php` into a scratch database — proving the backup is real.

---

## 20. Post-launch operations

| Cadence | Task |
| :--- | :--- |
| **Daily** | Review `security.log` (should be boring); confirm all five crons ran; scan `error.log` |
| **Weekly** | Review the audit log; check disk usage (uploads + backups grow); confirm backups exist and are non-zero |
| **Monthly** | `composer audit`; check for PHP patch releases; **restore a backup into a scratch DB**; review admin accounts and remove dormant ones |
| **Quarterly** | Run `SecuritySuite` against staging; `verify_audit.php`; review accepted risks in `SECURITY.md`; rotate `MAIL_PASS`/`IMAP_PASS`; review the OWASP Top 10 map against any new features |
| **Annually** | Full review; consider an external penetration test; plan the PHP version upgrade before the current branch reaches EOL |

---

## 21. What to build first

1. Read this document **twice**. The second pass is where the contradictions surface.
2. Copy it into the repo root as `P3A_BUILD_BRIEF.md`.
3. Create `DEV_NOTES.md` — an empty running log for decisions, surprises, and anything you had to work around.
4. Summarize the plan back in your own words, list every ambiguity or disagreement you found, and **wait for approval** before Phase 1.
5. Then build Phase 1. Nothing from Phase 2 until Phase 1 verifies clean.

**Ask rather than assume.** If something here is contradictory, missing, or wrong, say so and stop. That instruction is load-bearing: this document already corrects one contradiction I shipped in v1 (D5 — a strict CSP and inline event handlers cannot coexist, and v1 demanded both). Documents contain mistakes. Silent workarounds turn a mistake into a bug, and bugs found in Week 11 cost ten times what they cost in Week 2.

**Success looks like:** an admin completing TOTP and reaching a dashboard of real tickets; a customer submitting through a widget embedded on a third-party site and receiving a branded acknowledgement; their emailed reply threading onto the right ticket while an identical email from a stranger citing the same ticket ID lands as a separate ticket instead; an SLA breach flagged 30 minutes later; a full day's activity readable in a tamper-evident audit log; a backup restored successfully into a scratch database — and a security reviewer finding nothing in the OWASP Top 10 that isn't already answered in `SECURITY.md`.
