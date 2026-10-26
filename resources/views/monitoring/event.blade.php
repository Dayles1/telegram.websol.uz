@php
    $json = $event['context'] ? json_encode($event['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $phone = $event['phone'] ?? null;
@endphp

<x-layouts.app :title="__('monitoring.event.title', ['id' => $event['id']])" section="monitoring">
    <div x-data="eventsPage({
        urls: @js([
            'data' => route('monitoring.events.data'),
            'resolve' => route('monitoring.events.resolve', '__ID__'),
            'resolveAll' => route('monitoring.events.resolve-all'),
        ]),
        t: @js(__('monitoring.events.js') + ['kinds' => []]),
        event: @js($event),
    })">
        <a href="{{ route('monitoring.events') }}" class="c-muted mb-3 inline-flex items-center gap-1 text-xs hover:text-gray-900 dark:hover:text-white">
            <x-ui.icon name="chevron-left" class="h-3.5 w-3.5" /> {{ __('monitoring.event.back') }}
        </a>

        <x-ui.page-header :title="$event['message']">
            <x-slot:eyebrow>
                <span class="inline-flex items-center gap-2 normal-case tracking-normal">
                    <x-ui.badge :tone="$event['tone']" dot>{{ $event['level_label'] }}</x-ui.badge>
                    <span>{{ $event['kind_label'] }}</span>
                    <span class="font-mono">#{{ $event['id'] }}</span>
                </span>
            </x-slot:eyebrow>
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="filter" :href="route('monitoring.events', ['kind' => $event['kind'], 'state' => 'all', 'level' => $event['level'] === 'info' ? 'info' : 'problems'])">{{ __('monitoring.event.similar') }}</x-ui.button>
                @if ($event['level'] !== 'info')
                    <template x-if="!event.resolved_at">
                        <x-ui.button variant="primary" icon="check" @click="resolve(event)" ::disabled="busy">{{ __('monitoring.events.resolve') }}</x-ui.button>
                    </template>
                    <template x-if="event.resolved_at">
                        <x-ui.badge tone="success"><x-ui.icon name="check" class="h-3.5 w-3.5" /> {{ __('monitoring.event.resolved') }}</x-ui.badge>
                    </template>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="min-w-0 space-y-5">
                @if ($event['error_key'])
                    <div class="rounded-2xl border border-error-200 bg-error-50 px-4 py-3 dark:border-error-500/30 dark:bg-error-500/10">
                        <div class="c-eyebrow !text-error-700 dark:!text-error-300">{{ __('monitoring.event.error') }}</div>
                        <p class="c-break mt-1 text-sm font-medium text-error-800 dark:text-error-200">{{ $event['error_text'] }}</p>
                        @if ($event['error_text'] !== $event['error_key'])
                            <p class="mt-1 font-mono text-xs text-error-700/80 dark:text-error-300/80">{{ $event['error_key'] }}</p>
                        @endif
                    </div>
                @endif

                <x-ui.surface :title="__('monitoring.event.context')" :padded="false">
                    @if ($json)
                        <x-slot:actions>
                            <x-ui.button size="sm" variant="ghost" icon="copy" @click="$copy($refs.json.textContent)">{{ __('monitoring.copy') }}</x-ui.button>
                        </x-slot:actions>
                        <pre x-ref="json" class="c-break max-h-[60vh] overflow-auto whitespace-pre-wrap bg-[var(--surface-2)] p-4 font-mono text-xs leading-relaxed text-gray-800 dark:text-gray-200">{{ $json }}</pre>
                    @else
                        <p class="c-muted px-4 py-8 text-center text-[13px]">{{ __('monitoring.event.no_context') }}</p>
                    @endif
                </x-ui.surface>
            </div>

            <aside class="min-w-0">
                <x-ui.surface :title="__('monitoring.event.details')" :padded="false">
                    <dl class="divide-y divide-[var(--line)] text-[13px]">
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="c-muted">{{ __('monitoring.event.occurrences') }}</dt>
                            <dd class="font-semibold tabular-nums text-gray-900 dark:text-white">×{{ number_format($event['occurrences']) }}</dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="c-muted">{{ __('monitoring.event.first_seen') }}</dt>
                            <dd class="text-right"><span class="text-gray-900 dark:text-white" x-text="$fmt.dateTime(event.first_seen_at)"></span> <span class="c-muted block text-xs" x-text="$fmt.relative(event.first_seen_at)"></span></dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="c-muted">{{ __('monitoring.event.last_seen') }}</dt>
                            <dd class="text-right"><span class="text-gray-900 dark:text-white" x-text="$fmt.dateTime(event.last_seen_at)"></span> <span class="c-muted block text-xs" x-text="$fmt.relative(event.last_seen_at)"></span></dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="c-muted">{{ __('monitoring.event.state') }}</dt>
                            <dd class="text-right">
                                <span x-show="event.resolved_at" class="text-success-600" x-text="@js(__('monitoring.events.resolved_at')).replace(':time', $fmt.dateTime(event.resolved_at))"></span>
                                <span x-show="!event.resolved_at" class="{{ $event['level'] === 'info' ? 'c-muted' : 'text-error-600' }}">{{ $event['level'] === 'info' ? __('monitoring.event.journal_entry') : __('monitoring.events.state_open') }}</span>
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="c-muted">{{ __('monitoring.event.notified') }}</dt>
                            <dd class="text-right text-gray-900 dark:text-white" x-text="event.notified_at ? $fmt.dateTime(event.notified_at) : @js(__('common.no'))"></dd>
                        </div>
                        @if ($phone)
                            <div class="px-4 py-2.5">
                                <div class="flex justify-between gap-4">
                                    <dt class="c-muted">{{ __('monitoring.account') }}</dt>
                                    <dd class="font-mono text-gray-900 dark:text-white">{{ $phone['phone'] }}</dd>
                                </div>
                                <div class="mt-1.5 flex flex-wrap justify-end gap-x-3 gap-y-1 text-xs">
                                    <a href="{{ route('monitoring.events', ['phone_id' => $phone['id'], 'state' => 'all']) }}" class="font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('monitoring.event.account_events') }} →</a>
                                    <a href="{{ route('phones.index') }}" class="font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('monitoring.event.account_page') }} →</a>
                                </div>
                            </div>
                        @endif
                        @if ($event['user'] ?? null)
                            <div class="flex justify-between gap-4 px-4 py-2.5">
                                <dt class="c-muted">{{ __('monitoring.event.user') }}</dt>
                                <dd class="text-gray-900 dark:text-white">{{ $event['user'] }}</dd>
                            </div>
                        @endif
                        @if ($event['group_url'])
                            <div class="flex justify-between gap-4 px-4 py-2.5">
                                <dt class="c-muted">{{ __('monitoring.event.send') }}</dt>
                                <dd><a href="{{ $event['group_url'] }}" class="font-medium text-brand-600 hover:underline dark:text-brand-300">#{{ $event['group_id'] }} →</a></dd>
                            </div>
                        @endif
                    </dl>
                </x-ui.surface>
            </aside>
        </div>
    </div>
</x-layouts.app>
