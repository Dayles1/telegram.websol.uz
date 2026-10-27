@php
    $tabs = [
        ['value' => '', 'label' => __('sends.total'), 'count' => 'total', 'dot' => 'text-gray-400'],
        ['value' => 'sent', 'label' => __('sends.sent'), 'count' => 'sent', 'dot' => 'text-brand-500'],
        ['value' => 'failed', 'label' => __('sends.failed'), 'count' => 'failed', 'dot' => 'text-error-500'],
        ['value' => 'pending', 'label' => __('sends.pending'), 'count' => 'queued', 'dot' => 'text-info-500'],
        ['value' => 'sending', 'label' => __('enums.message_status.sending'), 'count' => 'sending', 'dot' => 'text-brand-500'],
        ['value' => 'canceled', 'label' => __('sends.canceled'), 'count' => 'canceled', 'dot' => 'text-gray-400'],
    ];
    $legend = [
        ['key' => 'sent', 'label' => __('sends.sent'), 'bar' => 'bg-brand-500', 'text' => 'text-gray-950 dark:text-white'],
        ['key' => 'failed', 'label' => __('sends.failed'), 'bar' => 'bg-error-400', 'text' => 'text-error-600 dark:text-error-400'],
        ['key' => 'pending', 'label' => __('sends.pending'), 'bar' => 'bg-gray-200 dark:bg-gray-700', 'text' => 'text-gray-950 dark:text-white'],
        ['key' => 'canceled', 'label' => __('sends.canceled'), 'bar' => 'bg-gray-400 dark:bg-gray-500', 'text' => 'text-gray-500'],
    ];
    $waitingTones = [
        'error' => 'border-error-200 bg-error-50 text-error-800 dark:border-error-500/30 dark:bg-error-500/10 dark:text-error-200',
        'warning' => 'border-warning-200 bg-warning-50 text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-200',
        'info' => 'border-[var(--line)] bg-[var(--surface)] text-gray-700 dark:text-gray-300',
    ];
    $strings = __('sends.js') + [
        'loop_reasons' => __('sends.loop_reasons'),
        'loop_due' => __('sends.loop_due'),
        'loop_after_this' => __('sends.loop_after_this'),
        'loop_round_running' => __('sends.loop_round_running'),
    ];
@endphp

