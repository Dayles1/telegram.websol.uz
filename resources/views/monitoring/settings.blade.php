@php
    use App\Enums\EventLevel;

    $value = fn (string $key) => old($key, $settings[$key] ?? null);

    // group => [field => [unit, min, max, alpine model]]
    $groups = [
        'pacing' => [
            'send_delay_min' => ['seconds', 0, 600, 'min'],
            'send_delay_max' => ['seconds', 0, 600, 'max'],
        ],
        'limits' => [
            'daily_limit_per_phone' => ['messages', 0, 100000, 'limit'],
        ],
        'flood' => [
            'flood_wait_inline_max' => ['seconds', 0, 3600, null],
            'flood_wait_jitter' => ['seconds', 0, 3600, null],
            'peer_flood_rest_hours' => ['hours', 1, 720, null],
        ],
    ];
    $icons = ['pacing' => 'clock', 'limits' => 'shield', 'flood' => 'pause', 'alerts' => 'bell'];
@endphp

<x-layouts.app :title="__('settings.title')" section="monitoring">
    <div x-data="settingsPage({
        urls: @js(['testAlert' => route('monitoring.settings.test-alert'), 'sweep' => route('monitoring.settings.sweep')]),
        values: @js(collect(array_keys($settings))->mapWithKeys(fn ($k) => [$k => $value($k)])),
        t: @js(__('settings.js')),
        configured: @js($alertsConfigured),
    })">
        <x-ui.page-header :title="__('settings.title')" :description="__('settings.description')" />

        @if ($errors->any())
            <div class="mb-5 rounded-2xl border border-error-200 bg-error-50 px-4 py-3 text-[13px] text-error-700 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-300">
                {{ __('settings.fix_errors') }}
            </div>
        @endif

        <form method="POST" action="{{ route('monitoring.settings.update') }}" class="space-y-5">
            @csrf
            @method('PUT')

            @foreach ($groups as $group => $fields)
                <section class="c-surface grid gap-4 p-4 sm:p-5 lg:grid-cols-[280px_minmax(0,1fr)] lg:gap-8">
                    <div>
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><x-ui.icon :name="$icons[$group]" class="c-muted" /> {{ __("settings.groups.{$group}.title") }}</h2>
                        <p class="c-muted mt-1 text-[13px] leading-relaxed">{{ __("settings.groups.{$group}.text") }}</p>
                    </div>
                    <div class="min-w-0 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            @foreach ($fields as $key => [$unit, $min, $max, $model])
                                <x-ui.field :label="__('settings.fields.'.$key.'.label')" :for="$key" :hint="__('settings.fields.'.$key.'.hint')" :error="$key">
                                    <div class="relative">
                                        <input type="number" id="{{ $key }}" name="{{ $key }}" value="{{ $value($key) }}" min="{{ $min }}" max="{{ $max }}" step="1" required
                                               class="c-input pr-20 tabular-nums" {{ $model ? 'x-model.number='.$model : '' }} aria-invalid="{{ $errors->has($key) ? 'true' : 'false' }}">
                                        <span class="c-muted pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs">{{ __("settings.units.{$unit}") }}</span>
                                    </div>
                                </x-ui.field>
                            @endforeach
                        </div>
                        @if ($group === 'pacing')
                            <p class="flex items-start gap-2 rounded-xl px-3 py-2 text-xs" :class="max < min ? 'bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-300' : 'bg-brand-50 text-brand-800 dark:bg-brand-500/10 dark:text-brand-200'">
                                <x-ui.icon name="info" class="mt-px h-3.5 w-3.5" /><span x-text="pacingText"></span>
                            </p>
                            <p class="c-muted flex items-start gap-2 text-xs">
                                <x-ui.icon name="server" class="mt-px h-3.5 w-3.5" /><span>{{ __('settings.parallel_hint', ['queue' => config('telegram.send_queue')]) }}</span>
                            </p>
                        @endif
                    </div>
                </section>
            @endforeach

            {{-- Alerts --}}
            <section id="alerts" class="c-surface grid scroll-mt-28 gap-4 p-4 sm:p-5 lg:grid-cols-[280px_minmax(0,1fr)] lg:gap-8">
                <div>
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white"><x-ui.icon name="bell" class="c-muted" /> {{ __('settings.groups.alerts.title') }}</h2>
                    <p class="c-muted mt-1 text-[13px] leading-relaxed">{{ __('settings.groups.alerts.text') }}</p>
                    <div class="mt-3">
                        @if ($alertsConfigured)
                            <x-ui.badge tone="success" dot>{{ __('settings.bot_configured') }}</x-ui.badge>
                        @else
                            <x-ui.badge tone="warning" dot>{{ __('settings.bot_missing') }}</x-ui.badge>
                        @endif
                    </div>
                </div>
                <div class="min-w-0 space-y-4">
                    <label class="flex items-start gap-3">
                        <input type="hidden" name="alerts_enabled" value="0">
                        <input type="checkbox" name="alerts_enabled" value="1" class="c-check mt-0.5" x-model="alertsEnabled" @checked($value('alerts_enabled'))>
                        <span>
                            <span class="block text-[13px] font-semibold text-gray-800 dark:text-gray-200">{{ __('settings.fields.alerts_enabled.label') }}</span>
                            <span class="c-muted block text-xs">{{ __('settings.fields.alerts_enabled.hint') }}</span>
                        </span>
                    </label>
                    <div class="grid gap-4 sm:grid-cols-2" :class="!alertsEnabled && 'opacity-60'">
                        <x-ui.field :label="__('settings.fields.alert_min_level.label')" for="alert_min_level" :hint="__('settings.fields.alert_min_level.hint')" error="alert_min_level">
                            <select id="alert_min_level" name="alert_min_level" class="c-input">
                                @foreach (EventLevel::cases() as $level)
                                    <option value="{{ $level->value }}" @selected($value('alert_min_level') === $level->value)>{{ $level->label() }}</option>
                                @endforeach
                            </select>
                        </x-ui.field>
                        <x-ui.field :label="__('settings.fields.alert_repeat_minutes.label')" for="alert_repeat_minutes" :hint="__('settings.fields.alert_repeat_minutes.hint')" error="alert_repeat_minutes">
                            <div class="relative">
                                <input type="number" id="alert_repeat_minutes" name="alert_repeat_minutes" value="{{ $value('alert_repeat_minutes') }}" min="1" max="10080" step="1" required
                                       class="c-input pr-20 tabular-nums" aria-invalid="{{ $errors->has('alert_repeat_minutes') ? 'true' : 'false' }}">
                                <span class="c-muted pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs">{{ __('settings.units.minutes') }}</span>
                            </div>
                        </x-ui.field>
                    </div>
                </div>
            </section>

            <div class="sticky bottom-0 z-10 -mx-4 flex items-center justify-end gap-3 border-t border-[var(--line)] bg-[var(--canvas)]/90 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-2xl sm:border">
                <span class="c-muted mr-auto hidden text-xs sm:inline">{{ __('settings.applies_within') }}</span>
                <x-ui.button type="submit" variant="primary" icon="check">{{ __('common.save') }}</x-ui.button>
            </div>
        </form>

        <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
            {{-- Alerts bot setup --}}
            <x-ui.surface :title="__('settings.guide.title')">
                <x-slot:actions>
                    <x-ui.button size="sm" variant="secondary" icon="send" @click="testAlert()" ::disabled="busy || !configured">{{ __('settings.test_alert') }}</x-ui.button>
                </x-slot:actions>
                @unless ($alertsConfigured)
                    <p class="mb-4 rounded-xl bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">{{ __('settings.guide.not_configured') }}</p>
                @endunless
                <ol class="space-y-4 text-[13px]">
                    @foreach (['bot', 'start', 'chat_id', 'env', 'apply'] as $step)
                        <li class="flex gap-3">
                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-[var(--line-strong)] text-[11px] font-semibold tabular-nums">{{ $loop->iteration }}</span>
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <p class="font-medium text-gray-900 dark:text-white">{{ __("settings.guide.{$step}.title") }}</p>
                                <p class="c-muted">{{ __("settings.guide.{$step}.text") }}</p>
                                @if ($step === 'chat_id')
                                    <code class="c-code c-break block px-2 py-1.5">https://api.telegram.org/bot&lt;token&gt;/getUpdates</code>
                                    <p class="c-muted text-xs">{!! __('settings.guide.chat_id.where', ['path' => '<code class="c-code">result[0].message.chat.id</code>']) !!}</p>
                                @elseif ($step === 'env')
                                    <div class="relative">
                                        <pre class="c-code block whitespace-pre px-3 py-2 leading-relaxed" x-ref="env">TELEGRAM_ALERT_BOT_TOKEN=123456789:AAH...
