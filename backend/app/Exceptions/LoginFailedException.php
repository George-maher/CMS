<?php

namespace App\Exceptions;

use App\Enums\LoginFailureCode;
use RuntimeException;
use Throwable;

/**
 * Domain exception for authentication failures.
 *
 * Replaces the previous "throw ValidationException for everything" pattern,
 * which made a 422 VALIDATION_ERROR indistinguishable between a malformed
 * request body and a rejected login. The renderer in bootstrap/app.php turns
 * this into a typed JSON envelope:
 *
 *   { "success": false, "message": "<localized>", "code": "EMAIL_NOT_VERIFIED" }
 *
 * with the status defined by LoginFailureCode::status().
 *
 * The message is resolved at construction time so the active request locale
 * (SetLocale middleware) is already applied.
 */
class LoginFailedException extends RuntimeException
{
    public function __construct(
        public readonly LoginFailureCode $failure,
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? (string) __($failure->messageKey()), 0, $previous);
    }
}