<x-layouts.app :title="__('sends.send_no', ['id' => $groupId])">
    <div x-data="sendShowPage({
        urls: @js([
            'progress' => route('sends.progress', $groupId),
            'cancel' => route('sends.cancel', $groupId),
            'retry' => route('sends.retry', $groupId),
            'removePeer' => route('catalogs.remove-peer', '__ID__'),
        ]),
        t: @js($strings),
        captionMax: @js(\App\Sending\MassSend::MAX_CAPTION_LENGTH),
    })">
        <x-ui.page-header :title="__('sends.send_no', ['id' => $groupId])">
            <x-slot:eyebrow>
                <a href="{{ route('sends.index') }}" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white">
                    <x-ui.icon name="chevron-left" class="h-3.5 w-3.5" /> {{ __('sends.back') }}
                </a>
            </x-slot:eyebrow>
            <x-slot:actions>
                <template x-if="counts.failed">
                    <x-ui.button variant="ghost" icon="copy" @click="copyFailed()">{{ __('sends.copy_failed') }}</x-ui.button>
                </template>
                <template x-if="group?.can.retry">
                    <x-ui.button variant="secondary" icon="refresh" @click="retry()" ::disabled="busy">{{ __('sends.retry') }}</x-ui.button>
                </template>
                <template x-if="group?.can.cancel">
                    <x-ui.button variant="danger" icon="stop" @click="stop()" ::disabled="busy">{{ __('sends.stop') }}</x-ui.button>
                </template>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Loading / error --}}
        <div x-show="loading && !error" class="grid gap-5 lg:grid-cols-[1fr_340px]">
            <div class="space-y-5"><div class="c-skeleton h-56 rounded-2xl"></div><div class="c-skeleton h-32 rounded-2xl"></div></div>
            <div class="c-skeleton h-80 rounded-2xl"></div>
        </div>
        <div x-show="error" x-cloak class="c-surface">
            <x-ui.empty icon="alert" :title="__('sends.loading_failed')">
                <x-ui.button variant="secondary" icon="refresh" @click="reload()">{{ __('common.refresh') }}</x-ui.button>
            </x-ui.empty>
        </div>

        <template x-if="group">
            <div class="space-y-5">
                {{-- Why the queued messages are not going out right now (App\Queries\WaitingReason) --}}
                <template x-if="waiting">
                    <section class="rounded-2xl border p-4 text-[13px]" :class="@js($waitingTones)[waiting.tone] ?? @js($waitingTones['info'])">
                        <div class="flex items-start gap-3">
                            <x-ui.icon name="alert" class="mt-0.5 h-5 w-5 shrink-0" x-show="waiting.tone !== 'info'" />
                            <x-ui.icon name="clock" class="mt-0.5 h-5 w-5 shrink-0" x-show="waiting.tone === 'info'" />
                            <div class="min-w-0 flex-1 space-y-2">
                                <div>
                                    <p class="text-[11px] font-semibold uppercase tracking-wide opacity-70">{{ __('sends.waiting_eyebrow') }}</p>
                                    <p class="mt-0.5 text-sm font-semibold" x-text="waiting.title"></p>
                                    <p class="mt-1 opacity-90" x-text="waiting.hint"></p>
                                </div>
                                <template x-if="waiting.problem">
                                    <div class="rounded-xl bg-white/70 px-3 py-2 text-xs text-gray-800 dark:bg-black/30 dark:text-gray-200">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                            <span class="font-semibold">{{ __('sends.last_problem') }}</span>
                                            <span class="c-muted tabular-nums">
                                                <span x-text="$fmt.relative(waiting.problem.at)"></span>
                                                <span x-show="waiting.problem.occurrences > 1" x-text="'· ' + @js(__('sends.problem_times')).replace(':n', waiting.problem.occurrences)"></span>
                                            </span>
                                        </div>
                                        <p class="mt-1" x-show="waiting.problem.label" x-text="waiting.problem.label"></p>
                                        <p class="c-muted mt-0.5 c-break" x-text="waiting.problem.message"></p>
                                        <code class="c-code c-break mt-1.5 block whitespace-pre-wrap" x-show="waiting.problem.raw" x-text="waiting.problem.raw"></code>
                                    </div>
                                </template>
                                <template x-if="waiting.phone_url">
                                    <a :href="waiting.phone_url" class="inline-flex items-center gap-1 text-xs font-semibold underline-offset-2 hover:underline">
                                        {{ __('sends.open_account') }} <x-ui.icon name="chevron-right" class="h-3.5 w-3.5" />
                                    </a>
                                </template>
                            </div>
                        </div>
                    </section>
                </template>

                <div class="grid gap-5 lg:grid-cols-[1fr_340px]">
                    <div class="min-w-0 space-y-5">
                        {{-- Progress --}}
                        <section class="c-surface p-4 sm:p-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(group.tone)">
                                        <span class="c-dot h-1.5 w-1.5" :class="group.active && 'is-live'"></span><span x-text="group.status_label"></span>
                                    </span>
                                    <div class="mt-3 flex items-baseline gap-2">
                                        <span class="text-4xl font-semibold leading-none tracking-[-0.03em] tabular-nums text-gray-950 dark:text-white" x-text="group.progress + '%'"></span>
                                        <span class="c-muted text-[13px] tabular-nums" x-text="@js(__('sends.done_of')).replace(':done', $fmt.number(done)).replace(':total', $fmt.number(counts.total))"></span>
                                    </div>
                                </div>
                                <dl class="grid grid-cols-3 gap-x-6 gap-y-1 text-right text-xs">
                                    <div>
                                        <dt class="c-eyebrow">{{ __('sends.duration') }}</dt>
                                        <dd class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white" x-text="duration !== null ? $fmt.clock(duration) : '—'"></dd>
                                    </div>
                                    <div>
                                        <dt class="c-eyebrow">{{ __('sends.rate') }}</dt>
                                        <dd class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white"
                                            x-text="rate !== null ? @js(__('sends.per_minute')).replace(':n', $fmt.number(Math.round(rate * 10) / 10)) : '—'"></dd>
                                    </div>
                                    <div>
                                        <dt class="c-eyebrow">{{ __('sends.eta') }}</dt>
                                        <dd class="mt-1 text-sm font-semibold tabular-nums text-brand-700 dark:text-brand-300" x-text="eta !== null ? '≈ ' + $fmt.clock(eta) : '—'"></dd>
                                    </div>
                                </dl>
                            </div>

                            <div class="c-progress mt-5 h-3" :class="group.active && 'is-live'">
                                <span class="bg-brand-500" :style="`width:${share('sent')}%`"></span>
                                <span class="bg-error-400" :style="`width:${share('failed')}%`"></span>
                                <span class="bg-gray-400 dark:bg-gray-500" :style="`width:${share('canceled')}%`"></span>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach ($legend as $item)
                                    <button type="button" @click="setStatus(@js($item['key']))"
                                            class="rounded-xl border border-[var(--line)] px-3 py-2.5 text-left transition-colors hover:border-[var(--line-strong)] hover:bg-[var(--surface-2)]"
                                            :class="status === @js($item['key']) && 'border-gray-900 dark:border-white'">
                                        <span class="c-muted flex items-center gap-1.5 text-xs"><span class="h-2 w-2 rounded-sm {{ $item['bar'] }}"></span>{{ $item['label'] }}</span>
                                        <span class="mt-1 block text-xl font-semibold tabular-nums {{ $item['text'] }}" x-text="$fmt.number(counts[@js($item['key'])])"></span>
                                        <span class="c-muted block text-[11px] tabular-nums" x-text="$fmt.percent(counts[@js($item['key'])], counts.total)"></span>
                                    </button>
                                @endforeach
                            </div>
                        </section>

                        {{-- Message text --}}
                        <x-ui.surface :title="__('sends.message')">
                            <x-slot:actions>
                                <x-ui.button size="sm" variant="ghost" icon="copy" @click="$copy(group.text)" x-show="group.text">{{ __('sends.copy_text') }}</x-ui.button>
                            </x-slot:actions>

                            {{-- The file sent with the text --}}
                            <template x-if="group.file">
                                <div class="mb-4">
                                    <div class="flex items-center gap-3 rounded-xl border border-[var(--line)] p-2.5">
                                        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/[0.06] dark:text-gray-400">
                                            <x-ui.icon name="image" class="h-5 w-5" x-show="group.file.kind === 'photo'" />
                                            <x-ui.icon name="file" class="h-5 w-5" x-show="group.file.kind !== 'photo'" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-[13px] font-medium text-gray-900 dark:text-white" :class="group.file.removed && 'line-through decoration-gray-400'"
                                                 x-text="group.file.name" :title="group.file.name"></div>
                                            <div class="c-muted text-xs tabular-nums">
                                                <span x-text="$fmt.bytes(group.file.size)"></span> ·
                                                <span x-text="group.file.kind === 'photo' ? @js(__('sends.file_photo')) : @js(__('sends.file_document'))"></span>
                                            </div>
                                        </div>
                                        <span x-show="group.file.removed" class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone('neutral')">
                                            <x-ui.icon name="trash" class="h-3 w-3" />{{ __('sends.file_removed') }}
                                        </span>
                                    </div>
                                    <p x-show="group.file.removed && counts.failed && !group.active" class="c-muted mt-2 flex gap-1.5 text-xs">
                                        <x-ui.icon name="info" class="mt-px h-3.5 w-3.5" /><span>{{ __('sends.file_removed_hint') }}</span>
                                    </p>
                                    <p x-show="group.text.length > captionMax" class="c-muted mt-2 flex gap-1.5 text-xs">
                                        <x-ui.icon name="info" class="mt-px h-3.5 w-3.5" /><span>{{ __('sends.text_separate', ['max' => \App\Sending\MassSend::MAX_CAPTION_LENGTH]) }}</span>
                                    </p>
                                </div>
                            </template>

                            <p x-show="!group.text" class="c-muted text-[13px]">{{ __('sends.no_text') }}</p>
                            <div class="relative" x-show="group.text">
                                <p class="c-break whitespace-pre-wrap text-[14px] leading-relaxed text-gray-900 dark:text-gray-100"
                                   :class="longText && !textOpen && 'max-h-40 overflow-hidden'" x-text="group.text"></p>
                                <div x-show="longText && !textOpen" class="pointer-events-none absolute inset-x-0 bottom-0 h-12 bg-gradient-to-t from-[var(--surface)] to-transparent"></div>
                            </div>
                            <button type="button" x-show="longText" @click="textOpen = !textOpen"
                                    class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                                <x-ui.icon name="chevron-down" class="h-3.5 w-3.5 transition-transform" ::class="textOpen && 'rotate-180'" />
                                <span x-text="textOpen ? @js(__('sends.show_less')) : @js(__('sends.show_more'))"></span>
                            </button>
                        </x-ui.surface>
                    </div>

                    <aside class="min-w-0 space-y-5">
                        {{-- Repeating send: this send is one of its rounds --}}
                        <template x-if="group.loop">
                            <section class="c-surface overflow-hidden">
                                <div class="c-surface__head">
                                    <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                                        <x-ui.icon name="repeat" class="c-muted" /> {{ __('sends.loop_title') }}
                                    </h2>
                                    <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(group.loop.tone)">
                                        <span class="c-dot h-1.5 w-1.5" :class="group.loop.active && 'is-live'"></span><span x-text="group.loop.status_label"></span>
                                    </span>
                                </div>
                                <dl class="divide-y divide-[var(--line)] text-[13px]">
                                    <div class="flex justify-between gap-4 px-4 py-3">
                                        <dt class="c-muted shrink-0">{{ __('sends.loop_every') }}</dt>
                                        <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-white" x-text="every(group.loop.every_minutes)"></dd>
                                    </div>
                                    <div class="flex justify-between gap-4 px-4 py-3">
                                        <dt class="c-muted shrink-0">{{ __('sends.loop_rounds') }}</dt>
                                        <dd class="text-right font-medium tabular-nums text-gray-900 dark:text-white" x-text="loopRounds"></dd>
                                    </div>
                                    <div x-show="group.loop.active" class="flex justify-between gap-4 px-4 py-3">
                                        <dt class="c-muted shrink-0">{{ __('sends.loop_next') }}</dt>
                                        <dd class="min-w-0 text-right">
                                            <template x-if="group.loop.next_run_at">
                                                <div>
                                                    <div class="font-semibold tabular-nums text-brand-700 dark:text-brand-300" x-text="nextRound"></div>
                                                    <div class="c-muted text-xs tabular-nums" x-text="$fmt.dateTime(group.loop.next_run_at)"></div>
                                                </div>
                                            </template>
                                            <template x-if="!group.loop.next_run_at">
                                                <div>
                                                    <div class="text-gray-900 dark:text-white" x-text="group.active ? t.loop_after_this : t.loop_round_running"></div>
                                                    <a x-show="!group.active" href="{{ route('sends.index', ['status' => 'active']) }}" class="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">{{ __('sends.loop_running_link') }} →</a>
                                                </div>
                                            </template>
                                        </dd>
                                    </div>
                                    <div x-show="!group.loop.active && group.loop.stop_reason" class="flex justify-between gap-4 px-4 py-3">
                                        <dt class="c-muted shrink-0">{{ __('sends.loop_stop_reason') }}</dt>
                                        <dd class="text-right text-gray-900 dark:text-white" x-text="t.loop_reasons[group.loop.stop_reason] ?? group.loop.stop_reason"></dd>
                                    </div>
                                </dl>
                                <div class="border-t border-[var(--line)] px-4 py-3">
                                    <p class="c-muted text-xs">{{ __('sends.loop_hint') }}</p>
                                    <template x-if="group.can.stop_loop">
                                        <x-ui.button variant="secondary" icon="stop" class="mt-3 w-full" @click="stopLoop()" ::disabled="busy">{{ __('sends.loop_stop') }}</x-ui.button>
                                    </template>
                                </div>
                            </section>
                        </template>

                        {{-- Details --}}
                        <x-ui.surface :padded="false">
                            <dl class="divide-y divide-[var(--line)] text-[13px]">
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.account') }}</dt>
                                    <dd class="min-w-0 text-right">
                                        <div class="truncate font-medium text-gray-900 dark:text-white" x-text="group.phone?.name ?? '—'"></div>
                                        <div class="c-muted font-mono text-xs" x-text="group.phone?.phone"></div>
                                    </dd>
                                </div>
                                <div x-show="group.phone?.owner" class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.owner') }}</dt>
                                    <dd class="truncate text-right font-medium text-gray-900 dark:text-white" x-text="group.phone?.owner"></dd>
                                </div>
                                <div x-show="group.creator" class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.creator') }}</dt>
                                    <dd class="truncate text-right font-medium text-gray-900 dark:text-white" x-text="group.creator"></dd>
                                </div>
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.catalogs') }}</dt>
                                    <dd class="flex min-w-0 flex-wrap justify-end gap-1">
                                        <template x-for="c in group.catalogs ?? []" :key="c.id">
                                            <span class="rounded bg-gray-100 px-1.5 py-px text-xs text-gray-700 dark:bg-white/[0.06] dark:text-gray-300" x-text="c.title"></span>
                                        </template>
                                        <span x-show="!(group.catalogs ?? []).length" class="c-muted">—</span>
                                    </dd>
                                </div>
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.created_at') }}</dt>
                                    <dd class="text-right tabular-nums text-gray-900 dark:text-white" x-text="$fmt.dateTime(group.created_at)"></dd>
                                </div>
                                <div class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.started_at') }}</dt>
                                    <dd class="text-right tabular-nums text-gray-900 dark:text-white" x-text="group.started_at ? $fmt.dateTime(group.started_at) : @js(__('sends.not_started'))"></dd>
                                </div>
                                <div x-show="group.finished_at" class="flex justify-between gap-4 px-4 py-3">
                                    <dt class="c-muted shrink-0">{{ __('sends.finished_at') }}</dt>
                                    <dd class="text-right tabular-nums text-gray-900 dark:text-white" x-text="$fmt.dateTime(group.finished_at)"></dd>
                                </div>
                            </dl>
                        </x-ui.surface>

                        {{-- Errors breakdown --}}
                        <x-ui.surface :title="__('sends.errors_title')" :padded="false">
                            <p x-show="!counts.failed" class="c-muted px-4 py-5 text-[13px]">{{ __('sends.errors_empty') }}</p>
                            <ul x-show="counts.failed" class="divide-y divide-[var(--line)]">
                                <template x-for="e in errorGroups" :key="e.text">
                                    <li class="px-4 py-2.5">
                                        <div class="flex items-start justify-between gap-3 text-[13px]">
                                            <span class="c-break text-gray-800 dark:text-gray-200" x-text="e.text"></span>
                                            <span class="shrink-0 font-semibold tabular-nums text-error-600 dark:text-error-400" x-text="$fmt.number(e.count)"></span>
                                        </div>
                                        <div class="c-progress mt-1.5 h-1">
                                            <span class="bg-error-400" :style="`width:${e.count / errorGroups[0].count * 100}%`"></span>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                            <p x-show="failedTruncated" class="c-muted border-t border-[var(--line)] px-4 py-2 text-xs"
                               x-text="@js(__('sends.errors_partial')).replace(':count', $fmt.number(failedPeers.length))"></p>
                        </x-ui.surface>
                    </aside>
                </div>

                {{-- Messages --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head flex-wrap">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('sends.messages_title') }}</h2>
                        <label class="relative block w-full sm:w-64">
                            <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                            <input type="search" data-page-search class="c-input h-9 pl-9" x-model="search" @input="searchChanged()" placeholder="{{ __('sends.peer_search') }}">
                        </label>
                    </div>
                    <div class="flex gap-1 overflow-x-auto border-b border-[var(--line)] px-3" style="scrollbar-width:none">
                        @foreach ($tabs as $tab)
                            <button type="button" @click="setStatus(@js($tab['value']))"
                                    class="relative inline-flex h-10 shrink-0 items-center gap-1.5 px-2.5 text-[13px] font-medium transition-colors"
                                    :class="status === @js($tab['value']) ? 'text-gray-950 dark:text-white' : 'c-muted hover:text-gray-900 dark:hover:text-white'">
                                <span class="c-dot h-1.5 w-1.5 {{ $tab['dot'] }}"></span>
                                {{ $tab['label'] }}
                                @if ($tab['count'])
                                    <span class="rounded-full bg-gray-100 px-1.5 text-[11px] tabular-nums text-gray-600 dark:bg-white/[0.06] dark:text-gray-300" x-text="$fmt.number(counts[@js($tab['count'])])"></span>
                                @endif
                                <span x-show="status === @js($tab['value'])" class="absolute inset-x-2 -bottom-px h-0.5 rounded-t bg-brand-500"></span>
                            </button>
                        @endforeach
                    </div>

                    <div x-show="!messages.length" x-cloak>
                        <x-ui.empty icon="search" :title="__('sends.no_messages')" />
                    </div>

                    <div x-show="messages.length" class="overflow-x-auto" :class="listLoading && 'opacity-60 transition-opacity'">
                        <table class="c-table min-w-[720px]">
                            <thead>
                                <tr>
                                    <th>{{ __('sends.col_peer') }}</th>
                                    <th class="w-36">{{ __('common.status') }}</th>
                                    <th>{{ __('sends.col_error') }}</th>
                                    <th class="w-24 text-right">{{ __('sends.col_attempts') }}</th>
                                    <th class="w-40 text-right">{{ __('sends.col_time') }}</th>
                                    <th class="w-12"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="m in messages" :key="m.id">
                                    <tr class="c-row">
                                        <td>
                                            <div class="flex items-center gap-1.5">
                                                <template x-if="m.link">
                                                    <a :href="m.link" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-mono text-[13px] text-brand-700 hover:underline dark:text-brand-300">
                                                        <span x-text="m.peer"></span><x-ui.icon name="external" class="h-3 w-3" />
                                                    </a>
                                                </template>
                                                <template x-if="!m.link"><span class="font-mono text-[13px] text-gray-900 dark:text-white" x-text="m.peer"></span></template>
                                                <button type="button" class="c-muted rounded p-0.5 hover:text-gray-900 dark:hover:text-white" @click="$copy(m.peer)" title="{{ __('sends.copy_peer') }}">
                                                    <x-ui.icon name="copy" class="h-3 w-3" />
                                                </button>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(m.tone)">
                                                <span class="c-dot h-1.5 w-1.5" :class="m.status === 'sending' && 'is-live'"></span><span x-text="m.status_label"></span>
                                            </span>
                                        </td>
                                        <td class="max-w-xs">
                                            <span x-show="m.error_text" class="c-break text-xs text-error-700 dark:text-error-300" x-text="m.error_text" :title="m.error_key"></span>
                                            <span x-show="!m.error_text" class="c-muted">—</span>
                                        </td>
                                        <td class="text-right tabular-nums" x-text="m.attempts ?? 0"></td>
                                        <td class="text-right">
                                            <div class="text-xs tabular-nums text-gray-900 dark:text-white" x-text="$fmt.time(m.sent_at ?? m.updated_at)"></div>
                                            <div class="c-muted text-[11px]" x-text="$fmt.relative(m.sent_at ?? m.updated_at)"></div>
                                        </td>
                                        <td class="text-right">
                                            <template x-if="m.status === 'failed' && editableCatalogs.length">
                                                <x-ui.button size="icon-sm" variant="danger-ghost" @click="askRemove(m)" ::disabled="busy || isRemoved(m.peer)"
                                                             :title="__('sends.remove_from_catalog')">
                                                    <x-ui.icon name="trash" x-show="!isRemoved(m.peer)" />
                                                    <x-ui.icon name="check" x-show="isRemoved(m.peer)" class="h-4 w-4 text-success-600" />
                                                </x-ui.button>
                                            </template>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <x-ui.pager />
                </section>
            </div>
        </template>

        {{-- Remove a peer: choose the catalog --}}
        <x-ui.modal show="removeModal" :title="__('sends.remove_from_catalog')" width="max-w-md">
            <p class="c-muted mb-4 text-[13px]" x-text="@js(__('sends.remove_choose')).replace(':peer', removeTarget?.peer ?? '')"></p>
            <div class="space-y-2">
                <template x-for="c in editableCatalogs" :key="c.id">
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-[var(--line)] px-3 py-2.5 text-[13px] hover:bg-[var(--surface-2)]"
                           :class="Number(removeCatalog) === c.id && 'border-brand-500'">
                        <input type="radio" class="c-check" name="remove_catalog" :value="c.id" x-model.number="removeCatalog">
                        <span class="flex-1 font-medium text-gray-900 dark:text-white" x-text="c.title"></span>
                        <x-ui.icon name="check" class="h-4 w-4 text-success-600" x-show="removed[c.id + '|' + removeTarget?.peer]" />
                    </label>
                </template>
            </div>
            <x-slot:footer>
                <x-ui.button variant="ghost" @click="removeModal = false">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button variant="danger" icon="trash" @click="confirmRemove()" ::disabled="busy || !removeCatalog">{{ __('sends.remove_from_catalog') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-layouts.app>
