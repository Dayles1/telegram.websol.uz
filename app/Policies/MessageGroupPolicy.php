<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MessageGroup;
use App\Models\User;

/** A send is visible to the owner of the account it went from and to whoever created it. */
final class MessageGroupPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    public function view(User $user, MessageGroup $group): bool
    {
        return $group->created_by === $user->id || $group->phone?->user_id === $user->id;
    }

    public function cancel(User $user, MessageGroup $group): bool
    {
        return $this->view($user, $group);
    }
}
