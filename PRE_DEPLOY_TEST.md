# P3A Support — Offline Pre-Deployment Test Checklist

Run this **on your local machine (Laragon)** before deploying to Go54. It walks
the entire system by hand — what to click and exactly what to expect. Tick each
box; if anything doesn't match "Expect", stop and note it before going live.

Time budget: ~45–60 min for a full pass.

---

## 0. Setup — get to a known state

**Reset the dev database to a clean, seeded state** (do this so results are
predictable):

```bash
cd c:/laragon/www/ticksys
php bin/install.php --force     # drops + recreates schema, loads baseline seed
php bin/seed_demo.php           # adds 9 realistic demo tickets (varied status/priority/SLA)
```

**Start the dev server** (leave it running in its own terminal):

```powershell
powershell -ExecutionPolicy Bypass -File scripts/serve.ps1
```

- **Browse to `http://localhost:8000`** — use **`localhost`**, *not* `127.0.0.1`
  (the session cookie is bound to the `localhost` host).

**Test accounts** (password for all: **`P3a-Seed-Change!2026`**):

| Role | Email | Notes |
|---|---|---|
| Admin | `admin@p3a-support.com.ng` | MFA enrolled → will ask for a 6-digit code |
| Agent | `agent1@p3a-support.com.ng` (Sarah Smith) | no MFA; **forced password change** on first login |
| Agent | `agent2@p3a-support.com.ng` (James Chen) | no MFA; **forced password change** on first login |
| Customer | `customer@example.com` | customer role |

**Get the admin's MFA code on demand** (dev helper — prints the current TOTP):

```bash
php bin/totp_code.php admin@p3a-support.com.ng
```

> **Re-running the suite:** if you'd rather trust the automated tests, run
> `php tests/run_all.php` — all 15 suites should print **✓ ALL SUITES GREEN**.
> The manual pass below covers the things a human eye catches that tests don't
> (layout, flows, wording).

> **AV note:** the security suite writes throwaway files named `shell.jpg` /
> `evil.jpg` under `storage/cache/` that Windows Defender may quarantine. That's
> a false positive on our own upload-rejection test fixtures — ignore it, or
> exclude `storage/cache` in Defender.

---

## 1. Automated gate (do this first)

- [ ] `php bin/check_env.php` → **Expect:** all green (no RED hard-requirement failures)
- [ ] `php tests/run_all.php` → **Expect:** `✓ ALL SUITES GREEN` (15 suites, ~570 checks), exit 0

---

## 2. Public surfaces (no login)

### 2.1 Submit a request — `http://localhost:8000/submit`
- [ ] **Do:** page loads. **Expect:** the branded card ("How can we help?"), company name = **Acme Corporation**, dark/light toggle top-right.
- [ ] **Do:** click **Submit** with everything empty. **Expect:** inline red error asking for a valid email / subject / description (form does not submit).
- [ ] **Do:** fill name, a valid email, subject, description, pick a category + priority; Submit. **Expect:** success view with a green check and a boxed reference id like **`TKT-2026-0001`**. Copy that id + email.
- [ ] **Do:** note the priority dropdown options. **Expect:** only Low / Normal / High — **no "Urgent"** (public priority ceiling).
- [ ] **Do:** click **Submit another request**. **Expect:** the form resets to blank.
- [ ] **Do:** toggle dark mode. **Expect:** the whole page switches theme and stays switched on reload.

