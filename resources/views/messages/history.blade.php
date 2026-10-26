@extends('layouts.app')

@section('content')
<h3 class="mb-3 text-center">Xabarlar tarixi</h3>

<div class="container">

    {{-- Search --}}
    <form method="GET" class="d-flex gap-2 mb-3" style="max-width:400px;">
        <input type="text" name="search" value="{{ request('messages.history') }}" placeholder="Search by user, phone or catalog..."
            class="form-control">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
    </form>

    <div class="table-responsive" style="min-height:70vh;">
        <table class="table table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Jonatuvchi</th>
                    <th>Telefon</th>
                    <th>Catalogs</th>
                    <th>Xabarlar soni</th>
                    <th>Jonatilganlar soni</th>
                    <th>Xabar matni</th>
                    <th>Boshlangan vaqt</th>
                    <th style="width:110px;">Amallar</th>
                </tr>
            </thead>
            <tbody>
                @foreach($groups as $g)
                <tr>
                    <td>{{ $g->creator->name ?? '-' }}</td>
                    <td>{{ $g->phone->phone ?? '-' }}</td>
                    <td>
                        @php
                            $catalogs = $g->catalogs ?? collect([]);
                            $firstCatalog = $catalogs->first();
                            $remainingCatalogs = $catalogs->slice(1);
                        @endphp
                        @if($firstCatalog)
                            <div class="catalog-dropdown-wrapper" style="position: relative;">
                                <button class="catalog-btn" style="background:none; border:none; cursor:pointer; padding:0; display:inline-flex; align-items:center; gap:5px;">
                                    {{ $firstCatalog->title ?? $firstCatalog }} <span>▼</span>
                                </button>
                                @if($remainingCatalogs->isNotEmpty())
                                    <ul class="catalog-dropdown" style="display:none; list-style:none; margin:0; padding:5px 10px; background:#f8f9fa; border:1px solid #ccc; border-radius:4px; max-height:200px; overflow-y:auto; box-shadow:0 2px 8px rgba(0,0,0,0.15); position:fixed; z-index:9999;">
                                        @foreach($remainingCatalogs as $cat)
                                            <li style="padding:2px 0; border-bottom:1px solid #ddd;">{{ $cat->title ?? $cat }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @else
                            <span>No catalogs</span>
                        @endif
                    </td>
                    <td>{{ $g->messages_count }}</td>
                    <td>{{ $g->sent_count }}</td>
                    <td style="max-width:280px; white-space: nowrap; overflow:hidden; text-overflow: ellipsis;">
                        {{ $g->com_message_text ?? $g->text }}
                    </td>
                    <td>{{ $g->started_at?->format('Y-m-d H:i') ?? '-' }}</td>

                    {{-- SHOW button --}}
                    <td class="text-center">
                        <a href="{{ route('messages.show', $g->id) }}" class="btn btn-sm btn-primary w-100">Show</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Mini Pagination --}}
    @if ($groups->lastPage() > 1)
    <div class="d-flex justify-content-center align-items-center gap-2 mt-3 flex-wrap">
        @if($groups->onFirstPage())
            <span class="btn btn-light btn-sm disabled">&laquo;</span>
        @else
            <a href="{{ $groups->previousPageUrl() }}" class="btn btn-light btn-sm">&laquo;</a>
        @endif

        @for ($i = max(1, $groups->currentPage()-2); $i <= min($groups->lastPage(), $groups->currentPage()+2); $i++)
            @if ($i == $groups->currentPage())
                <span class="btn btn-primary btn-sm">{{ $i }}</span>
            @else
                <a href="{{ $groups->url($i) }}" class="btn btn-light btn-sm">{{ $i }}</a>
            @endif
        @endfor

        @if($groups->hasMorePages())
            <a href="{{ $groups->nextPageUrl() }}" class="btn btn-light btn-sm">&raquo;</a>
        @else
            <span class="btn btn-light btn-sm disabled">&raquo;</span>
        @endif
    </div>
    @endif

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.catalog-dropdown-wrapper').forEach(function(wrapper) {
        const btn = wrapper.querySelector('.catalog-btn');
        const dropdown = wrapper.querySelector('.catalog-dropdown');
        if (!btn || !dropdown) return;

        btn.addEventListener('mouseenter', function() {
            const rect = btn.getBoundingClientRect();
            dropdown.style.top = (rect.bottom + window.scrollY) + 'px';
            dropdown.style.left = (rect.left + window.scrollX) + 'px';
            dropdown.style.display = 'block';
        });

        btn.addEventListener('mouseleave', function() {
            setTimeout(() => { if (!dropdown.matches(':hover')) dropdown.style.display = 'none'; }, 100);
        });

        dropdown.addEventListener('mouseleave', function() {
            dropdown.style.display = 'none';
        });

        dropdown.addEventListener('mouseenter', function() {
            dropdown.style.display = 'block';
        });
    });
});
</script>

<style>
.table td,
.table th {
    vertical-align: middle;
}

.catalog-dropdown::-webkit-scrollbar {
    width: 6px;
}
.catalog-dropdown::-webkit-scrollbar-thumb {
    background-color: rgba(0,0,0,0.2);
    border-radius: 3px;
}

input[type="text"] {
    border-radius: 0.3rem;
}
</style>
@endsection
