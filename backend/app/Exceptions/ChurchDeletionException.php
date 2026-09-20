<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Domain exception for the church deletion (soft/hard/restore) workflow.
 *
 * Carries a stable machine-readable code plus a localized, user-safe message.
 * Unexpected technical failures are logged with full detail server-side and
 * rethrown here with the generic CHURCH_DELETE_FAILED code — never exposed.
 */
class ChurchDeletionException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
