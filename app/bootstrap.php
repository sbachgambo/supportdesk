<?php
declare(strict_types=1);

/**
 * bootstrap.php — wires the runtime. Required by public/index.php, the CLI cron
 * scripts, and tests. Deliberately free of superglobals and of any output.
 *
 * Env file selection: define P3A_ENV_FILE before requiring this to point at an
 * alternate env (tests use .env.testing). Otherwise .env at the project root.
 */

use App\Core\Config;
use App\Core\ErrorHandler;

if (!defined('P3A_ROOT')) {
    define('P3A_ROOT', dirname(__DIR__));
}

require P3A_ROOT . '/vendor/autoload.php';

// Fail loudly and immediately on missing config, not at 2am in a cron job (§8).
$envFile = defined('P3A_ENV_FILE') ? P3A_ENV_FILE : P3A_ROOT . '/.env';
Config::load($envFile);

// All datetimes are handled as UTC (§7); rendering converts to APP_TIMEZONE.
date_default_timezone_set('UTC');

// Production error posture is enforced in code, never trusting php.ini (§10.10).
ErrorHandler::register();
