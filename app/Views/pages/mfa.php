<?php
declare(strict_types=1);
/**
 * MFA challenge / enrolment (D8). mfa.js drives it against the /api actions (which are
 * reachable while the session is unverified). Enrolled → code challenge; not enrolled
 * (admin first login) → TOTP enrolment with backup codes.
 *
 * @var string $csrf
 */
?>
<section class="p3a-auth" data-view="mfa" data-csrf="<?= e($csrf) ?>">
    <h1>Two-factor verification</h1>
    <p class="p3a-form-msg" data-bind="mfa-msg" role="status"></p>

    <div data-region="mfa-challenge" hidden>
        <p>Enter the 6-digit code from your authenticator app.</p>
        <form data-action="mfa-verify">
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9A-Za-z]*" required autofocus>
            <button type="submit">Verify</button>
        </form>
    </div>

    <div data-region="mfa-enroll" hidden>
        <p>Multi-factor authentication is required. Scan this secret into your authenticator app (or enter it manually), then confirm a code.</p>
        <p><code data-bind="mfa-secret"></code></p>
        <p><a data-bind="mfa-uri" href="#" rel="noopener">otpauth link</a></p>
        <form data-action="mfa-confirm">
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
            <button type="submit">Confirm &amp; enable</button>
        </form>
    </div>

    <div data-region="mfa-backup" hidden>
        <h2>Save your backup codes</h2>
        <p>Each can be used once if you lose your device. Store them somewhere safe.</p>
        <ul data-bind="mfa-backup-list"></ul>
        <p><a href="<?= e(url('dashboard')) ?>">Continue to dashboard</a></p>
    </div>
</section>
