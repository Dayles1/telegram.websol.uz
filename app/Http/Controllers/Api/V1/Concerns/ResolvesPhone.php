<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use App\Models\UserPhone;

/**
 * The old API names an account three ways: phone_id, phone, or telegram_user_id.
 * Only the caller's own accounts resolve (all of them for a superadmin).
 */
trait ResolvesPhone
{
    /** @return array<string, list<string>> */
    protected function phoneRules(): array
    {
        return [
            'phone_id' => ['nullable', 'integer', 'required_without_all:phone,telegram_user_id'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without_all:phone_id,telegram_user_id'],
            'telegram_user_id' => ['nullable', 'string', 'max:32', 'required_without_all:phone_id,phone'],
        ];
    }

    /** @param array<string, mixed> $input */
    protected function resolvePhone(User $user, array $input): ?UserPhone
    {
        $query = UserPhone::query()->ownedBy($user);

        return match (true) {
            ! empty($input['phone_id']) => $query->whereKey((int) $input['phone_id'])->first(),
            ! empty($input['phone']) => $query->wherePhone((string) $input['phone'])->latest('id')->first(),
            ! empty($input['telegram_user_id']) => $query->where('telegram_user_id', (string) $input['telegram_user_id'])->latest('id')->first(),
            default => null,
        };
    }

    /** The caller's own account by phone (the login endpoints never act on someone else's). */
    protected function ownPhone(User $user, string $phone): ?UserPhone
    {
        return UserPhone::query()->where('user_id', $user->id)->wherePhone($phone)->latest('id')->first();
    }
}
