<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * superadmin: one person (config telegram.superadmin_email), sees and manages everything.
 * admin: the superadmin's rights except for accounts: their own and plain users', never
 *        another admin's (and never edits another admin, whose password would open them).
 * user: only their own.
 */
enum Role: string
{
    case User = 'user';
    case Admin = 'admin';
    case Superadmin = 'superadmin';

    public function label(): string
    {
        return __("enums.role.{$this->value}");
    }

    public function tone(): string
    {
        return match ($this) {
            self::Superadmin => 'brand',
            self::Admin => 'info',
            self::User => 'neutral',
        };
    }

    /** Roles a user with this email may be given: superadmin only for the configured email. */
    public static function assignableTo(?string $email): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role) => $role !== self::Superadmin || self::isSuperadminEmail($email),
        ));
    }

    public static function isSuperadminEmail(?string $email): bool
    {
        $configured = (string) config('telegram.superadmin_email');

        return $configured !== '' && $email !== null && strcasecmp(trim($email), $configured) === 0;
    }
}
