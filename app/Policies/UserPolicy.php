<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Admins and the superadmin manage users. An admin changes only plain users and themselves:
 * editing another admin (their password, their role) would reach that admin's accounts.
 * Nobody deletes the superadmin or themselves.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->seesOthers();
    }

    public function create(User $user): bool
    {
        return $user->seesOthers();
    }

    public function update(User $user, User $target): bool
    {
        return $user->seesOthers() && $user->oversees($target);
    }

    public function delete(User $user, User $target): bool
    {
        return $this->update($user, $target) && ! $target->isSuperadmin() && $user->id !== $target->id;
    }
}
