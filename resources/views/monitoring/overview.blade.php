@php
    use App\Enums\AccountStatus;
    use App\Enums\EventLevel;

    $statusTones = collect(AccountStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->tone()]);
    $barTones = ['success' => 'bg-success-500', 'error' => 'bg-error-500', 'warning' => 'bg-warning-500', 'info' => 'bg-info-500', 'brand' => 'bg-brand-500', 'neutral' => 'bg-gray-400'];
    $levelTones = collect(EventLevel::cases())->mapWithKeys(fn ($l) => [$l->value => $l->tone()]);
    // Send jobs report only an account id; the phone number makes the row readable.
    $phones = \App\Models\UserPhone::query()->pluck('phone', 'id');
    $commands = [
        'cron' => '* * * * * cd /var/www/telegram.websol.uz && php artisan schedule:run',
        'queue' => 'php artisan queue:work --queue='.config('telegram.queue'),
        'sending' => 'php artisan queue:work --queue='.config('telegram.send_queue').' --max-jobs=1',
    ];
@endphp

<x-layouts.app :title="__('monitoring.overview.title')" section="monitoring">
    <div x-data="monitoringPage({
        urls: @js([
            'data' => route('monitoring.data'),
            'send' => route('sends.show', '__ID__'),
            'event' => route('monitoring.events.show', '__ID__'),
        ]),
        t: @js(__('monitoring.js')),
        errors: @js(__('errors.codes')),
        phones: @js($phones),
    })">
        <x-ui.page-header :title="__('monitoring.overview.title')" :description="__('monitoring.overview.description')">
            <x-slot:actions>
                <span class="inline-flex items-center gap-2 text-xs tabular-nums" :class="stale ? 'text-error-600' : 'c-muted'">
                    <span class="c-dot" :class="stale ? 'text-error-500' : 'is-live text-success-500'"></span>
                    <span x-show="stale" x-cloak>{{ __('monitoring.overview.connection_lost') }}</span>
                    <span x-show="!stale && ago !== null" x-text="@js(__('monitoring.overview.updated_ago')).replace(':n', ago)"></span>
                </span>
                <x-ui.button variant="secondary" size="sm" icon="refresh" @click="refresh()">{{ __('common.refresh') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Something essential is not running --}}
        <div x-show="down" x-cloak class="mb-5 rounded-2xl border border-error-200 bg-error-50 p-4 text-[13px] text-error-800 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-200">
            <div class="flex items-start gap-3">
                <x-ui.icon name="alert" class="mt-0.5 h-5 w-5 text-error-600" />
                <div class="min-w-0 flex-1 space-y-3">
                    <p class="text-sm font-semibold">{{ __('monitoring.overview.down_title') }}</p>
                    <div x-show="!tick.alive">
                        <p>{{ __('monitoring.overview.tick_down') }}</p>
                        <div class="mt-1.5 flex min-w-0 items-center gap-2">
                            <code class="c-code c-break !bg-white/70 dark:!bg-black/30">{{ $commands['cron'] }}</code>
                            <button type="button" class="shrink-0 hover:text-error-950 dark:hover:text-white" @click="$copy(@js($commands['cron']))" title="{{ __('monitoring.copy') }}" aria-label="{{ __('monitoring.copy') }}"><x-ui.icon name="copy" class="h-3.5 w-3.5" /></button>
                        </div>
                    </div>
                    <div x-show="!queue.alive">
                        <p>{{ __('monitoring.overview.queue_down') }}</p>
                        @foreach (['queue', 'sending'] as $command)
                            <div class="mt-1.5 flex min-w-0 items-center gap-2">
                                <code class="c-code c-break !bg-white/70 dark:!bg-black/30">{{ $commands[$command] }}</code>
                                <button type="button" class="shrink-0 hover:text-error-950 dark:hover:text-white" @click="$copy(@js($commands[$command]))" title="{{ __('monitoring.copy') }}" aria-label="{{ __('monitoring.copy') }}"><x-ui.icon name="copy" class="h-3.5 w-3.5" /></button>
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs opacity-80">{{ __('monitoring.overview.down_hint') }}</p>
                </div>
            </div>
        </div>

        {{-- Health strip --}}
        <div class="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {{-- Sending: send jobs on the queue, the minute tick as their safety net --}}
            <div class="c-surface p-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="c-eyebrow flex items-center gap-1.5"><x-ui.icon name="send" class="h-3.5 w-3.5" /> {{ __('monitoring.overview.sending') }}</span>
                    <span x-show="!loading" x-cloak class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(tick.alive ? 'success' : 'error')"
                          title="{{ __('monitoring.overview.tick_title') }}">
                        <span class="c-dot h-1.5 w-1.5" :class="tick.alive && 'is-live'"></span>
                        <span x-text="tick.alive ? @js(__('monitoring.overview.alive')) : @js(__('monitoring.overview.dead'))"></span>
                    </span>
                </div>
                <div class="mt-3 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 dark:text-white">
                    <span x-text="workers.length"></span>
                    <span class="c-muted text-xs font-normal tracking-normal">{{ __('monitoring.overview.jobs_running') }}</span>
                </div>
                <div class="c-muted mt-2 space-y-0.5 text-xs">
                    <div x-show="tick.at">
                        {{ __('monitoring.overview.last_tick') }}: <span class="font-medium" :class="tick.alive ? 'text-gray-800 dark:text-gray-200' : 'text-error-600'" x-text="$fmt.relative(tick.at)"></span>
                    </div>
                    <div x-show="tick.at" class="tabular-nums" x-text="@js(__('monitoring.overview.tick_queued')).replace(':n', $fmt.number(tick.queued))"></div>
                    <div x-show="!loading && !tick.at" x-cloak class="text-error-600">{{ __('monitoring.overview.tick_never') }}</div>
                </div>
            </div>

            {{-- Queue --}}
            <div class="c-surface p-4">
                <div class="flex items-center justify-between gap-2">
                    <span class="c-eyebrow flex items-center gap-1.5"><x-ui.icon name="layers" class="h-3.5 w-3.5" /> {{ __('monitoring.overview.queue') }}</span>
                    <span x-show="!loading" x-cloak class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(queue.alive ? 'success' : 'error')">
                        <span class="c-dot h-1.5 w-1.5" :class="queue.alive && 'is-live'"></span>
                        <span x-text="queue.alive ? @js(__('monitoring.overview.alive')) : @js(__('monitoring.overview.dead'))"></span>
                    </span>
                </div>
                <div class="mt-3 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums text-gray-950 dark:text-white">
                    <span x-text="$fmt.number(queue.waiting)"></span>
                    <span class="c-muted text-xs font-normal tracking-normal">{{ __('monitoring.overview.jobs_waiting') }}</span>
                </div>
                <div class="c-muted mt-2 flex flex-wrap gap-x-3 gap-y-0.5 text-xs tabular-nums">
                    <span>{{ __('monitoring.overview.delayed') }}: <span class="font-medium text-gray-800 dark:text-gray-200" x-text="$fmt.number(queue.delayed)"></span></span>
                    <span>{{ __('monitoring.overview.reserved') }}: <span class="font-medium text-gray-800 dark:text-gray-200" x-text="$fmt.number(queue.reserved)"></span></span>
                    <span :class="queue.failed && 'text-error-600'">{{ __('monitoring.overview.failed_jobs') }}: <span class="font-medium" x-text="$fmt.number(queue.failed)"></span></span>
                    <span x-show="queue.oldest_wait_seconds" :class="queue.oldest_wait_seconds > 60 && 'text-warning-600'">{{ __('monitoring.overview.oldest_wait') }}: <span class="font-medium" x-text="span(queue.oldest_wait_seconds)"></span></span>
                    <span x-show="!queue.alive && queue.last_beat">{{ __('monitoring.overview.last_beat') }}: <span x-text="span(since(queue.last_beat))"></span></span>
                </div>
            </div>

            {{-- Alerts bot --}}
            <a href="{{ route('monitoring.settings') }}#alerts" class="c-surface block p-4 transition-colors hover:border-[var(--line-strong)]">
                <div class="flex items-center justify-between gap-2">
                    <span class="c-eyebrow flex items-center gap-1.5"><x-ui.icon name="bell" class="h-3.5 w-3.5" /> {{ __('monitoring.overview.alerts') }}</span>
                    <span x-show="!loading" x-cloak class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(processes.alerts_configured ? 'success' : 'warning')">
                        <span class="c-dot h-1.5 w-1.5"></span>
                        <span x-text="processes.alerts_configured ? @js(__('monitoring.overview.alerts_on')) : @js(__('monitoring.overview.alerts_off'))"></span>
                    </span>
                </div>
                <p class="mt-3 text-[13px] text-gray-800 dark:text-gray-200" x-text="processes.alerts_configured ? @js(__('monitoring.overview.alerts_on_text')) : @js(__('monitoring.overview.alerts_off_text'))"></p>
                <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-300">{{ __('monitoring.overview.alerts_settings') }} →</p>
            </a>

            {{-- Problems --}}
            <a href="{{ route('monitoring.events') }}" class="c-surface block p-4 transition-colors hover:border-[var(--line-strong)]">
                <div class="flex items-center justify-between gap-2">
                    <span class="c-eyebrow flex items-center gap-1.5"><x-ui.icon name="alert" class="h-3.5 w-3.5" /> {{ __('monitoring.overview.open_problems') }}</span>
                    <span x-show="problems.critical" x-cloak class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone('error')">
                        <span class="c-dot is-live h-1.5 w-1.5"></span><span x-text="@js(__('monitoring.overview.errors_count')).replace(':n', problems.critical)"></span>
                    </span>
                </div>
                <div class="mt-3 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums" :class="problems.open ? 'text-error-600' : 'text-success-600'" x-text="$fmt.number(problems.open)"></div>
                <p class="c-muted mt-2 text-xs" x-text="problems.open ? @js(__('monitoring.overview.problems_text')) : @js(__('monitoring.overview.no_problems'))"></p>
            </a>
        </div>

        {{-- Today --}}
        <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <x-ui.stat :label="__('monitoring.overview.sent')" icon="check-circle" tone="success">
                <span x-text="$fmt.number(today.sent)"></span>
                <x-slot:hint><span x-show="successRate !== null" x-text="@js(__('monitoring.overview.success_rate')).replace(':rate', successRate)"></span></x-slot:hint>
            </x-ui.stat>
            <x-ui.stat :label="__('monitoring.overview.failed')" icon="x-circle" tone="error"><span x-text="$fmt.number(today.failed)"></span></x-ui.stat>
            <x-ui.stat :label="__('monitoring.overview.pending')" icon="clock">
                <span x-text="$fmt.number(today.pending)"></span>
                <x-slot:hint><span x-text="@js(__('monitoring.overview.in_flight')).replace(':n', $fmt.number(sending.in_flight))"></span></x-slot:hint>
            </x-ui.stat>
            <x-ui.stat :label="__('monitoring.overview.canceled')" icon="stop"><span x-text="$fmt.number(today.canceled)"></span></x-ui.stat>
            <x-ui.stat :label="__('monitoring.overview.sends')" icon="send" tone="brand"><span x-text="$fmt.number(today.groups)"></span></x-ui.stat>
            <x-ui.stat :label="__('monitoring.overview.accounts_used')" icon="phone"><span x-text="$fmt.number(today.accounts)"></span></x-ui.stat>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_400px]">
            <div class="min-w-0 space-y-5">
                {{-- 24 hours --}}
                <x-ui.surface :title="__('monitoring.overview.last_24h')">
                    <x-slot:actions>
                        <span class="c-muted flex gap-3 text-xs">
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-brand-500"></span>{{ __('monitoring.delivered') }}</span>
                            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-error-400"></span>{{ __('monitoring.errors') }}</span>
                        </span>
                    </x-slot:actions>
                    <div x-show="loading" class="c-skeleton h-[140px]"></div>
                    <div x-show="!loading" x-cloak><x-ui.bar-chart series="last_24h" labels="hour" :height="140" /></div>
                </x-ui.surface>

                {{-- Live sends --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                            <span class="c-dot text-brand-500" :class="sending.groups.length && 'is-live'"></span> {{ __('monitoring.overview.live_sends') }}
                        </h2>
                        <span class="c-muted text-xs tabular-nums" x-text="@js(__('monitoring.overview.queue_line')).replace(':queued', $fmt.number(sending.queued_messages)).replace(':groups', $fmt.number(sending.active_groups))"></span>
                    </div>
                    <div x-show="loading" class="space-y-3 p-4"><div class="c-skeleton h-12"></div><div class="c-skeleton h-12"></div></div>
                    <div x-show="!loading && !sending.groups.length" x-cloak>
                        <x-ui.empty icon="send" :title="__('monitoring.overview.nothing_sending')" />
                    </div>
                    <div class="divide-y divide-[var(--line)]">
                        <template x-for="g in sending.groups" :key="g.id">
                            <a :href="sendUrl(g.id)" class="block px-4 py-3 transition-colors hover:bg-[var(--surface-2)]">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(g.status === 'sending' ? 'brand' : 'info')">
                                        <span class="c-dot h-1.5 w-1.5" :class="g.status === 'sending' && 'is-live'"></span>
                                        <span x-text="@js(__('enums.group_status'))[g.status] ?? g.status"></span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="g.text"></p>
                                        <p class="c-muted mt-0.5 truncate text-xs">
                                            <span x-text="'#' + g.id"></span> · <span class="font-mono" x-text="g.phone"></span>
                                            <span x-show="g.owner" x-text="' · ' + g.owner"></span> · <span x-text="$fmt.relative(g.created_at)"></span>
                                        </p>
                                    </div>
                                    <div class="shrink-0 text-right tabular-nums">
                                        <div class="text-[13px] font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(g.sent) + ' / ' + $fmt.number(g.total)"></div>
                                        <div class="text-xs">
                                            <span x-show="g.failed" class="text-error-600" x-text="'✕ ' + $fmt.number(g.failed)"></span>
                                            <span class="c-muted" x-text="g.progress + '%'"></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="c-progress is-live mt-2.5">
                                    <span class="bg-brand-500" :style="`width:${g.total ? g.sent / g.total * 100 : 0}%`"></span>
                                    <span class="bg-error-400" :style="`width:${g.total ? g.failed / g.total * 100 : 0}%`"></span>
                                </div>
                            </a>
                        </template>
                    </div>
                </section>

                {{-- Workers & backoff --}}
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,320px)]">
                    <section class="c-surface min-w-0 overflow-hidden">
                        <div class="c-surface__head">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.overview.workers') }}</h2>
                            <span class="c-muted text-xs tabular-nums" x-text="workers.length"></span>
                        </div>
                        <div x-show="!loading && !workers.length" x-cloak>
                            <x-ui.empty icon="server" :title="__('monitoring.overview.no_workers')" :text="__('monitoring.overview.no_workers_text', ['queue' => config('telegram.send_queue')])" />
                        </div>
                        <div x-show="workers.length" class="c-scroll-x">
                            <table class="c-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('monitoring.account') }}</th>
                                        <th>PID</th>
                                        <th class="text-right">{{ __('monitoring.overview.running_for') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="w in workers" :key="w.account_id">
                                        <tr class="c-row">
                                            <td>
                                                <span class="inline-flex items-center gap-2">
                                                    <span class="c-dot is-live text-success-500"></span>
                                                    <span class="font-mono text-[13px]" x-text="phoneOf(w.account_id)"></span>
                                                    <span class="c-muted text-xs" x-text="'#' + w.account_id"></span>
                                                </span>
                                            </td>
                                            <td class="font-mono text-xs" x-text="w.pid ?? '—'"></td>
                                            <td class="text-right tabular-nums" x-text="span(since(w.started_at))"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="c-surface min-w-0 overflow-hidden">
                        <div class="c-surface__head">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.overview.backoff') }}</h2>
                            <span class="c-muted text-xs tabular-nums" x-text="backoff.length"></span>
                        </div>
                        <p x-show="!backoff.length" class="c-muted px-4 py-6 text-center text-[13px]">{{ __('monitoring.overview.no_backoff') }}</p>
                        <ul class="divide-y divide-[var(--line)]">
                            <template x-for="b in backoff" :key="b.account_id">
                                <li class="flex items-center gap-3 px-4 py-2.5 text-[13px]">
                                    <span class="c-dot text-warning-500"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate font-mono" x-text="phoneOf(b.account_id)"></div>
                                        <div class="c-muted text-xs" x-text="'#' + b.account_id"></div>
                                    </div>
                                    <span class="shrink-0 text-xs tabular-nums text-warning-700 dark:text-warning-300" x-text="@js(__('monitoring.overview.failures')).replace(':n', b.failures)"></span>
                                </li>
                            </template>
                        </ul>
                        <p class="c-muted border-t border-[var(--line)] px-4 py-2.5 text-xs">{{ __('monitoring.overview.backoff_hint') }}</p>
                    </section>
                </div>
            </div>

            <aside class="min-w-0 space-y-5">
                {{-- Latest problems --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.overview.latest_problems') }}</h2>
                        <a href="{{ route('monitoring.events') }}" class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ __('monitoring.overview.all_events') }} →</a>
                    </div>
                    <div x-show="!loading && !problems.latest.length" x-cloak>
                        <x-ui.empty icon="check-circle" :title="__('monitoring.overview.no_problems')" />
                    </div>
                    <ul class="divide-y divide-[var(--line)]">
                        <template x-for="e in problems.latest" :key="e.id">
                            <li>
                                <a :href="eventUrl(e.id)" class="block px-4 py-3 transition-colors hover:bg-[var(--surface-2)]">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="$fmt.tone(@js($levelTones)[e.level])" x-text="@js(__('enums.event_level'))[e.level] ?? e.level"></span>
                                        <span class="c-muted text-xs" x-text="@js(__('enums.event_kind'))[e.kind] ?? e.kind"></span>
                                        <span x-show="e.occurrences > 1" class="text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-300" x-text="'×' + e.occurrences"></span>
                                        <span class="c-muted ml-auto text-xs" x-text="$fmt.relative(e.last_seen_at)"></span>
                                    </div>
                                    <p class="c-break mt-1 line-clamp-2 text-[13px] text-gray-900 dark:text-white" x-text="e.message"></p>
                                    <p x-show="e.error_key || e.phone" class="c-muted mt-0.5 truncate text-xs">
                                        <span x-show="e.error_key" x-text="errorText(e.error_key)"></span>
                                        <span x-show="e.error_key && e.phone"> · </span>
                                        <span x-show="e.phone" class="font-mono" x-text="e.phone"></span>
                                    </p>
                                </a>
                            </li>
                        </template>
                    </ul>
                </section>

                {{-- Accounts by status --}}
                <x-ui.surface :title="__('monitoring.overview.accounts')">
                    <x-slot:actions><a href="{{ route('phones.index') }}" class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ __('monitoring.overview.manage') }} →</a></x-slot:actions>
                    <div x-show="accountsTotal" class="c-progress mb-4 h-2">
                        <template x-for="s in statusList" :key="s.status">
                            <span :class="@js($barTones)[@js($statusTones)[s.status]] ?? 'bg-gray-400'" :style="`width:${s.count / accountsTotal * 100}%`"></span>
                        </template>
                    </div>
                    <p x-show="!loading && !accountsTotal" x-cloak class="c-muted text-center text-[13px]">{{ __('monitoring.overview.no_accounts') }}</p>
                    <ul class="grid grid-cols-2 gap-x-4 gap-y-2 text-[13px]">
                        <template x-for="s in statusList" :key="s.status">
                            <li class="flex items-center gap-2">
                                <span class="c-dot" :class="$fmt.dot(@js($statusTones)[s.status])"></span>
                                <span class="min-w-0 flex-1 truncate" x-text="@js(__('enums.account_status'))[s.status] ?? s.status"></span>
                                <span class="font-semibold tabular-nums text-gray-900 dark:text-white" x-text="$fmt.number(s.count)"></span>
                            </li>
                        </template>
                    </ul>
                </x-ui.surface>

                {{-- Accounts needing attention --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.overview.attention') }}</h2>
                        <span class="c-muted text-xs tabular-nums" x-text="accounts.attention.length"></span>
                    </div>
                    <div x-show="!loading && !accounts.attention.length" x-cloak>
                        <x-ui.empty icon="shield" :title="__('monitoring.overview.attention_empty')" />
                    </div>
                    <ul class="divide-y divide-[var(--line)]">
                        <template x-for="a in accounts.attention" :key="a.id">
                            <li class="px-4 py-3">
                                <div class="flex items-start gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="a.name || a.phone"></div>
                                        <div class="c-muted truncate text-xs"><span class="font-mono" x-text="a.phone"></span><span x-show="a.owner" x-text="' · ' + a.owner"></span></div>
                                    </div>
                                    <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold"
                                          :class="$fmt.tone(a.flood_until && a.status === 'authorized' ? 'warning' : @js($statusTones)[a.status])">
                                        <span class="c-dot h-1.5 w-1.5"></span>
                                        <span x-text="a.flood_until && a.status === 'authorized' ? @js(__('monitoring.overview.paused')) : (@js(__('enums.account_status'))[a.status] ?? a.status)"></span>
                                    </span>
                                </div>
                                <p x-show="a.flood_until && until(a.flood_until) > 0" class="mt-2 rounded-xl bg-warning-50 px-3 py-1.5 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                                    {{ __('monitoring.overview.flood_left') }} <span class="font-semibold tabular-nums" x-text="span(until(a.flood_until))"></span>
                                </p>
                                <p x-show="a.last_error" class="c-break mt-2 text-xs text-error-700 dark:text-error-300" x-text="errorText(a.last_error)"></p>
                                <div class="mt-2 flex items-center gap-3 text-xs">
                                    <span class="c-muted tabular-nums">
                                        {{ __('monitoring.overview.sent_today') }}:
                                        <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(a.sent_today)"></span><span x-show="accounts.daily_limit" x-text="' / ' + $fmt.number(accounts.daily_limit)"></span>
                                    </span>
                                    <div x-show="accounts.daily_limit" class="c-progress flex-1"><span class="bg-brand-500" :style="`width:${usage(a)}%`"></span></div>
                                    <a href="{{ route('phones.index') }}" class="ml-auto shrink-0 font-medium text-brand-600 hover:underline dark:text-brand-300">{{ __('monitoring.overview.open') }} →</a>
                                </div>
                            </li>
                        </template>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
