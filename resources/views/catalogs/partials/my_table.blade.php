@if ($catalogs->isEmpty())
    <div class="py-4 text-center text-muted">Cataloglar mavjud emas.</div>
@else
<table class="table table-hover align-middle">
    <thead>
        <tr>
            <th>#</th>
            <th>User</th>
            <th>Title</th>
            <th>Peers</th>
            <th>Yaratilgan vaqti</th>
            <th class="text-end">Amallar</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($catalogs as $c)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>{{ $c->user->name }}</td>
            <td>{{ $c->title }}</td>
            <td>
                @if ($c->peers && count($c->peers) > 0)
                <div class="catalog-dropdown" style="position:relative; display:inline-block;">
                    <button class="btn btn-sm btn-outline-secondary">Peers ({{ count($c->peers) }}) ▼</button>
                    <div class="catalog-dropdown-content" style="display:none; position:absolute; top:100%; left:0; background:#f8f9fa; min-width:200px; max-height:200px; overflow-y:auto; border:1px solid #ccc; border-radius:4px; padding:5px 10px; z-index:100;">
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
                <a href="{{ route('catalogs.edit', $c->id) }}" class="btn btn-sm btn-primary"><i class="fas fa-pen"></i></a>
                <form action="{{ route('catalogs.destroy', $c->id) }}" method="POST" onsubmit="return confirm('Delete?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                </form>
                <button class="btn btn-sm btn-success export-btn" data-catalog="{{ $c->id }}"><i class="fas fa-download"></i> Duplicate</button>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif
