# P3A — Dev Notes (running decision log)

Per §21.3: an empty running log for decisions, surprises, and anything worked around.
Newest entries at the top.

---

## 2026-07-18 — Phase 3 (Auth): assumptions to reconcile

**Legacy `v2$` hash algorithm is a DOCUMENTED ASSUMPTION, not the confirmed GAS scheme.**
`PasswordPolicy::makeLegacy/verifyLegacy` implement `v2$<saltHex>$<digest>` where
`digest = PBKDF2-HMAC-SHA256(password, salt, 100000 iters, 64 hex chars)`. The brief (D4)
says only "salted-iterated-SHA256" without parameters. Before the real GAS import
(`bin/import_gas.php`, Phase 6+), the ACTUAL prototype algorithm/params MUST be confirmed
and this code aligned — otherwise imported users cannot log in. The Phase 3 test proves the
migration *mechanism* (verify → rewrite on login), which is correct regardless of params.

**`app/Security/data/common_passwords.txt` is a STARTER list (~180 entries).** §10.3 calls
for the full top-10k. Replace with the SecLists 10k-most-common (rockyou-derived) before
production. Local breach check is exact-match, case-insensitive.

**MFA gate is staged.** Auth sets `mfa_required` only when an admin has `totp_enabled=1`.
The seeded admin has `totp_enabled=0`, so they log in normally (no TOTP built until Phase 12).
Phase 12 adds TOTP AND must enforce that admins are required to enrol — otherwise D8
("required for admin") is not actually met. Tracked for Phase 12.

**Session `Secure` cookie flag = `Config::isProduction()`.** Always https in prod (§10.13) so
Secure=true there; in local dev over http it is false so the cookie is usable. Not a relaxation
of §10.4 in production.

**Login/forgot/reset use the STATELESS public CSRF token** (D6) because the forms are shown to
anonymous users with no session to bind to. Logout uses the session-bound token. These move
into the /api gateway shape in Phase 4 where applicable; the page routes stay page routes.

---

## 2026-07-18 — CONTRADICTION in brief §7: "15 tables" vs 16 defined

**Flagged per §21 (say so rather than paper over).** The §7 header reads "15 tables",
but the section defines **16** `CREATE TABLE` statements. The missing one is
`totp_backup_codes`, which D8 (admin TOTP MFA — backup codes stored hashed) requires.

**Resolution: 16 tables.** The schema is authoritative; the "15" is a stale prose count
that predates the D8 MFA addition. Dropping the table would break a locked-in feature, so
the count — not the schema — is the error. Resolved without a hard stop because the correct
answer is unambiguous. `schema.sql`, `seed.sql`, and `Phase1Test.php` all use 16.
**Action for maintainer:** correct the §7 header from "15 tables" to "16 tables".

---

## 2026-07-18 — Phase 1: Foundation started

**Local environment (dev box, Laragon on Windows):**
- PHP 8.3.30 CLI, Composer 2.9.4, MySQL 8.4.3 — all exceed the §5 minimums.
- Production target remains PHP 8.1+ / 8.2+ preferred (D1). Code must not use 8.3-only syntax.

**`ext-imap` deliberately omitted from `composer.json` `require`:**
- §5 lists `imap` as a required *runtime* extension (for D3 inbound ingestion).
- It is NOT in `composer.json` because `ext-imap` was moved out of core in PHP 8.4 and
  is frequently absent on dev boxes — listing it would block `composer install` locally.
- `bin/check_env.php` checks for it at runtime instead, which is the correct place to
  gate a hosting-specific extension. Production readiness is verified there, not by Composer.

**Approval gate (§21.4) skipped by explicit user instruction** ("get building immediately").
The load-bearing part is retained: genuine contradictions/blockers get flagged and stop
the build rather than being papered over.

**Testing is concurrent from Phase 1:** `scripts/watch.ps1` re-runs the static suite + the
current phase test on every save. See README "Testing" section.
