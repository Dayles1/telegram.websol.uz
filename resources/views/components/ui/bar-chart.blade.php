@props(['series', 'height' => 160, 'labels' => 'day'])

{{--
    Stacked bars (sent + failed) from an Alpine expression that yields
    [{bucket, sent, failed}, ...]. Hover shows the numbers. No library.
    labels: "day" (dd.mm) or "hour" (HH:00).
--}}
<div x-data="{ hover: null, get points() { return {{ $series }} ?? [] }, get max() { return Math.max(1, ...this.points.map(p => p.sent + p.failed)) } }"
     {{ $attributes->merge(['class' => 'relative']) }}>
    <div class="flex items-end gap-[3px]" style="height: {{ $height }}px">
        <template x-for="(p, i) in points" :key="p.bucket">
            <div class="group relative flex h-full flex-1 cursor-default flex-col justify-end" @mouseenter="hover = i" @mouseleave="hover = null">
                <div class="w-full rounded-t-[2px] bg-error-400/80 transition-[height] duration-300" :style="`height:${p.failed / max * 100}%`"></div>
                <div class="w-full bg-brand-500 transition-[height] duration-300" :class="p.failed ? '' : 'rounded-t-[2px]'" :style="`height:${p.sent / max * 100}%`"></div>
                <div x-show="!p.sent && !p.failed" class="h-[2px] w-full rounded bg-gray-200 dark:bg-gray-800"></div>
            </div>
        </template>
    </div>
    <div class="c-muted mt-2 flex justify-between text-[11px] tabular-nums">
        <span x-text="points[0] ? (@js($labels) === 'hour' ? points[0].bucket.slice(11) : points[0].bucket.slice(5).split('-').reverse().join('.')) : ''"></span>
        <span x-text="points.at(-1) ? (@js($labels) === 'hour' ? points.at(-1).bucket.slice(11) : points.at(-1).bucket.slice(5).split('-').reverse().join('.')) : ''"></span>
    </div>
    <div x-show="hover !== null" x-cloak class="pointer-events-none absolute -top-2 left-1/2 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-xl border border-[var(--line)] bg-[var(--surface)] px-3 py-2 text-xs shadow-lg">
        <template x-if="hover !== null && points[hover]">
            <div>
                <div class="font-semibold" x-text="points[hover].bucket"></div>
                <div class="mt-1 flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-brand-500"></span><span x-text="$fmt.number(points[hover].sent)"></span></div>
                <div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-error-400"></span><span x-text="$fmt.number(points[hover].failed)"></span></div>
            </div>
        </template>
    </div>
</div>
