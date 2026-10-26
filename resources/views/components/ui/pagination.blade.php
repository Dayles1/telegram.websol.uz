{{-- Server-side paginator view (Paginator::defaultView). --}}
@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-3 border-t border-[var(--line)] px-4 py-3 text-[13px]" aria-label="Pagination">
        <span class="c-muted tabular-nums">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} · {{ number_format($paginator->total()) }}</span>
        <div class="flex items-center gap-1">
            <x-ui.button size="sm" variant="ghost" icon="chevron-left" :href="$paginator->previousPageUrl()" :aria-disabled="$paginator->onFirstPage() ? 'true' : null">{{ __('common.prev') }}</x-ui.button>
            <x-ui.button size="sm" variant="ghost" :href="$paginator->nextPageUrl()" :aria-disabled="$paginator->hasMorePages() ? null : 'true'">
                {{ __('common.next') }} <x-ui.icon name="chevron-right" />
            </x-ui.button>
        </div>
    </nav>
@endif