### 2.2 Check status — `http://localhost:8000/status`
- [ ] **Do:** enter the ticket id + the **correct** email; Check Status. **Expect:** a result panel — status chip, subject, priority/submitted meta, and "Our team has received your request…" (no reply yet).
- [ ] **Do:** same ticket id + a **wrong** email. **Expect:** the generic **"No ticket matches that ID and email."**
- [ ] **Do:** a made-up ticket id + any email. **Expect:** the *byte-identical* same message (no hint that one exists and one doesn't — this is the enumeration guard).

### 2.3 Widget (optional)
- [ ] **Do:** open `http://localhost:8000/submit?widget=1`. **Expect:** the same form with no theme toggle / footer links (embeddable mode).

---

## 3. Authentication & MFA

### 3.1 Login — `http://localhost:8000/login`
- [ ] **Do:** load `/login`. **Expect:** split-screen brand page ("Support that just *works*.").
- [ ] **Do:** sign in with a **wrong** password. **Expect:** one generic error ("check your email and password" — never "no such user").
- [ ] **Do:** sign in with a **non-existent** email. **Expect:** the *same* generic error (no user enumeration).

### 3.2 Forced password change (agent) — sign in as `agent1@…`
- [ ] **Do:** sign in as `agent1@p3a-support.com.ng` / seed password. **Expect:** dashboard loads, then a **"Choose a new password"** modal appears that **cannot be dismissed** (backdrop click + close both do nothing).
- [ ] **Do:** enter a wrong current password. **Expect:** "Your current password is incorrect."
- [ ] **Do:** current = seed password, new = something < 12 chars. **Expect:** "at least 12 characters."
- [ ] **Do:** new ≠ confirm. **Expect:** "New passwords do not match."
- [ ] **Do:** current = seed, new = a fresh 12+ char password, confirm matches; submit. **Expect:** toast "Password updated", modal closes, dashboard usable.
- [ ] (You just changed agent1's password — remember it, or re-run `php bin/install.php --force && php bin/seed_demo.php` to reset later.)

### 3.3 Admin MFA challenge — sign in as `admin@…`
- [ ] **Do:** sign in as `admin@p3a-support.com.ng` / seed password. **Expect:** redirected to a **Two-factor verification** card (not the dashboard).
- [ ] **Do:** run `php bin/totp_code.php admin@p3a-support.com.ng`, enter the 6-digit code. **Expect:** dashboard loads. Admin has no forced password change.
- [ ] **Do:** enter a wrong code first. **Expect:** "Invalid code." and you stay on the MFA screen.

### 3.4 Forgot / reset password
- [ ] **Do:** `/login` → **Forgot password?** → enter `agent2@p3a-support.com.ng`; submit. **Expect:** neutral notice "If that email belongs to an active account, a reset link is on its way."
- [ ] **Do:** enter a **non-existent** email; submit. **Expect:** the *same* neutral notice (no enumeration).
- [ ] **Do:** get the reset link. On production the email is delivered; in **dev, mail is
      "pretend"** (recorded in the `mail_log` table, not actually sent), so mint a working
      link directly from your terminal:
      ```
      php -r "require 'vendor/autoload.php'; App\Core\Config::load('.env'); echo 'http://localhost:8000/reset?token=' . App\Services\PasswordReset::createToken('agent2@p3a-support.com.ng','James Chen') . PHP_EOL;"
      ```
      Copy the printed `/reset?token=…` URL and open it.
      **Expect:** the URL immediately redraws as `/reset` with **no token in the address bar**, showing "Choose a new password".
- [ ] **Do:** confirm the send was logged: `php -r "require 'vendor/autoload.php'; App\Core\Config::load('.env'); var_dump(App\Core\Db::queryAll(\"SELECT recipient,subject,status FROM mail_log ORDER BY id DESC LIMIT 3\"));"`
      **Expect:** a recent row `subject='Reset your password'`, `status='sent'` (pretend in dev).
- [ ] **Do:** set a new 12+ char password. **Expect:** "Password updated!" card → **Sign in →**.
- [ ] **Do:** open the same reset link again. **Expect:** "Link invalid or expired" (single-use).

### 3.5 Session / logout
- [ ] **Do:** while signed in, click the **sign-out** icon (sidebar footer). **Expect:** back at `/login`.
- [ ] **Do:** visit `http://localhost:8000/dashboard` while signed out. **Expect:** redirected to `/login`.

---

## 4. Agent dashboard (sign in as agent2, or admin after MFA)

- [ ] **Do:** land on Dashboard. **Expect:** stat cards (Open, Pending/Resolved 24h, SLA breaches, avg response) with real numbers; a "recent tickets" list.
- [ ] **Do:** click sidebar **All Tickets**. **Expect:** a table of all 9 demo tickets — priority dots, status chips, SLA chips, assignee avatars; count badge matches.
- [ ] **Do:** **My Tickets**. **Expect:** only tickets assigned to the signed-in agent.
- [ ] **Do:** **SLA Breaches**. **Expect:** only breached, still-open tickets (the demo seed includes at least one).
- [ ] **Do:** **Resolved**. **Expect:** resolved/closed tickets only.
- [ ] **Do:** if a list has >10 rows, use the pager. **Expect:** 10 per page, pager moves between pages.
- [ ] **Do:** toggle dark mode (top bar). **Expect:** whole app re-themes; persists on reload.
- [ ] **Do:** click the **bell**. **Expect:** a notifications panel opens (may be empty); "Mark all read" works.

---

## 5. Ticket detail & lifecycle

Open any **open** demo ticket from All Tickets.

- [ ] **Expect:** a wide modal — left: message thread (customer/agent/note bubbles); right: Customer, Status, Priority, Assignee, SLA, Attachments.
- [ ] **Do:** type a reply, click **Reply**. **Expect:** it appears in the thread as an agent message, **and the ticket status flips Open → Pending** (check the status chip).
- [ ] **Do:** type text, click **Internal note**. **Expect:** it appears as a distinct note bubble.
- [ ] **Do:** open the same ticket's **public status page** (`/status` with its id + customer email in another tab). **Expect:** the reply is visible to the customer, but the **internal note is NOT** shown.
- [ ] **Do:** pick a **Canned response** from the composer dropdown. **Expect:** the composer fills with the templated text (customer/agent/ticket-id substituted).
- [ ] **Do:** **Attach** a normal file (PNG/PDF). **Expect:** it uploads and lists under Attachments; you can download it.
- [ ] **Do:** change **Status** to Resolved. **Expect:** toast/confirmation; the ticket shows resolved; SLA resolution grades (met/breached).
- [ ] **Do:** change **Priority** to Urgent. **Expect:** it updates; SLA deadlines recompute (a past-due milestone may flip to "breached").
- [ ] **Do:** change **Assigned to** another agent. **Expect:** it updates; that agent gets a notification.

---

## 6. Create a ticket (agent side)

- [ ] **Do:** **+ New ticket**, fill subject/customer email/description, pick priority (Urgent available here); Create. **Expect:** modal closes, toast, the new ticket appears at the top of All Tickets and is auto-assigned to an agent.
- [ ] **Do:** try Create with an invalid email. **Expect:** a red validation error, no ticket created.

---

## 7. Knowledge Base

- [ ] **Do:** sidebar **Knowledge Base**. **Expect:** a card grid of articles (or an empty state if none seeded).
- [ ] **Do:** open an article. **Expect:** it renders; content shows as text (no broken markup).

---

## 8. Reports

- [ ] **Do:** sidebar **Reports**. **Expect:** 4 stat cards + charts (volume line, distribution bars) render via the self-hosted Chart.js.
- [ ] **Do:** switch the period (7/30/90 days). **Expect:** the numbers and charts update.
- [ ] **Do:** click **Export CSV**. **Expect:** a `.csv` downloads; opening it shows the ticket rows with headers.

---

## 9. Admin panel (sign in as admin, after MFA)

Sidebar shows **Administration → Admin Panel** (only for admins).

### 9.1 Agents
- [ ] **Do:** open **Agents** tab. **Expect:** table of all users with role chips + Active + action buttons.
- [ ] **Do:** **Add** a member (name/email/role/temp password). **Expect:** toast "added", appears in the table.
- [ ] **Do:** sign out, sign in as that new member. **Expect:** forced password-change modal (temp password).
- [ ] **Do:** back as admin — **Deactivate** then **Activate** that member. **Expect:** Active toggles Yes/No.
- [ ] **Do:** **Reset PW** on that member (enter a value in the prompt). **Expect:** toast "Password reset".
- [ ] **Do:** try to **Deactivate your own** admin account. **Expect:** blocked — "You cannot deactivate your own account."
- [ ] **Do:** **Delete** the test member you created. **Expect:** removed from the table.

### 9.2 Categories
- [ ] **Do:** **Categories** tab → **Add** a category (name + colour + parent). **Expect:** appears in the table.
- [ ] **Do:** **Disable/Enable** it. **Expect:** Active toggles.
- [ ] **Do:** **Delete** a category that has tickets assigned. **Expect:** blocked ("tickets are assigned…"). Delete your empty test category → succeeds.

### 9.3 SLA Targets
- [ ] **Do:** **SLA Targets** tab → change a tier's minutes → **Save SLA targets**. **Expect:** toast "SLA saved".
- [ ] **Do:** set resolution < response → Save. **Expect:** rejected with an error.

### 9.4 System
- [ ] **Do:** **System** tab → change company name/support email/brand colour → **Save settings**. **Expect:** toast; reload shows the new company name in the top bar.
- [ ] **Do:** enter an invalid support email → Save. **Expect:** rejected.

### 9.5 Routing Rules
- [ ] **Do:** **Routing Rules** tab → **Add** a rule (IF subject contains "refund" THEN set priority high) → Create. **Expect:** appears in the table.
- [ ] **Do:** submit a public ticket (`/submit`) whose subject contains "refund". **Expect:** in All Tickets it comes in at **High** priority (rule fired).
- [ ] **Do:** **Disable** the rule, submit another "refund" ticket. **Expect:** it comes in at the chosen priority (rule did **not** fire).
- [ ] **Do:** **Delete** the rule.

### 9.6 Backup & Data
- [ ] **Do:** **Backup & Data** tab → **Run backup now**. **Expect:** toast "Backup created: …"; a `.gz.enc` file appears in `storage/backups/`.
- [ ] **Do:** in the danger zone, type the wrong phrase → Reset. **Expect:** refused ("confirmation phrase did not match").
- [ ] **(Optional, destructive)** type `RESET TICKET DATA` exactly → confirm. **Expect:** tickets cleared, a backup taken first, and the audit log **survives**. Re-seed afterward with `php bin/seed_demo.php`.

---

## 10. Change password (self-service, non-forced) — as admin

- [ ] **Do:** click the **key icon** in the sidebar footer. **Expect:** "Change password" modal (dismissable — has a close ×).
- [ ] **Do:** wrong current password. **Expect:** "Your current password is incorrect."
- [ ] **Do:** valid current + a new 12+ char password + matching confirm. **Expect:** toast "Password updated".

---

## 11. Security spot-checks (quick, high-value)

- [ ] **Do:** on any page, **View Source** and search for `onclick=`/`onchange=`. **Expect:** none — all behaviour is external JS (strict CSP). No console errors either.
- [ ] **Do:** browse to `http://localhost:8000/app/bootstrap.php` and `http://localhost:8000/.env`. **Expect:** not served (404 / blocked) — only `public/` is web-reachable.
- [ ] **Do:** as an **agent** (not admin), no "Admin Panel" link shows; also try an admin action isn't reachable. **Expect:** admin surface is invisible/blocked for agents.
- [ ] **Do:** upload a file renamed to `.php` (e.g. `test.php` as an attachment). **Expect:** rejected by the upload validator.
- [ ] **Do:** submit the **same email** to `/submit` more than 5 times in a row. **Expect:** around the 6th, "Too many submissions right now."
- [ ] **Do:** (already covered) `/status` and `/login` return identical errors for wrong-vs-unknown. **Expect:** confirmed in §2.2 and §3.1.

---

## 12. Cron runners (run each once by hand)

These are CLI-only (they refuse to run over HTTP). Run from the project root:

- [ ] `php app/Cron/sla_monitor.php` → **Expect:** runs clean; breached tickets get audited + assignees notified; a second run is a no-op.
- [ ] `php app/Cron/backup_db.php` → **Expect:** an encrypted `.gz.enc` lands in `storage/backups/`.
- [ ] `php app/Cron/cleanup.php` → **Expect:** runs clean (prunes old sessions/logs/tokens).
- [ ] `php app/Cron/daily_digest.php` → **Expect:** runs clean (in dev, mail is pretended — check `storage/logs/`).
- [ ] `php app/Cron/ingest_email.php` → **Expect:** runs clean or a graceful "IMAP not configured" (fine locally without a mailbox).

---

## 13. Backup → restore verification (proves your safety net)

- [ ] **Do:** create a backup (`php app/Cron/backup_db.php`), note the newest file in `storage/backups/`.
- [ ] **Do:** create a scratch DB, then restore into it (never the live DB):
      `php bin/restore_backup.php storage/backups/<file>.gz.enc --into=p3a_restore_test`
      (create `p3a_restore_test` first in your MySQL tool; the command refuses to touch the
      live `DB_NAME` unless `--force`).
      **Expect:** it decrypts (using APP_KEY) and restores the tables without error.

---

## Sign-off

| Area | Pass? | Notes |
|---|---|---|
| Automated suite (§1) | ☐ | |
| Public submit/status (§2) | ☐ | |
| Auth + MFA + reset (§3) | ☐ | |
| Dashboard + lists (§4) | ☐ | |
| Ticket lifecycle (§5) | ☐ | |
| Create ticket (§6) | ☐ | |
| KB (§7) | ☐ | |
| Reports (§8) | ☐ | |
| Admin panel — 6 tabs (§9) | ☐ | |
| Change password (§10) | ☐ | |
| Security spot-checks (§11) | ☐ | |
| Cron runners (§12) | ☐ | |
| Backup/restore (§13) | ☐ | |

**All green?** You're clear to deploy — follow `DEPLOYMENT.md`.
**Before you deploy, reset dev to a clean state** so no test data lingers:
`php bin/install.php --force` (+ `php bin/seed_demo.php` only if you want demo data locally; do **not** seed demo tickets on production).
