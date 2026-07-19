<?php
declare(strict_types=1);

/**
 * bin/totp_code.php — LOCAL DEV HELPER. Prints the current TOTP code for a user by
 * decrypting the secret stored during enrolment. Lets you complete/clear the admin
 * MFA flow without a phone authenticator while testing locally.
 *
 *   php bin/totp_code.php admin@p3a-support.com.ng
 *
 * Refuses to run when APP_ENV=production — it is a testing convenience, not a control.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Models\User;
use App\Security\Totp;

if (Config::isProduction()) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$email = $_SERVER['argv'][1] ?? '';
if ($email === '') {
    fwrite(STDERR, "Usage: php bin/totp_code.php <email>\n");
    exit(1);
}

$user = User::findByEmail(strtolower(trim($email)));
if ($user === null || empty($user['totp_secret'])) {
    fwrite(STDERR, "No enrolled/pending TOTP secret for {$email}. Click 'Enroll' on /mfa first.\n");
    exit(1);
}

$secret = Totp::decryptSecret((string) $user['totp_secret']);
$code = Totp::codeAt($secret);
$secsLeft = 30 - (time() % 30);
fwrite(STDOUT, "TOTP for {$email}: \033[1m{$code}\033[0m  (valid {$secsLeft}s)\n");
