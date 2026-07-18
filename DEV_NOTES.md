# P3A — Dev Notes (running decision log)

Per §21.3: an empty running log for decisions, surprises, and anything worked around.
Newest entries at the top.

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
