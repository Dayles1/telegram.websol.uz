<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * State of a Telegram account (user_phones.status).
 *
 *   requesting_code -> code_sent -> verifying -> authorized
 *                                       \-> need_password -> verifying
 *   errors on the way: code_invalid (retry the code), password_invalid (retry the
 *   password), failed (start again)
 *   later: authorized -> revoked (Telegram killed the session)
 *          authorized -> logging_out -> logged_out
 */
enum AccountStatus: string
{
    case RequestingCode = 'requesting_code';
    case CodeSent = 'code_sent';
    case Verifying = 'verifying';
    case NeedPassword = 'need_password';
    case CodeInvalid = 'code_invalid';
    case PasswordInvalid = 'password_invalid';
    case Failed = 'failed';
    case Authorized = 'authorized';
    case Revoked = 'revoked';
    case LoggingOut = 'logging_out';
    case LoggedOut = 'logged_out';

    /** A worker is busy with this account right now; the UI waits. */
    public function isInFlight(): bool
    {
        return in_array($this, [self::RequestingCode, self::Verifying, self::LoggingOut], true);
    }

    /** The login waits for something the user types. */
    public function awaitsInput(): bool
    {
        return in_array($this, [self::CodeSent, self::CodeInvalid, self::NeedPassword, self::PasswordInvalid], true);
    }

    public function acceptsCode(): bool
    {
        return in_array($this, [self::CodeSent, self::CodeInvalid], true);
    }

    public function acceptsPassword(): bool
    {
        return in_array($this, [self::NeedPassword, self::PasswordInvalid], true);
    }

    /** Somebody may start a fresh login for this phone. */
    public function canStartLogin(): bool
    {
        return ! $this->isInFlight() && $this !== self::Authorized;
    }

    public function canSend(): bool
    {
        return $this === self::Authorized;
    }

    public function tone(): string
    {
        return match ($this) {
            self::Authorized => 'success',
            self::Revoked, self::Failed => 'error',
            self::CodeInvalid, self::PasswordInvalid => 'warning',
            self::LoggedOut => 'neutral',
            default => 'info',
        };
    }

    public function label(): string
    {
        return __("enums.account_status.{$this->value}");
    }
}
