<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SendLoop;
use App\Models\User;

/** A repeating send belongs to the owner of its account and to whoever created it. */
final class SendLoopPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    public function view(User $user, SendLoop $loop): bool
    {
        return $loop->created_by === $user->id || $user->oversees($loop->phone?->user);
    }

    public function stop(User $user, SendLoop $loop): bool
    {
        return $this->view($user, $loop);
    }
}
