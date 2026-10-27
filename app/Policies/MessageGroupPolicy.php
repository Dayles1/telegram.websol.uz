<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MessageGroup;
use App\Models\User;

/** A send is visible to whoever created it and to whoever oversees the account it went from (User::oversees). */
final class MessageGroupPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    public function view(User $user, MessageGroup $group): bool
    {
        return $group->created_by === $user->id || $user->oversees($group->phone?->user);
    }

    public function cancel(User $user, MessageGroup $group): bool
    {
        return $this->view($user, $group);
    }
}
