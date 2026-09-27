<?php

namespace App\Enums;

/**
 * Machine-readable outcome codes for authentication failures.
 *
 * These are part of the public API contract: the frontend switches on
 * `code` (never on the human-readable message) to decide which recovery
 * action to offer. Keeping them in one enum guarantees the backend and the
 * frontend i18n layers can never drift apart.
 */
enum LoginFailureCode: string
{
    /** Email or password did not match, or the account is not allowed here. */
    case InvalidCredentials = 'LOGIN_FAILED';

    /** Account exists and credentials are correct, but is_active = false. */
    case AccountInactive = 'ACCOUNT_INACTIVE';

    /** Account exists and credentials are correct, but email is unverified. */
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';

    /** The tenant church is suspended by the platform. */
    case ChurchSuspended = 'CHURCH_SUSPENDED';

    /** The tenant church was soft-deleted / decommissioned. */
    case ChurchDeleted = 'CHURCH_DELETED';

    /** Church application is still awaiting platform review. */
    case ApplicationPending = 'APPLICATION_PENDING';

    /** Church application was rejected by the platform. */
    case ApplicationRejected = 'APPLICATION_REJECTED';

    /**
     * HTTP status for this failure.
     *
     * 401 is reserved for "we will not tell you who you are" (bad
     * credentials). Once the caller is authenticated-but-blocked we answer
     * 403 so the client can render a specific recovery action.
     */
    public function status(): int
    {
        return match ($this) {
            self::InvalidCredentials => 401,
            self::AccountInactive,
            self::EmailNotVerified,
            self::ChurchSuspended,
            self::ChurchDeleted,
            self::ApplicationPending,
            self::ApplicationRejected => 403,
        };
    }

    /**
     * Translation key for the human-readable, localized message.
     */
    public function messageKey(): string
    {
        return match ($this) {
            self::InvalidCredentials => 'auth.failed',
            self::AccountInactive => 'auth.inactive',
            self::EmailNotVerified => 'auth.email_not_verified',
            self::ChurchSuspended => 'auth.suspended',
            self::ChurchDeleted => 'auth.church_deleted',
            self::ApplicationPending => 'auth.pending',
            self::ApplicationRejected => 'auth.rejected',
        };
    }
}
