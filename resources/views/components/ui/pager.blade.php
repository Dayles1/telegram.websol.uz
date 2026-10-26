{{--
    Pagination for Alpine lists. Expects `meta` {current_page, last_page, total}
    and a `go(page)` method in the component.
--}}
<div x-show="meta.last_page > 1" x-cloak class="flex items-center justify-between gap-3 border-t border-[var(--line)] px-4 py-3 text-[13px]">
    <span class="c-muted tabular-nums" x-text="`${meta.current_page} / ${meta.last_page} · ${$fmt.number(meta.total)}`"></span>
    <div class="flex items-center gap-1">
        <x-ui.button size="sm" variant="ghost" icon="chevron-left" ::disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">{{ __('common.prev') }}</x-ui.button>
        <x-ui.button size="sm" variant="ghost" ::disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">
            {{ __('common.next') }} <x-ui.icon name="chevron-right" />
        </x-ui.button>
    </div>
</div>
