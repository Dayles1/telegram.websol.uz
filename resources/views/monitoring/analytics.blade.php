@php
    $periods = ['today' => __('monitoring.analytics.period_today'), '7d' => __('monitoring.analytics.period_7d'), '30d' => __('monitoring.analytics.period_30d'), '90d' => __('monitoring.analytics.period_90d')];
    $segment = 'h-8 rounded-lg px-3 text-xs font-medium whitespace-nowrap transition-colors';
    $segmentOn = 'bg-gray-900 text-white dark:bg-white dark:text-gray-900';
    $segmentOff = 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white';
@endphp

<x-layouts.app :title="__('monitoring.analytics.title')" section="monitoring">
    <div x-data="analyticsPage({
        urls: @js(['data' => route('monitoring.analytics.data'), 'events' => route('monitoring.events')]),
        t: @js(__('monitoring.analytics.js')),
    })">
        <x-ui.page-header :title="__('monitoring.analytics.title')" :description="__('monitoring.analytics.description')" />

        {{-- Filters --}}
        <div class="c-surface mb-5 flex flex-col gap-3 p-3 sm:p-4 lg:flex-row lg:items-center">
            <div class="c-scroll-x">
                <div class="inline-flex gap-0.5 rounded-xl border border-[var(--line-strong)] p-0.5">
                    @foreach ($periods as $value => $label)
                        <button type="button" class="{{ $segment }}" :class="period === @js($value) ? @js($segmentOn) : @js($segmentOff)" @click="period = @js($value)">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <div class="grid flex-1 gap-3 sm:grid-cols-2 lg:max-w-xl">
                <select x-model="userId" class="c-input h-9" aria-label="{{ __('monitoring.analytics.user') }}">
                    <option value="">{{ __('monitoring.analytics.all_users') }}</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                <select x-model="phoneId" class="c-input h-9" aria-label="{{ __('monitoring.account') }}">
                    <option value="">{{ __('monitoring.analytics.all_accounts') }}</option>
                    <template x-for="p in phoneOptions" :key="p.id">
                        <option :value="p.id" :selected="String(p.id) === String(phoneId)" x-text="p.phone + (p.owner ? ' · ' + p.owner : '')"></option>
                    </template>
                </select>
            </div>
            <div class="c-muted flex items-center gap-2 text-xs lg:ml-auto">
                <span x-show="refreshing" class="c-dot is-live text-brand-500"></span>
                <span x-show="data.from" x-text="$fmt.dateTime(data.from).split(',')[0] + ' — ' + $fmt.dateTime(data.to).split(',')[0]"></span>
            </div>
        </div>

        <p x-show="failed" x-cloak class="mb-5 rounded-2xl bg-error-50 px-4 py-3 text-[13px] text-error-700 dark:bg-error-500/10 dark:text-error-300">{{ __('monitoring.analytics.load_failed') }}</p>

        {{-- KPIs --}}
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
            <template x-for="k in kpis" :key="k.key">
                <div class="c-surface p-4" :class="k.key === 'rate' && 'col-span-2 md:col-span-1'">
                    <div class="c-eyebrow" x-text="k.label"></div>
                    <div x-show="loading" class="c-skeleton mt-2 h-7 w-20"></div>
                    <div x-show="!loading" class="mt-2 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 dark:text-white" x-text="k.value"></div>
                    <div class="mt-2 flex items-center gap-1.5 text-xs">
                        <span x-show="k.delta" class="font-semibold tabular-nums" :class="deltaTone(k)" x-text="k.delta?.text"></span>
                        <span class="c-muted" x-text="k.delta ? @js(__('monitoring.analytics.vs_previous')) : @js(__('monitoring.analytics.no_previous'))"></span>
                    </div>
                </div>
            </template>
        </div>

        {{-- Volume --}}
        <x-ui.surface :title="__('monitoring.analytics.volume')" class="mb-5">
            <x-slot:actions>
                <span class="c-muted flex gap-3 text-xs">
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-brand-500"></span>{{ __('monitoring.delivered') }}</span>
                    <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-error-400"></span>{{ __('monitoring.errors') }}</span>
                </span>
            </x-slot:actions>
            <div x-show="loading" class="c-skeleton h-[200px]"></div>
            <div x-show="!loading" x-cloak>
                <template x-if="hourly"><x-ui.bar-chart series="data.series" labels="hour" :height="200" /></template>
                <template x-if="!hourly"><x-ui.bar-chart series="data.series" labels="day" :height="200" /></template>
                <p x-show="peak && (peak.sent + peak.failed)" class="c-muted mt-3 text-xs">
                    {{ __('monitoring.analytics.peak') }}: <span class="font-medium text-gray-800 dark:text-gray-200" x-text="peak?.bucket"></span>
                    · <span class="tabular-nums" x-text="$fmt.number((peak?.sent ?? 0) + (peak?.failed ?? 0))"></span>
                </p>
            </div>
        </x-ui.surface>

        <div class="mb-5 grid gap-5 xl:grid-cols-2">
            {{-- Why messages fail --}}
            <x-ui.surface :title="__('monitoring.analytics.why_fail')">
                <x-slot:actions>
                    <span class="c-muted text-xs tabular-nums" x-show="totals.failed" x-text="@js(__('monitoring.analytics.failures_total')).replace(':n', $fmt.number(totals.failed))"></span>
                </x-slot:actions>
                <div x-show="!loading && !data.errors.length" x-cloak>
                    <x-ui.empty icon="check-circle" :title="__('monitoring.analytics.no_failures')" class="!py-8" />
                </div>
                <ul class="space-y-3">
                    <template x-for="e in data.errors" :key="e.error_key">
                        <li>
                            <div class="mb-1 flex items-baseline justify-between gap-3 text-[13px]">
                                <span class="c-break min-w-0 text-gray-900 dark:text-white" :title="e.error_key" x-text="e.text || e.error_key"></span>
                                <span class="shrink-0 tabular-nums">
                                    <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(e.count)"></span>
                                    <span class="c-muted text-xs" x-text="$fmt.percent(e.count, errorsTotal)"></span>
                                </span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-full rounded-full bg-error-400 transition-[width] duration-300" :style="`width:${e.count / errorsMax * 100}%`"></div>
                            </div>
                        </li>
                    </template>
                </ul>
            </x-ui.surface>

            {{-- Per user --}}
            <section class="c-surface min-w-0 overflow-hidden">
                <div class="c-surface__head">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.analytics.by_user') }}</h2>
                    <span class="c-muted text-xs">{{ __('monitoring.analytics.click_to_filter') }}</span>
                </div>
                <div x-show="!loading && !data.users.length" x-cloak><x-ui.empty icon="users" :title="__('monitoring.analytics.no_data')" class="!py-8" /></div>
                <div x-show="data.users.length" class="c-scroll-x max-h-[420px] overflow-y-auto">
                    <table class="c-table">
                        <thead>
                            <tr>
                                @foreach (['name' => __('monitoring.analytics.user'), 'groups' => __('monitoring.analytics.sends'), 'sent' => __('monitoring.delivered'), 'failed' => __('monitoring.errors'), 'rate' => __('monitoring.analytics.rate')] as $key => $label)
                                    <th class="{{ $key === 'name' ? '' : 'text-right' }}">
                                        <button type="button" class="inline-flex items-center gap-1 uppercase hover:text-gray-900 dark:hover:text-white" @click="sortBy('users', @js($key))">{{ $label }} <span x-text="arrow('users', @js($key))"></span></button>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="u in sorted('users')" :key="u.user_id">
                                <tr class="c-row cursor-pointer" @click="pickUser(u)" :class="String(u.user_id) === String(userId) && 'bg-brand-50/60 dark:bg-brand-500/10'">
                                    <td class="font-medium text-gray-900 dark:text-white" x-text="u.name"></td>
                                    <td class="text-right tabular-nums" x-text="$fmt.number(u.groups)"></td>
                                    <td class="text-right tabular-nums" x-text="$fmt.number(u.sent)"></td>
                                    <td class="text-right tabular-nums" :class="u.failed && 'text-error-600'" x-text="$fmt.number(u.failed)"></td>
                                    <td class="text-right font-semibold tabular-nums" :class="rateTone(u.rate)" x-text="u.rate === null ? '—' : u.rate.toFixed(1) + '%'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Per account --}}
        <section class="c-surface overflow-hidden">
            <div class="c-surface__head">
                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.analytics.by_account') }}</h2>
                <span class="c-muted text-xs">{{ __('monitoring.analytics.click_to_filter') }}</span>
            </div>
            <div x-show="!loading && !data.phones.length" x-cloak><x-ui.empty icon="phone" :title="__('monitoring.analytics.no_data')" class="!py-8" /></div>
            <div x-show="data.phones.length" class="c-scroll-x">
                <table class="c-table">
                    <thead>
                        <tr>
                            @foreach (['phone' => __('monitoring.account'), 'owner' => __('monitoring.analytics.owner'), 'sent' => __('monitoring.delivered'), 'failed' => __('monitoring.errors'), 'rate' => __('monitoring.analytics.rate'), 'flood' => __('monitoring.analytics.flood')] as $key => $label)
                                <th class="{{ in_array($key, ['phone', 'owner'], true) ? '' : 'text-right' }}">
                                    <button type="button" class="inline-flex items-center gap-1 uppercase hover:text-gray-900 dark:hover:text-white" @click="sortBy('phones', @js($key))">{{ $label }} <span x-text="arrow('phones', @js($key))"></span></button>
                                </th>
                            @endforeach
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="p in sorted('phones')" :key="p.phone_id">
                            <tr class="c-row cursor-pointer" @click="pickPhone(p)" :class="String(p.phone_id) === String(phoneId) && 'bg-brand-50/60 dark:bg-brand-500/10'">
                                <td class="font-mono text-gray-900 dark:text-white" x-text="p.phone"></td>
                                <td class="c-muted" x-text="p.owner ?? '—'"></td>
                                <td class="text-right tabular-nums" x-text="$fmt.number(p.sent)"></td>
                                <td class="text-right tabular-nums" :class="p.failed && 'text-error-600'" x-text="$fmt.number(p.failed)"></td>
                                <td class="text-right font-semibold tabular-nums" :class="rateTone(p.rate)" x-text="p.rate === null ? '—' : p.rate.toFixed(1) + '%'"></td>
                                <td class="text-right tabular-nums">
                                    <span x-show="p.flood" class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[11px] font-semibold" :class="$fmt.tone('warning')" x-text="$fmt.number(p.flood)"></span>
                                    <span x-show="!p.flood" class="c-muted">0</span>
                                </td>
                                <td class="text-right">
                                    <a :href="eventsUrl(p)" @click.stop class="c-muted inline-flex items-center gap-1 text-xs hover:text-gray-900 dark:hover:text-white" title="{{ __('monitoring.analytics.account_events') }}"><x-ui.icon name="alert" class="h-3.5 w-3.5" /></a>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.app>
