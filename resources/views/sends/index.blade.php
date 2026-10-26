@php
    $statuses = [
        '' => __('sends.status_all'),
        'active' => __('sends.status_active'),
        'pending' => __('enums.group_status.pending'),
        'sending' => __('enums.group_status.sending'),
        'completed' => __('enums.group_status.completed'),
        'failed' => __('enums.group_status.failed'),
        'canceled' => __('enums.group_status.canceled'),
    ];
@endphp

<x-layouts.app :title="__('sends.title')">
    <div x-data="sendsPage({
        url: @js(route('sends.data')),
        stopAllUrl: @js(route('sends.cancel-all')),
        phones: @js($phones->pluck('phone', 'id')),
        t: @js(['every' => __('sends.js.every'), 'units' => __('sends.js.units'), 'file_removed' => __('sends.file_removed'), 'stop_all' => __('sends.stop_all')]
            + \Illuminate\Support\Arr::only(__('sends.js'), ['stop_all_title', 'stop_all_title_account', 'stop_all_body', 'stop_all_loops', 'stop_all_tasks'])),
    })">
        <x-ui.page-header :title="__('sends.title')" :description="__('sends.description')">
            <x-slot:actions>
                <span x-show="activeCount" x-cloak class="inline-flex items-center gap-2 text-[13px] font-medium text-brand-700 dark:text-brand-300">
                    <span class="c-dot is-live text-brand-500"></span>
                    <span x-text="@js(__('sends.active_now')).replace(':count', activeCount)"></span>
                </span>
                <x-ui.button x-show="canStopAll" x-cloak variant="danger-ghost" icon="stop" @click="stopAll()" ::disabled="stopping">{{ __('sends.stop_all') }}</x-ui.button>
                <x-ui.button :href="route('send.create')" variant="primary" icon="send">{{ __('sends.new_send') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Filters --}}
        <div class="c-surface mb-4 p-3">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                <label class="relative block min-w-0 flex-1">
                    <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                    <input type="search" data-page-search class="c-input pl-9 pr-10" x-model="filters.search" @input="searchChanged()"
                           @keydown.enter.prevent="apply()" placeholder="{{ __('sends.search_placeholder') }}" aria-label="{{ __('common.search') }}">
                    <span class="c-kbd absolute right-2.5 top-1/2 hidden -translate-y-1/2 sm:inline-flex">/</span>
                </label>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:flex lg:items-center">
                    <select class="c-input lg:w-48" x-model="filters.phone_id" @change="apply()" aria-label="{{ __('sends.col_account') }}">
                        <option value="">{{ __('sends.all_phones') }}</option>
                        @foreach ($phones as $phone)
                            <option value="{{ $phone->id }}">{{ $phone->phone }}</option>
                        @endforeach
                    </select>
                    <input type="date" class="c-input lg:w-40" x-model="filters.from" @change="apply()" :max="filters.to || null"
                           title="{{ __('sends.date_from') }}" aria-label="{{ __('sends.date_from') }}">
                    <input type="date" class="c-input lg:w-40" x-model="filters.to" @change="apply()" :min="filters.from || null"
                           title="{{ __('sends.date_to') }}" aria-label="{{ __('sends.date_to') }}">
                </div>
            </div>

            <div class="mt-3 flex items-center gap-2 overflow-x-auto pb-0.5" style="scrollbar-width:none">
                @foreach ($statuses as $value => $label)
                    <button type="button" @click="setStatus(@js($value))"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full border px-3 text-xs font-medium transition-colors"
                            :class="filters.status === @js($value)
                                ? 'border-gray-900 bg-gray-900 text-white dark:border-white dark:bg-white dark:text-gray-900'
                                : 'border-[var(--line-strong)] text-gray-600 hover:border-gray-400 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white'">
                        @if ($value === 'active')<span class="c-dot is-live h-1.5 w-1.5 text-brand-500"></span>@endif
                        {{ $label }}
                    </button>
                @endforeach
                <button type="button" x-show="filtered" x-cloak @click="reset()" class="c-muted ml-auto inline-flex shrink-0 items-center gap-1 text-xs hover:text-gray-900 dark:hover:text-white">
                    <x-ui.icon name="x" class="h-3.5 w-3.5" /> {{ __('sends.reset_filters') }}
                </button>
            </div>
        </div>

        <section class="c-surface overflow-hidden">
            {{-- Loading --}}
            <div x-show="loading && !groups.length" class="space-y-3 p-4">
                <template x-for="i in 5"><div class="c-skeleton h-12"></div></template>
            </div>

            {{-- Empty --}}
            <div x-show="!loading && !groups.length" x-cloak>
                <template x-if="filtered">
                    <x-ui.empty icon="filter" :title="__('sends.empty_filtered')" :text="__('sends.empty_filtered_text')">
                        <x-ui.button variant="secondary" icon="x" @click="reset()">{{ __('sends.reset_filters') }}</x-ui.button>
                    </x-ui.empty>
                </template>
                <template x-if="!filtered">
                    <x-ui.empty icon="send" :title="__('sends.empty_title')" :text="__('sends.empty_text')">
                        <x-ui.button :href="route('send.create')" variant="primary" icon="send">{{ __('sends.new_send') }}</x-ui.button>
                    </x-ui.empty>
                </template>
            </div>

            {{-- Desktop: table --}}
            <div x-show="groups.length" class="hidden overflow-x-auto md:block" :class="loading && 'opacity-60 transition-opacity'">
                <table class="c-table">
                    <thead>
                        <tr>
                            <th class="w-36">{{ __('sends.col_status') }}</th>
                            <th>{{ __('sends.col_message') }}</th>
                            <th class="w-48">{{ __('sends.col_account') }}</th>
                            <th class="w-60">{{ __('sends.col_progress') }}</th>
                            <th class="w-36 text-right">{{ __('sends.col_created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="g in groups" :key="g.id">
                            <tr class="c-row cursor-pointer" @click="window.location.href = g.url">
                                <td>
                                    <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(g.tone)">
                                        <span class="c-dot h-1.5 w-1.5" :class="g.active && 'is-live'"></span><span x-text="g.status_label"></span>
                                    </span>
                                    <div class="c-muted mt-1 font-mono text-[11px]" x-text="'#' + g.id"></div>
                                </td>
                                <td class="max-w-0">
                                    <div class="flex min-w-0 items-center gap-1.5">
                                        <span x-show="g.file" class="c-muted shrink-0" :title="fileTitle(g.file)" :aria-label="fileTitle(g.file)">
                                            <x-ui.icon name="paperclip" class="h-3.5 w-3.5" />
                                        </span>
                                        <a :href="g.url" class="block truncate font-medium text-gray-900 hover:underline dark:text-white" x-text="g.text || g.file?.name || '—'" @click.stop></a>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span x-show="g.loop" class="inline-flex items-center gap-1 rounded px-1.5 py-px text-[11px] font-medium" :class="$fmt.tone(g.loop?.active ? 'brand' : 'neutral')" :title="g.loop?.status_label">
                                            <x-ui.icon name="repeat" class="h-3 w-3" /><span class="tabular-nums" x-text="g.loop ? every(g.loop.every_minutes) : ''"></span>
                                        </span>
                                        <template x-for="c in (g.catalogs ?? []).slice(0, 3)" :key="c.id">
                                            <span class="rounded bg-gray-100 px-1.5 py-px text-[11px] text-gray-600 dark:bg-white/[0.06] dark:text-gray-300" x-text="c.title"></span>
                                        </template>
                                        <span x-show="(g.catalogs ?? []).length > 3" class="c-muted text-[11px]" x-text="'+' + ((g.catalogs ?? []).length - 3)"></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="font-mono text-xs text-gray-900 dark:text-white" x-text="g.phone?.phone ?? '—'"></div>
                                    <div class="c-muted truncate text-xs" x-text="g.phone?.owner ?? g.creator ?? ''"></div>
                                </td>
                                <td>
                                    <div class="flex items-baseline justify-between gap-2 text-xs tabular-nums">
                                        <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(g.counts.sent) + ' / ' + $fmt.number(g.counts.total)"></span>
                                        <span>
                                            <span x-show="g.counts.failed" class="text-error-600 dark:text-error-400" x-text="'✕ ' + $fmt.number(g.counts.failed)"></span>
                                            <span x-show="g.active && g.counts.pending" class="c-muted ml-1.5" x-text="'◷ ' + $fmt.number(g.counts.pending)"></span>
                                        </span>
                                    </div>
                                    <div class="c-progress mt-1.5" :class="g.active && 'is-live'">
                                        <span class="bg-brand-500" :style="`width:${share('sent', g)}%`"></span>
                                        <span class="bg-error-400" :style="`width:${share('failed', g)}%`"></span>
                                        <span class="bg-gray-300 dark:bg-gray-600" :style="`width:${share('canceled', g)}%`"></span>
                                    </div>
                                </td>
                                <td class="text-right">
                                    <div class="text-xs text-gray-900 dark:text-white" x-text="$fmt.relative(g.created_at)"></div>
                                    <div class="c-muted text-[11px] tabular-nums" x-text="$fmt.dateTime(g.created_at)"></div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Mobile: rows --}}
            <div x-show="groups.length" class="divide-y divide-[var(--line)] md:hidden">
                <template x-for="g in groups" :key="g.id"><x-ui.group-row /></template>
            </div>

            <x-ui.pager />
        </section>
    </div>
</x-layouts.app>
