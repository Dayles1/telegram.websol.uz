@extends('layouts.app')

@section('content')
<div class="container" style="max-width:900px;">

    {{-- Tab switch --}}
    <div class="d-flex gap-2 mb-3">
        <a href="{{ route('catalogs.index', array_merge(request()->query(), ['tab' => 'my'])) }}"
            class="btn btn-sm {{ $tab === 'my' ? 'btn-primary' : 'btn-light' }}">
            Mening Cataloglarim
        </a>

        @if (auth()->user()->hasRole('superadmin'))
            <a href="{{ route('catalogs.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                class="btn btn-sm {{ $tab === 'all' ? 'btn-primary' : 'btn-light' }}">
                Umumiy Cataloglar
            </a>
        @else
            {{-- oddiy foydalanuvchi uchun ham "all" ko'rinadi (global kataloglar) --}}
            <a href="{{ route('catalogs.index', array_merge(request()->query(), ['tab' => 'all'])) }}"
                class="btn btn-sm {{ $tab === 'all' ? 'btn-primary' : 'btn-light' }}">
                Global Cataloglar
            </a>
        @endif
    </div>

    {{-- Search + Create --}}
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <form method="GET" action="{{ route('catalogs.index') }}"
            class="d-flex gap-2 align-items-center flex-grow-1 flex-sm-grow-0" style="max-width:400px;">
            <input type="text" name="search" value="{{ request('search') }}"
                placeholder="Search by catalog or user..." class="form-control">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
        </form>

        <div class="ms-auto">
            <a href="{{ route('catalogs.create') }}" class="btn btn-success btn-sm px-3">+ Create</a>
        </div>
    </div>

    {{-- Determine which paginator to use (controller already provides these) --}}
    @if ($tab === 'my')
        @php $catalogs = $myCatalogs; @endphp
    @else
        @php $catalogs = $allCatalogs; @endphp
    @endif

    {{-- Empty state --}}
    @if ($catalogs->isEmpty())
        <div class="py-4 text-center text-muted">Cataloglar mavjud emas.</div>
    @else
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>User</th>
                    <th>Title</th>
                    <th>Global</th>
                    <th>Timer</th>
                    <th>Peers</th>
                    <th>Yaratilgan vaqti</th>
                    <th class="text-end">Amallar</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($catalogs as $c)
                    <tr>
                        {{-- Absolute index across pagination --}}
                        <td>{{ $catalogs->firstItem() ? $catalogs->firstItem() + $loop->index : $loop->iteration }}</td>

                        <td>{{ $c->user->name ?? '-' }}</td>
                        <td>{{ $c->title }}</td>

                        <td>
                            @if($c->is_global)
                                <span class="badge bg-success">Yes</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        <td>
                            @if($c->timer_minutes && (int)$c->timer_minutes > 0)
                                <span class="badge bg-info">{{ $c->timer_minutes }} min</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        <td>
                            @if ($c->peers && count($c->peers) > 0)
                                <div class="catalog-dropdown" style="position:relative; display:inline-block;">
                                    <button class="btn btn-sm btn-outline-secondary">
                                        Peers ({{ count($c->peers) }}) ▼
                                    </button>
                                    <div class="catalog-dropdown-content"
                                        style="display:none; position:absolute; top:100%; left:0; background:#f8f9fa; min-width:200px; max-height:200px; overflow-y:auto; border:1px solid #ccc; border-radius:4px; padding:5px 10px; z-index:100;">
                                        <ul style="list-style:none; margin:0; padding:0;">
                                            @foreach ($c->peers as $peer)
                                                <li style="padding:2px 0; border-bottom:1px solid #ddd;">{{ $peer }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            @else
                                <span class="text-muted">No peers</span>
                            @endif
                        </td>

                        <td>{{ $c->created_at->format('Y-m-d H:i') }}</td>

                        <td class="text-end d-flex gap-2 justify-content-end">

                            {{-- Edit: faqat owner yoki superadmin --}}
                            @if(auth()->user()->hasRole('superadmin') || $c->user_id === auth()->id())
                                <a href="{{ route('catalogs.edit', $c->id) }}?{{ http_build_query(request()->query()) }}" class="btn btn-sm btn-primary" title="Edit">
                                    <i class="fas fa-pen"></i>
                                </a>
                            @endif

                            {{-- Delete: faqat owner yoki superadmin --}}
                            @if(auth()->user()->hasRole('superadmin') || $c->user_id === auth()->id())
                                <form action="{{ route('catalogs.destroy', $c->id) }}" method="POST" onsubmit="return confirm('Delete?')" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    {{-- Preserve query so redirect back keeps tab/search/page --}}
                                    <input type="hidden" name="tab" value="{{ request('tab') }}">
                                    <input type="hidden" name="search" value="{{ request('search') }}">
                                    <input type="hidden" name="myPage" value="{{ request('myPage') }}">
                                    <input type="hidden" name="allPage" value="{{ request('allPage') }}">
                                    <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                            @endif

                            {{-- Duplicate / Export behaviour --}}
                            @if(auth()->user()->hasRole('superadmin'))
                                {{-- admin: opens modal to choose users --}}
                                <button class="btn btn-sm btn-success export-btn" data-catalog="{{ $c->id }}" title="Duplicate for other users">
                                    <i class="fas fa-download"></i> Duplicate
                                </button>
                            @else
                                {{-- normal user: duplicate immediately into their own account --}}
                                <button class="btn btn-sm btn-success duplicate-self-btn" data-catalog="{{ $c->id }}" title="Duplicate to my account">
                                    <i class="fas fa-download"></i> Duplicate
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Pagination --}}
        @if ($catalogs->lastPage() > 1)
            <div class="d-flex justify-content-center align-items-center gap-2 mt-2 flex-wrap">
                @if ($catalogs->onFirstPage())
                    <span class="btn btn-light btn-sm disabled">&laquo;</span>
                @else
                    <a href="{{ $catalogs->appends(request()->query())->previousPageUrl() }}" class="btn btn-light btn-sm">&laquo;</a>
                @endif

                @for ($i = max(1, $catalogs->currentPage() - 2); $i <= min($catalogs->lastPage(), $catalogs->currentPage() + 2); $i++)
                    @if ($i == $catalogs->currentPage())
                        <span class="btn btn-primary btn-sm">{{ $i }}</span>
                    @else
                        <a href="{{ $catalogs->appends(request()->query())->url($i) }}" class="btn btn-light btn-sm">{{ $i }}</a>
                    @endif
                @endfor

                @if ($catalogs->hasMorePages())
                    <a href="{{ $catalogs->appends(request()->query())->nextPageUrl() }}" class="btn btn-light btn-sm">&raquo;</a>
                @else
                    <span class="btn btn-light btn-sm disabled">&raquo;</span>
                @endif
            </div>
        @endif
    @endif

</div>

{{-- Hidden form for non-admin immediate duplicate (no-AJAX) --}}
<form id="duplicateSelfForm" method="POST" action="{{ route('catalogs.duplicate') }}" style="display:none;">
    @csrf
    <input type="hidden" name="catalog_id" id="dupCatalogId" value="">
    <input type="hidden" name="user_ids[]" value="{{ auth()->id() }}">
</form>

{{-- Export Modal (admin) --}}
<div id="exportModal"
    style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:200; justify-content:center; align-items:center;">
    <div
        style="background:#fff; padding:20px; border-radius:8px; min-width:300px; max-width:400px; max-height:400px; min-height:400px; display:flex; flex-direction:column;">
        <h5>Nusxa oluvchi</h5>
        <input type="text" id="userSearch" placeholder="Find user..."
            style="margin:10px 0; padding:5px; border:1px solid #ccc; border-radius:4px;">
        <form id="exportForm" method="POST" action="{{ route('catalogs.duplicate') }}"
            style="display:flex; flex-direction:column; flex:1; overflow:hidden;">
            @csrf
            <input type="hidden" name="catalog_id" id="exportCatalogId" value="">
            <div id="userList"
                style="overflow-y:auto; flex:1; min-height:50px; border:1px solid #ccc; border-radius:4px; padding:5px;">
                @foreach ($users as $u)
                    <div style="padding:5px 0;">
                        <input type="checkbox" id="user-{{ $u->id }}" name="user_ids[]" value="{{ $u->id }}">
                        <label for="user-{{ $u->id }}">{{ $u->name }}</label>
                    </div>
                @endforeach
            </div>
            <div class="mt-3 d-flex justify-content-end gap-2">
                <button type="button" id="exportCancel" class="btn btn-secondary btn-sm">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">Duplicate</button>
            </div>
        </form>
    </div>
</div>

{{-- Scripts --}}
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Catalog dropdown hover
    document.querySelectorAll('.catalog-dropdown').forEach(function(drop) {
        const btn = drop.querySelector('button');
        const content = drop.querySelector('.catalog-dropdown-content');
        if (btn && content) {
            btn.addEventListener('mouseenter', () => content.style.display = 'block');
            drop.addEventListener('mouseleave', () => content.style.display = 'none');
        }
    });

    // Export modal (admin)
    const modal = document.getElementById('exportModal');
    const exportCatalogId = document.getElementById('exportCatalogId');
    const userSearch = document.getElementById('userSearch');
    const userList = document.getElementById('userList');

    document.querySelectorAll('.export-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            exportCatalogId.value = btn.dataset.catalog;
            modal.style.display = 'flex';
            userSearch.value = '';
            Array.from(userList.children).forEach(div => div.style.display = 'block');
        });
    });

    document.getElementById('exportCancel').addEventListener('click', () => {
        modal.style.display = 'none';
    });

    userSearch.addEventListener('input', () => {
        const search = userSearch.value.toLowerCase();
        Array.from(userList.children).forEach(div => {
            const label = div.querySelector('label').textContent.toLowerCase();
            div.style.display = label.includes(search) ? 'block' : 'none';
        });
    });

    // Non-admin immediate duplicate (no-AJAX): use hidden form submit
    document.querySelectorAll('.duplicate-self-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const catalogId = btn.dataset.catalog;
            if (!confirm('Bu catalogni oʻzingizga nusxalamoqchimisiz?')) return;
            document.getElementById('dupCatalogId').value = catalogId;
            document.getElementById('duplicateSelfForm').submit();
        });
    });

    // Toast (optional)
    const toast = document.getElementById("toastMessage");
    if (toast) {
        setTimeout(() => { toast.style.opacity = "1"; }, 50);
        setTimeout(() => { toast.style.opacity = "0"; }, 3000);
        setTimeout(() => { if (toast) toast.remove(); }, 3500);
    }
});
</script>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" crossorigin="anonymous" />
@endsection
