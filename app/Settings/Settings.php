<?php

declare(strict_types=1);

namespace App\Settings;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime settings a superadmin edits on the settings page.
 * Defaults come from config('telegram.defaults'); app_settings rows override them.
 * Workers read through a short cache, so a change reaches them within a minute.
 */
final class Settings
{
    private const CACHE_KEY = 'app_settings';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 60, fn () => AppSetting::query()->pluck('value', 'key')->all());

        return array_replace(config('telegram.defaults'), array_intersect_key($stored, config('telegram.defaults')));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /** @param array<string, mixed> $values */
    public function update(array $values, ?int $userId = null): void
    {
        foreach (array_intersect_key($values, config('telegram.defaults')) as $key => $value) {
            AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $userId]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
