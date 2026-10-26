@if ($paginated->lastPage() > 1)
<div class="d-flex justify-content-center align-items-center gap-2 mt-2 flex-wrap">
    @if($paginated->onFirstPage())
        <span class="btn btn-light btn-sm disabled">&laquo;</span>
    @else
        <a href="{{ $paginated->appends(request()->query())->previousPageUrl() }}" class="btn btn-light btn-sm">&laquo;</a>
    @endif

    @for ($i = max(1, $paginated->currentPage()-2); $i <= min($paginated->lastPage(), $paginated->currentPage()+2); $i++)
        @if ($i == $paginated->currentPage())
            <span class="btn btn-primary btn-sm">{{ $i }}</span>
        @else
            <a href="{{ $paginated->appends(request()->query())->url($i) }}" class="btn btn-light btn-sm">{{ $i }}</a>
        @endif
    @endfor

    @if($paginated->hasMorePages())
        <a href="{{ $paginated->appends(request()->query())->nextPageUrl() }}" class="btn btn-light btn-sm">&raquo;</a>
    @else
        <span class="btn btn-light btn-sm disabled">&raquo;</span>
    @endif
</div>
@endif
