<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Catalog;
use App\Models\User;

final class CatalogPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isSuperadmin() ? true : null;
    }

    public function view(User $user, Catalog $catalog): bool
    {
        return $catalog->user_id === $user->id || $catalog->is_global;
    }

    public function update(User $user, Catalog $catalog): bool
    {
        return $catalog->user_id === $user->id;
    }

    public function delete(User $user, Catalog $catalog): bool
    {
        return $catalog->user_id === $user->id;
    }

    /** Make catalogs global and copy them to other users. */
    public function manageAll(User $user): bool
    {
        return false;
    }
}
