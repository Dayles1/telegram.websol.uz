@php
    use App\Enums\EventKind;
    use App\Enums\EventLevel;

    $levels = ['problems' => __('monitoring.events.level_problems')] + collect(EventLevel::cases())->mapWithKeys(fn ($l) => [$l->value => $l->label()])->all();
    $kinds = collect(EventKind::cases())->mapWithKeys(fn ($k) => [$k->value => $k->label()])->all();
    $states = ['open' => __('monitoring.events.state_open'), 'resolved' => __('monitoring.events.state_resolved'), 'all' => __('monitoring.events.state_all')];
    $segment = 'h-8 rounded-lg px-3 text-xs font-medium whitespace-nowrap transition-colors';
    $segmentOn = 'bg-gray-900 text-white dark:bg-white dark:text-gray-900';
    $segmentOff = 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white';
@endphp

<x-layouts.app :title="__('monitoring.events.title')" section="monitoring">
    <div x-data="eventsPage({
        urls: @js([
            'data' => route('monitoring.events.data'),
            'resolve' => route('monitoring.events.resolve', '__ID__'),
            'resolveAll' => route('monitoring.events.resolve-all'),
        ]),
        t: @js(__('monitoring.events.js') + ['kinds' => $kinds]),
    })">
        <x-ui.page-header :title="__('monitoring.events.title')" :description="__('monitoring.events.description')">
            <x-slot:actions>
                <span class="c-muted inline-flex items-center gap-2 text-xs">
                    <span class="c-dot is-live text-success-500"></span> {{ __('monitoring.events.auto_refresh') }}
                </span>
                <x-ui.button variant="secondary" icon="check" @click="resolveAll()" ::disabled="busy || !openTotal">
                    <span x-text="filters.kind ? @js(__('monitoring.events.resolve_kind')).replace(':kind', @js($kinds)[filters.kind]) : @js(__('monitoring.events.resolve_all'))"></span>
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Filters --}}
        <div class="c-surface mb-4 space-y-3 p-3 sm:p-4">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <div class="relative min-w-0 flex-1">
                    <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                    <input type="search" x-model="filters.search" data-page-search class="c-input pl-9" placeholder="{{ __('monitoring.events.search') }}" autocomplete="off">
                </div>
                <div class="c-scroll-x">
                    <div class="inline-flex gap-0.5 rounded-xl border border-[var(--line-strong)] p-0.5">
                        @foreach ($levels as $value => $label)
                            <button type="button" class="{{ $segment }}" :class="filters.level === @js($value) ? @js($segmentOn) : @js($segmentOff)" @click="filters.level = @js($value)">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="c-scroll-x">
                    <div class="inline-flex gap-0.5 rounded-xl border border-[var(--line-strong)] p-0.5">
                        @foreach ($states as $value => $label)
                            <button type="button" class="{{ $segment }}" :class="filters.state === @js($value) ? @js($segmentOn) : @js($segmentOff)" @click="filters.state = @js($value)">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Kind chips (counts = open problems of that kind) --}}
            <div class="flex flex-wrap items-center gap-1.5">
                <button type="button" class="inline-flex h-7 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors"
                        :class="!filters.kind ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-[var(--line-strong)] text-gray-700 hover:border-gray-400 dark:text-gray-300'"
                        @click="filters.kind = ''">
                    {{ __('monitoring.events.all_kinds') }}
                    <span x-show="openTotal" class="tabular-nums opacity-70" x-text="openTotal"></span>
                </button>
                @foreach ($kinds as $value => $label)
                    <button type="button" class="inline-flex h-7 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors"
                            :class="filters.kind === @js($value) ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900' : 'border-[var(--line-strong)] text-gray-700 hover:border-gray-400 dark:text-gray-300'"
                            @click="filters.kind = filters.kind === @js($value) ? '' : @js($value)">
                        {{ $label }}
                        <span x-show="counts[@js($value)]" class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-error-500 px-1 text-[10px] font-semibold tabular-nums text-white" x-text="counts[@js($value)]"></span>
                    </button>
                @endforeach

                <template x-if="filters.phone_id">
                    <button type="button" class="inline-flex h-7 items-center gap-1.5 rounded-full bg-brand-50 px-3 text-xs font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" @click="filters.phone_id = ''">
                        <x-ui.icon name="phone" class="h-3 w-3" /> <span x-text="@js(__('monitoring.events.account_filter')).replace(':id', filters.phone_id)"></span> <x-ui.icon name="x" class="h-3 w-3" />
                    </button>
                </template>

                <button type="button" x-show="filtered" x-cloak class="c-muted ml-auto text-xs hover:text-gray-900 dark:hover:text-white" @click="reset()">{{ __('common.reset') }}</button>
            </div>
        </div>

        {{-- List --}}
        <section class="c-surface overflow-hidden" :class="refreshing && 'opacity-70 transition-opacity'">
            <div class="c-surface__head">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">
                    {{ __('monitoring.events.found') }} <span class="c-muted font-normal tabular-nums" x-text="$fmt.number(meta.total)"></span>
                </h2>
            </div>

            <div x-show="loading" class="space-y-3 p-4">
                <template x-for="i in 5"><div class="c-skeleton h-14"></div></template>
            </div>

            <div x-show="!loading && !events.length" x-cloak>
                <x-ui.empty icon="check-circle" :title="__('monitoring.events.empty_title')" :text="__('monitoring.events.empty_text')" />
            </div>

            <ul x-show="!loading" class="divide-y divide-[var(--line)]">
                <template x-for="e in events" :key="e.id">
                    <li class="flex flex-col gap-3 px-4 py-3.5 transition-colors hover:bg-[var(--surface-2)] sm:flex-row sm:items-start" :class="e.resolved_at && 'opacity-60'">
                        <div class="flex shrink-0 items-center gap-2 sm:w-28 sm:flex-col sm:items-start sm:gap-1">
                            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(e.tone)">
                                <span class="c-dot h-1.5 w-1.5" :class="!e.resolved_at && e.level === 'critical' && 'is-live'"></span><span x-text="e.level_label"></span>
                            </span>
                            <span class="c-muted text-xs" x-text="e.kind_label"></span>
                        </div>

                        <a :href="e.url" class="min-w-0 flex-1">
                            <p class="c-break text-[13px] font-medium text-gray-900 hover:underline dark:text-white" x-text="e.message"></p>
                            <p x-show="e.error_text && e.error_text !== e.message" class="c-break mt-0.5 text-xs text-error-700 dark:text-error-300">
                                <span x-text="e.error_text"></span>
                                <span x-show="e.error_key && e.error_key !== e.error_text" class="c-muted font-mono" x-text="'· ' + e.error_key"></span>
                            </p>
                            <p class="c-muted mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                <span x-show="e.phone" class="inline-flex items-center gap-1"><x-ui.icon name="phone" class="h-3 w-3" /><span class="font-mono" x-text="e.phone?.phone"></span></span>
                                <span x-show="e.user" class="inline-flex items-center gap-1"><x-ui.icon name="user" class="h-3 w-3" /><span x-text="e.user"></span></span>
                                <span x-show="e.group_id" class="inline-flex items-center gap-1"><x-ui.icon name="send" class="h-3 w-3" /><span x-text="'#' + e.group_id"></span></span>
                                <span x-show="e.notified_at" class="inline-flex items-center gap-1"><x-ui.icon name="bell" class="h-3 w-3" />{{ __('monitoring.events.notified') }}</span>
                            </p>
                        </a>

                        <div class="flex shrink-0 items-center gap-3 sm:flex-col sm:items-end sm:gap-1">
                            <div class="flex items-center gap-2 text-xs tabular-nums">
                                <span x-show="e.occurrences > 1" class="rounded-md bg-gray-100 px-1.5 py-0.5 font-semibold text-gray-800 dark:bg-white/[0.07] dark:text-gray-200" x-text="'×' + $fmt.number(e.occurrences)"></span>
                                <span class="c-muted" :title="$fmt.dateTime(e.last_seen_at)" x-text="$fmt.relative(e.last_seen_at)"></span>
                            </div>
                            <span x-show="e.occurrences > 1" class="c-muted text-[11px]" :title="$fmt.dateTime(e.first_seen_at)" x-text="@js(__('monitoring.events.first_seen')).replace(':time', $fmt.relative(e.first_seen_at))"></span>
                            <div class="ml-auto sm:ml-0">
                                <template x-if="!e.resolved_at && e.level !== 'info'">
                                    <x-ui.button size="sm" variant="ghost" icon="check" @click="resolve(e)" ::disabled="busy">{{ __('monitoring.events.resolve') }}</x-ui.button>
                                </template>
                                <template x-if="e.resolved_at">
                                    <span class="inline-flex items-center gap-1 text-xs text-success-600"><x-ui.icon name="check" class="h-3.5 w-3.5" /><span x-text="@js(__('monitoring.events.resolved_at')).replace(':time', $fmt.relative(e.resolved_at))"></span></span>
                                </template>
                            </div>
                        </div>
                    </li>
                </template>
            </ul>

            <x-ui.pager />
        </section>
    </div>
</x-layouts.app>
