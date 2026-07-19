# P3A Helpdesk — Security Control Inventory

Security target: **OWASP ASVS 4.0 Level 2**, OWASP Top 10 (2021), NIST SP 800-63B.
This document maps controls to code and records accepted risks. Every control below
has an automated assertion in `tests/SecuritySuite.php` and/or the phase tests.

## OWASP Top 10 (2021) coverage

| Risk | Controls (code) |
| :--- | :--- |
| **A01 Broken Access Control** | Default-deny gateway `Core\Dispatch` (5 gates, `REQUIRES` map); `Security\Rbac` role + `ownsTicket` (D2); single `Security\MessageVisibility` gate; mass-assignment allowlists in every action; CSRF (below). Tests: IDOR customer→customer, agent→admin, unverified-admin MFA gate. |
| **A02 Cryptographic Failures** | Argon2id/bcrypt (`Security\PasswordPolicy`); session tokens sha256-hashed at rest (`Core\Session`); reset tokens hashed (`Services\PasswordReset`); TOTP secrets AES-256 encrypted (`Security\Totp`); backups AES-256 (`Services\BackupService`); TLS enforced (`Core\Config` + `.htaccess`). |
| **A03 Injection** | PDO prepared statements only, `EMULATE_PREPARES=false` (`Core\Db`); `e()` escape-by-default + strict CSP; log-injection sanitisation (`Core\Logger`); mail header-injection rejection (`Services\Mailer`). StaticSuite forbids interpolated SQL/identifiers. |
| **A04 Insecure Design** | Threat model (brief §4); single dispatch gateway; fail-open rate limits (considered); inbound sender-match rule; audit excluded from ticket reset. |
| **A05 Security Misconfiguration** | All code outside webroot (`public/` only DocumentRoot); `Core\SecurityHeaders`; `display_errors=Off` in `Core\ErrorHandler`; least-privilege DB grant; `bin/check_env.php`. |
| **A06 Vulnerable Components** | One dependency (PHPMailer), pinned, `composer.lock` committed; self-hosted Chart.js; PHP 8.1+. |
| **A07 Auth Failures** | Lockout (5/15min email+IP) + MFA (TOTP, D8) + NIST password policy + breach denylist + timing normalization + enumeration parity (`Security\Auth`); session lifecycle + concurrent cap + termination (`Core\Session`). |
| **A08 Integrity Failures** | Hash-chained audit log (`Security\Audit`, `bin/verify_audit.php`); attachment sha256; JSON only, never `unserialize()`; signed stateless CSRF. |
| **A09 Logging & Monitoring** | Structured logs + request IDs + separate `security.log` + threshold alerting (`Core\Logger`, `Core\ErrorHandler`); audit coverage. |
| **A10 SSRF** | Minimal surface: no user-supplied URL is fetched. Only outbound calls are SMTP/IMAP to configured hosts and the optional fixed HIBP endpoint. |

## Multi-factor authentication (D8)

- **Admins: required.** A session for an admin starts unverified (`mfa_verified=0`) and
  the gateway rejects every action except the MFA enrol/verify actions until verified.
  Not-yet-enrolled admins are forced through enrolment on first login.
- **Agents: optional** (self-enrolled). **Customers: unavailable.**
- Secrets encrypted at rest with `APP_KEY`; 10 single-use backup codes stored hashed.
- TOTP: RFC 6238, ±1 time-step tolerance, replayed steps rejected (`users.totp_last_step`),
  verification rate-limited (`TOTPFAIL`).

## Accepted risks (documented, not solved)

- **No host-level isolation / no ClamAV** on shared hosting. Compensating controls:
  uploads stored outside webroot, random names, extension+MIME agreement, GD image
  re-encoding, never executed, never served inline (§10.7).
- **Local backup encryption keys live on the same host as the backups.** Protection is
  against backup *exfiltration / mis-serving*, not host compromise. **Off-site copies are
  the real control** — see the README deploy runbook.
- **Rate limiting fails open by design** (§10.6): a limiter outage prefers availability
  over a self-inflicted denial of service.
- **Legacy `v2$` KDF parameters** are a documented assumption pending reconciliation with
  the original GAS prototype before any real import (see `DEV_NOTES.md`).
- **Breached-password denylist** ships as a ~180-entry starter; replace with the full
  SecLists 10k for production (see `DEV_NOTES.md`).

## Verification

- `php tests/run_all.php` — StaticSuite + all phase tests + SecuritySuite + HTTP smoke.
- `php tests/SecuritySuite.php` — the adversarial regression suite.
- `php bin/verify_audit.php` — audit hash-chain integrity.
- `php bin/check_env.php` — hosting readiness (run green on production before go-live).
