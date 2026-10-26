<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MessageTask;
use App\Models\User;

final class MessageTaskPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    public function view(User $user, MessageTask $task): bool
    {
        return $task->sender?->user_id === $user->id;
    }
}
