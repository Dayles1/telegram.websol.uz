<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EventLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'send_delay_min' => ['required', 'integer', 'min:0', 'max:600'],
            'send_delay_max' => ['required', 'integer', 'min:0', 'max:600', 'gte:send_delay_min'],
            'daily_limit_per_phone' => ['required', 'integer', 'min:0', 'max:100000'],
            'flood_wait_inline_max' => ['required', 'integer', 'min:0', 'max:3600'],
            'flood_wait_jitter' => ['required', 'integer', 'min:0', 'max:3600'],
            'peer_flood_rest_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'alerts_enabled' => ['boolean'],
            'alert_min_level' => ['required', Rule::enum(EventLevel::class)],
            'alert_repeat_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return array_map(
            fn ($value) => is_numeric($value) ? (int) $value : $value,
            ['alerts_enabled' => $this->boolean('alerts_enabled')] + $this->validated(),
        );
    }
}
