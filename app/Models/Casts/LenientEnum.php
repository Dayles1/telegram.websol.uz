<?php

declare(strict_types=1);

namespace App\Models\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * An enum cast that does not crash on a value the enum does not know.
 *
 * Production rows were written by several generations of old code, so a status
 * column may hold a value no current enum case has. Such a value reads as the
 * fallback case instead of throwing a ValueError on every page that touches it.
 *
 * Usage: 'status' => LenientEnum::class.':'.GroupStatus::class.',pending'
 */
final class LenientEnum implements CastsAttributes
{
    /** @param class-string<BackedEnum> $enum */
    public function __construct(private readonly string $enum, private readonly string $fallback) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        return ($this->enum)::tryFrom((string) $value) ?? ($this->enum)::from($this->fallback);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : ($this->enum)::from((string) $value)->value;
    }
}