TELEGRAM_ALERT_CHAT_ID=-1001234567890</pre>
                                        <button type="button" class="c-muted absolute right-2 top-2 hover:text-gray-900 dark:hover:text-white" @click="$copy($refs.env.textContent)" title="{{ __('monitoring.copy') }}"><x-ui.icon name="copy" class="h-3.5 w-3.5" /></button>
                                    </div>
                                @elseif ($step === 'apply')
                                    <code class="c-code inline-block px-2 py-1">php artisan config:clear</code>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.surface>

            {{-- Maintenance --}}
            <x-ui.surface :title="__('settings.maintenance.title')">
                <p class="c-muted text-[13px] leading-relaxed">{{ __('settings.maintenance.text') }}</p>
                <ul class="c-muted mt-3 list-disc space-y-1 pl-5 text-xs">
                    @foreach (__('settings.maintenance.items') as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
                <x-ui.button class="mt-4" variant="secondary" icon="refresh" @click="sweep()" ::disabled="sweeping">
                    <span x-show="!sweeping">{{ __('settings.maintenance.run') }}</span>
                    <span x-show="sweeping" x-cloak>{{ __('settings.maintenance.running') }}</span>
                </x-ui.button>
            </x-ui.surface>
        </div>
    </div>
</x-layouts.app>
