{{--
    One send in a list, for an Alpine `g` (GroupResource): status, text, account,
    a paperclip when a file goes with it, the interval of a repeating send,
    a live segmented progress bar and the counts. Links to the detail page.
--}}
@php
    $every = __('sends.js.every');
    $units = __('sends.js.units');
@endphp
<a :href="g.url" {{ $attributes->merge(['class' => 'group block px-4 py-3.5 transition-colors hover:bg-[var(--surface-2)]']) }}>
    <div class="flex items-start gap-3">
        <span class="mt-0.5 inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(g.tone)">
            <span class="c-dot h-1.5 w-1.5" :class="g.active && 'is-live'"></span><span x-text="g.status_label"></span>
        </span>
        <div class="min-w-0 flex-1">
            <p class="flex min-w-0 items-center gap-1.5 text-[13px] font-medium text-gray-900 dark:text-white">
                <span x-show="g.file" class="c-muted shrink-0" :title="g.file ? g.file.name + ' · ' + $fmt.bytes(g.file.size) : ''"><x-ui.icon name="paperclip" class="h-3.5 w-3.5" /></span>
                <span class="truncate" x-text="g.text || g.file?.name || '—'"></span>
            </p>
            <p class="c-muted mt-0.5 flex min-w-0 items-center gap-1 text-xs">
                <span x-show="g.loop" class="inline-flex shrink-0 items-center gap-1 rounded px-1 text-[11px] font-medium" :class="$fmt.tone(g.loop?.active ? 'brand' : 'neutral')" :title="g.loop?.status_label">
                    <x-ui.icon name="repeat" class="h-3 w-3" /><span class="tabular-nums" x-text="g.loop ? @js($every).replace(':time', $fmt.minutes(g.loop.every_minutes, @js($units))) : ''"></span>
                </span>
                <span class="truncate">
                    <span x-text="'#' + g.id"></span> ·
                    <span x-text="g.phone?.phone"></span>
                    <template x-if="g.phone?.owner"><span x-text="' · ' + g.phone.owner"></span></template>
                    · <span x-text="$fmt.relative(g.created_at)"></span>
                </span>
            </p>
        </div>
        <div class="shrink-0 text-right">
            <div class="text-[13px] font-semibold tabular-nums text-gray-900 dark:text-white" x-text="$fmt.number(g.counts.sent) + ' / ' + $fmt.number(g.counts.total)"></div>
            <div x-show="g.counts.failed" class="text-xs tabular-nums text-error-600" x-text="'✕ ' + $fmt.number(g.counts.failed)"></div>
        </div>
    </div>
    <div class="c-progress mt-2.5" :class="g.active && 'is-live'">
        <span class="bg-brand-500" :style="`width:${g.counts.total ? g.counts.sent / g.counts.total * 100 : 0}%`"></span>
        <span class="bg-error-400" :style="`width:${g.counts.total ? g.counts.failed / g.counts.total * 100 : 0}%`"></span>
        <span class="bg-gray-300 dark:bg-gray-600" :style="`width:${g.counts.total ? g.counts.canceled / g.counts.total * 100 : 0}%`"></span>
    </div>
</a>
