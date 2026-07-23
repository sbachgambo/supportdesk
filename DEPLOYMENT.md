# P3A Support — Deployment Runbook (Phase 13)

Deploying the hardened P3A helpdesk to **Go54 shared hosting** (cPanel) on
`p3a-support.com.ng`. Follow the steps in order. Every command that touches the
database or secrets is called out. Nothing here weakens the security model
(§10) — it operationalises it.

> **PHP CLI path on cPanel** — cron and `bin/*` scripts must run with the CLI PHP
> for your selected version, not the web SAPI. On Go54/cPanel this is usually
> `/usr/local/bin/php` or a versioned binary like `/opt/cpanel/ea-php81/root/usr/bin/php`.
> Confirm with `which php` over SSH, or check cPanel → *MultiPHP Manager*. This
> runbook writes it as `PHP_CLI` — substitute your real path.

---

## 0. Prerequisites

- Go54 cPanel account with the domain `p3a-support.com.ng` pointed at it (DNS active).
- **PHP 8.1+** selected for the domain (cPanel → *MultiPHP Manager*). 8.3 recommended.
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `gd`, `fileinfo`, `zip`,
  and `imap` if you want inbound-email → ticket. Enable in cPanel → *Select PHP Extensions*.
- **MySQL 8** database (cPanel → *MySQL Databases*).
- An **AutoSSL / Let's Encrypt** certificate for the domain (cPanel → *SSL/TLS Status*).
- SSH access is strongly recommended (cron + `bin/*` scripts). If you have no SSH,
  §5b and §6b give phpMyAdmin / cron-UI equivalents.

---

## 1. Get the code onto the server

**With git (preferred):**
```bash
cd ~
git clone git@github.com:sbachgambo/supportdesk.git p3a
```

**Without SSH:** download the repo as a ZIP from GitHub, upload via cPanel
*File Manager*, and extract into `~/p3a`.

> `vendor/` is committed (shared hosting has no Composer), so there is **no
> `composer install` step**. If you ever do run Composer, use
> `composer install --no-dev --optimize-autoloader`.

---

## 2. Point the domain's document root at `public/`

**This is the single most important hardening step.** Only `public/` may be
web-served; `app/`, `database/`, `storage/`, `bin/`, `.env` must sit **outside**
the web root.

- cPanel → *Domains* → manage `p3a-support.com.ng` → set **Document Root** to
  `p3a/public`.
- Verify: `https://p3a-support.com.ng/` loads, and
  `https://p3a-support.com.ng/../app/bootstrap.php` is **not** reachable.
- The committed `public/.htaccess` provides the front-controller rewrite and
  security headers; the top-level `.htaccess` is a belt-and-braces deny for the
  app root if the docroot is ever misconfigured. Confirm Apache `AllowOverride`
  is on (default on Go54).

---

## 3. Create the database + a least-privilege app user

cPanel → *MySQL Databases*:

1. Create a database, e.g. `cpuser_p3a`.
2. Create a user, e.g. `cpuser_p3aapp`, with a long random password.
3. Add the user to the database with **only** these privileges:
   `SELECT, INSERT, UPDATE, DELETE`.
   - The app never needs DDL at runtime. (Schema install in §5 uses this same
     user once for `CREATE TABLE`; you may temporarily grant `CREATE`/`ALTER`/`INDEX`
     for the install, then revoke — or grant `ALL` just for the install and drop
     back to the four DML privileges afterward.)
   - The **backup** cron uses `mysqldump`, which needs `SELECT` (+ `LOCK TABLES`
     if you keep dump locking on) — already covered by `SELECT`.

---

## 4. Configure `.env`

```bash
cd ~/p3a
cp .env.example .env
chmod 600 .env            # secrets are owner-read-only
PHP_CLI bin/generate_key.php   # writes APP_KEY into .env (32 random bytes, base64)
```

Then edit `.env` and fill in **at minimum**:

