<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserPhone;

/** An account belongs to the user who connected it; an admin also manages plain users' accounts, the superadmin all of them. */
final class UserPhonePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    public function view(User $user, UserPhone $phone): bool
    {
        return $user->oversees($phone->user);
    }

    /** Send from it, log it in or out, check it. */
    public function use(User $user, UserPhone $phone): bool
    {
        return $user->oversees($phone->user);
    }
}
