<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * ValidationException — a business/validation error whose message is SAFE to show
 * the user (unlike an unexpected exception, whose detail is never leaked, §10.10).
 * The Dispatch gateway maps this to a 422 with the message; any other Throwable maps
 * to a generic 500 + request id.
 */
final class ValidationException extends RuntimeException
{
}
