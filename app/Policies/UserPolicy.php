<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/** Only a superadmin manages users, and nobody deletes a superadmin or themselves. */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, User $target): bool
    {
        return $user->isSuperadmin() && ! $target->isSuperadmin() && $user->id !== $target->id;
    }
}
