@foreach ($groups as $g)
    @php
        $percent = $g->messages_count > 0 ? round(($g->sent_count / $g->messages_count) * 100) : 0;
        $catalogs = $g->catalogs ?? collect([]);
        $firstCatalog = $catalogs->first();
        $remainingCatalogs = $catalogs->slice(1);
    @endphp

    {{-- DATA ROW --}}
    <tr data-group-id="{{ $g->id }}">
        <td class="js-creator">{{ $g->creator->name ?? '-' }}</td>
        <td class="js-phone">{{ $g->phone->phone ?? '-' }}</td>
        <td>
            @if ($firstCatalog)
                <div class="catalog-dropdown" style="position:relative; display:inline-block; z-index:20;">
                    <button class="catalog-btn">
                        {{ $firstCatalog->title ?? $firstCatalog }} ▼
                    </button>

                    @if ($remainingCatalogs->isNotEmpty())
                        <div class="catalog-dropdown-content">
                            <ul>
                                @foreach ($remainingCatalogs as $cat)
                                    <li>{{ $cat->title ?? $cat }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @else
                <span>No catalogs</span>
            @endif
        </td>
        <td class="js-messages-count">{{ $g->messages_count }}</td>
        <td class="js-sent-count">{{ $g->sent_count }}</td>
        <td class="js-text" style="max-width:280px; white-space: nowrap; overflow:hidden; text-overflow: ellipsis;">
            {{ $g->com_message_text ?? $g->text }}
        </td>
        <td class="js-started-at">{{ $g->started_at ? \Carbon\Carbon::parse($g->started_at)->format('Y-m-d H:i') : '-' }}</td>
        <td class="text-center">
            <a href="{{ route('messages.show', $g->id) }}" class="btn btn-sm btn-primary mb-1 w-100">Show</a>

            @if ($g->status !== 'sent')
                <button type="button" class="btn btn-sm btn-danger w-100" data-bs-toggle="modal"
                        data-bs-target="#stopModal{{ $g->id }}">
                    Stop
                </button>

                <div class="modal fade" id="stopModal{{ $g->id }}" tabindex="-1"
                     aria-labelledby="stopModalLabel{{ $g->id }}" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="stopModalLabel{{ $g->id }}">Xabarni to‘xtatisish</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"
                                        aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                Rostdan ham bu xabar yuborilishini to‘xtirmoqchimisiz?
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bekor qilish</button>
                                <form action="{{ route('message-groups.cancel', $g->id) }}" method="POST" class="m-0">
                                    @csrf
                                    <button type="submit" class="btn btn-danger">Ha, to‘xtatish</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif
        </td>
    </tr>

    {{-- PROGRESS BAR ROW --}}
    <tr data-group-id-row="{{ $g->id }}">
        <td colspan="9">
            <div class="progress-wrapper">
                <div class="progress-bar js-progress-bar"
                     style="width: {{ $percent }}%; background: {{ $percent == 100 ? '#28a745' : '#007bff' }};">
                </div>
            </div>
            <div class="text-end small mt-1 js-percent-text">{{ $percent }}%</div>
        </td>
    </tr>
@endforeach