| Key | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_URL` | `https://p3a-support.com.ng` (must be `https://` — validated at boot) |
| `APP_KEY` | (written by `generate_key.php`) |
| `APP_TIMEZONE` | `Africa/Lagos` |
| `DB_HOST` | `localhost` |
| `DB_NAME` | `cpuser_p3a` |
| `DB_USER` | `cpuser_p3aapp` |
| `DB_PASS` | (the app user's password) |
| `MAIL_*` | your outbound SMTP (cPanel email account) |
| `IMAP_*` | inbound mailbox, if using email-to-ticket |
| `ALERT_EMAIL` | where error/security alerts go |
| `MYSQLDUMP_BIN` | full path to `mysqldump` (find with `which mysqldump`) |

Leave the rate-limit, session, upload, and backup values at their defaults
unless you have a reason to change them.

> **Never** commit `.env`. It is gitignored. Keep `chmod 600`.

---

## 5. Install the schema (+ seed)

**5a. With SSH:**
```bash
cd ~/p3a
PHP_CLI bin/check_env.php     # RED/GREEN hosting readiness — fix any RED before continuing
PHP_CLI bin/install.php       # creates schema + seeds baseline data
```
`install.php` refuses if tables already exist (use `--force` only to wipe and
reinstall, or `--no-seed` for schema only).

**5b. Without SSH (phpMyAdmin):**
- cPanel → *phpMyAdmin* → select `cpuser_p3a` → *Import* → upload
  `database/schema.sql`, then `database/seed.sql`.

---

## 6. First admin sign-in + lock down the seed account

The seed creates one admin: **`admin@p3a-support.com.ng`**.

1. Go to `https://p3a-support.com.ng/login` and sign in with the seed
   credentials (see `database/seed.sql`).
2. Admin accounts **require MFA** — you'll be sent to `/mfa` to enrol a TOTP app
   (Google Authenticator, Authy, etc.). **Save the backup codes.**
3. You'll be prompted to **change the seeded password** immediately
   (`must_change_pw`).
4. In *Admin Panel → Agents*, create the real staff accounts (each gets a
   temp password and is forced to change it on first login). Deactivate or
   delete the seed admin only **after** a second real admin exists — the
   last-active-admin guard will block you otherwise.
5. In *Admin Panel → System*, set the real company name, support email, brand
   colour, and ticket prefix. Review *SLA Targets*, *Categories*, and *Routing Rules*.

---

## 7. Cron jobs

cPanel → *Cron Jobs*. Add these five (adjust `PHP_CLI` and the `~/p3a` path).
Each runner refuses to run over HTTP — they are CLI-only.

| Schedule | cPanel timing | Command |
|---|---|---|
| Every 5 min | `*/5 * * * *` | `PHP_CLI ~/p3a/app/Cron/ingest_email.php` |
| Every 30 min | `*/30 * * * *` | `PHP_CLI ~/p3a/app/Cron/sla_monitor.php` |
| Daily 02:15 | `15 2 * * *` | `PHP_CLI ~/p3a/app/Cron/backup_db.php` |
| Daily 03:00 | `0 3 * * *` | `PHP_CLI ~/p3a/app/Cron/cleanup.php` |
| Daily 08:00 | `0 8 * * *` | `PHP_CLI ~/p3a/app/Cron/daily_digest.php` |

Notes:
- `ingest_email` uses a MySQL `GET_LOCK` for mutual exclusion, so overlapping
  runs are safe; skip it entirely if you're not using email-to-ticket.
- `sla_monitor` is idempotent — a second run in the same window is a no-op.
- `backup_db` writes encrypted `.gz.enc` archives to `storage/backups/` and
  prunes to `BACKUP_RETAIN_DAYS`.
- To silence cron mail on success, append `>/dev/null 2>&1` (errors still go to
  `ALERT_EMAIL` via the app).

---

## 8. File permissions

```bash
cd ~/p3a
chmod 600 .env
find storage -type d -exec chmod 750 {} \;   # storage/ must be writable by PHP
# uploads, backups, logs, cache are gitignored-empty with .gitkeep — keep them writable
```
`storage/` (uploads, backups, logs, cache) must be writable by the PHP user.
Nothing else needs write access.

---

## 9. HTTPS / final checks

- Confirm the padlock on `https://p3a-support.com.ng` (AutoSSL issued).
- Force HTTPS: the app sets HSTS; also enable cPanel → *Domains* → **Force HTTPS
  Redirect**.
- The strict CSP (`script-src 'self'`), `X-Content-Type-Options`, `Referrer-Policy`,
  and frame protections ship in `public/.htaccess` / the app headers — no action needed.

---

## 10. Post-deploy smoke checklist

- [ ] `https://p3a-support.com.ng/` responds over HTTPS (padlock valid)
- [ ] `/submit` renders and a test ticket creates → returns a `TKT-YYYY-NNNN` id
- [ ] `/status` finds that ticket with the same email; wrong email → generic "no match"
- [ ] `/login` works; admin is forced through `/mfa`, then forced password change
- [ ] Agent dashboard loads; open a ticket, reply (ticket flips open→pending), add an internal note
- [ ] Admin Panel: all nine tabs load (Agents / Organizations / Products / Categories / SLA / System / Routing Rules / Audit Log / Backup)
- [ ] `/help` shows the public Help Centre (publish at least one public KB article)
- [ ] Submit a test ticket with a REAL email → the "We received your request" receipt arrives
- [ ] Trigger *Admin → Backup → Run backup now* → a `.gz.enc` appears in `storage/backups/`
- [ ] `PHP_CLI ~/p3a/app/Cron/sla_monitor.php` runs cleanly by hand
- [ ] `app/`, `.env`, `storage/` are **not** reachable via the browser
- [ ] Password-reset email arrives (check `MAIL_*`); reset link works and is single-use

---

## 11. Backups & restore

- Automated: the daily `backup_db` cron (encrypted, pruned to `BACKUP_RETAIN_DAYS`).
- On demand: *Admin Panel → Backup & Data → Run backup now*, or
  `PHP_CLI bin/… ` equivalents.
- **Restore** a backup into a scratch DB first to verify, then into production:
  ```bash
  PHP_CLI bin/restore_backup.php storage/backups/<file>.gz.enc
  ```
  (Reads `APP_KEY` to decrypt. Test on a scratch DB before touching production.)
- The audit log **survives** a ticket-data reset by design (§10.11); backups
  capture everything.

---

## 12. Updating the deployment later

```bash
cd ~/p3a
git pull
# no build step; vendor/ is committed. If schema changed, apply database/migrations/ in order.
PHP_CLI bin/check_env.php
```
Review `database/migrations/` for any new migration files and apply them in
order via phpMyAdmin or the CLI before the new code serves traffic.

> **July 2026 release:** an existing database needs two migrations, in order:
> `database/migrations/2026_07_22_products.sql` (Products/Projects table +
> `tickets.product_id`) and `database/migrations/2026_07_23_super_admin.sql`
> (the `super_admin` role + a hidden owner account — **edit the seeded email in
> that file to your real owner address before running it**, and change its
> password on first login). Then add your real product names in *Admin → Products
> / Projects*. Customers now receive real emails (submission receipt, agent-reply
> notification, satisfaction request) — make sure `MAIL_*` in `.env` points at a
> working mailbox before going live. Optional: paste a Slack Incoming Webhook into
> *Admin → System* to get new-ticket + SLA-breach alerts in Slack. Review the
> *Admin → System* auto-close-days setting and *SLA Targets → SLA clock*
> (business-hours) option after deploying. The brand name shown on every page
> (incl. the landing) is *Admin → System → Company name*.

---

### Security posture (already built in — no deploy action required)

Argon2id/bcrypt password hashing · DB-hashed session tokens · admin TOTP MFA with
backup codes · single-gateway authz (allowlist → rate-limit → CSRF → auth+MFA →
role) · hash-chained tamper-evident audit log · strict CSP with no inline handlers ·
upload validation outside the webroot · enumeration-safe login/reset/status ·
encrypted backups. See `P3A_BUILD_BRIEF.md` §10 for the full threat model.
