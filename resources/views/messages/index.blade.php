@extends('layouts.app')

@section('content')
    <h3 class="mb-3 text-center">Xabarlar tarixi</h3>

    <div class="container">

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
                        <th style="width:120px;">Amallar</th>
                    </tr>
                </thead>
                <tbody id="groups-tbody">
                    @include('messages._rows', ['groups' => $groups])
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div id="pagination-area" class="mt-3 d-flex justify-content-center">
            @include('messages._links', ['groups' => $groups])
        </div>

    </div>

    <style>
        /* Catalog dropdown styling */
        .catalog-btn { background: none; border: none; cursor: pointer; padding: 0; display: inline-flex; align-items: center; gap: 5px; font-weight: 500; }
        .catalog-dropdown-content { display: none; position: absolute; top: 100%; left: 0; background: #f8f9fa; min-width: 150px; max-height: 150px; overflow-y: auto; border: 1px solid #ccc; border-radius: 4px; padding: 5px 10px; z-index: 9999; }
        .catalog-dropdown-content ul { list-style: none; margin: 0; padding: 0; }
        .catalog-dropdown-content li { padding: 2px 0; border-bottom: 1px solid #ddd; }

        .progress-wrapper { background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden; }
        .progress-bar { height: 8px; transition: 0.3s; }
        .table td, .table th { vertical-align: middle; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const statsUrl = "{{ route('messages.stats') }}";
            const dataUrl = "{{ route('messages.data') }}";
            const POLL_STATS_MS = 5000;   // 1s
            const POLL_FULL_MS  = 10000;  // 10s

            function currentPage() {
                return new URLSearchParams(window.location.search).get('page') || 1;
            }

            function initCatalogs() {
                document.querySelectorAll('.catalog-dropdown').forEach(function(drop) {
                    const newDrop = drop.cloneNode(true);
                    drop.parentNode.replaceChild(newDrop, drop);
                    const btn = newDrop.querySelector('.catalog-btn');
                    const content = newDrop.querySelector('.catalog-dropdown-content');
                    if (btn && content) {
                        btn.addEventListener('mouseenter', () => content.style.display = 'block');
                        newDrop.addEventListener('mouseleave', () => content.style.display = 'none');
                    }
                });
            }

            function applyStatsToDOM(groups) {
                if (!Array.isArray(groups)) return;

                groups.forEach(g => {
                    const row = document.querySelector(`tr[data-group-id="${g.id}"]`);
                    const progressRow = document.querySelector(`tr[data-group-id-row="${g.id}"]`);
                    if (row) {
                        const sentEl = row.querySelector('.js-sent-count');
                        const msgEl = row.querySelector('.js-messages-count');
                        const startedEl = row.querySelector('.js-started-at');
                        // const textEl = row.querySelector('.js-text');

                        if (sentEl) sentEl.textContent = g.sent_count;
                        if (msgEl) msgEl.textContent = g.messages_count;
                        if (startedEl) startedEl.textContent = g.started_at ?? '-';
                        // if (textEl) textEl.textContent = g.text_preview;
                    }

                    if (progressRow) {
                        const bar = progressRow.querySelector('.js-progress-bar');
                        const pct = progressRow.querySelector('.js-percent-text');
                        if (bar) {
                            bar.style.width = (g.percent ?? 0) + '%';
                            bar.style.background = (g.percent === 100) ? '#28a745' : '#007bff';
                        }
                        if (pct) pct.textContent = (g.percent ?? 0) + '%';
                    }
                });
            }

            async function refreshStats(page = null) {
                try {
                    const p = page || currentPage();
                    const res = await fetch(statsUrl + '?page=' + p + '&_=' + Date.now(), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!res.ok) throw new Error('Stats network error: ' + res.status);
                    const data = await res.json();
                    if (data.groups) applyStatsToDOM(data.groups);
                } catch (err) {
                    console.error('refreshStats error:', err);
                }
            }

            async function refreshFull(page = null) {
                try {
                    const p = page || currentPage();
                    const url = dataUrl + '?page=' + p + '&_=' + Date.now();
                    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) throw new Error('Full refresh network error: ' + res.status);
                    const data = await res.json();
                    if (data.html !== undefined) {
                        const tbody = document.getElementById('groups-tbody');
                        if (tbody) tbody.innerHTML = data.html;
                    }
                    if (data.links !== undefined) {
                        const pag = document.getElementById('pagination-area');
                        if (pag) pag.innerHTML = data.links;
                    }
                    initCatalogs();
                } catch (err) {
                    console.error('refreshFull error:', err);
                }
            }

            // Pagination delegate
            document.body.addEventListener('click', function(e) {
                const target = e.target.closest('#pagination-area a');
                if (!target) return;
                e.preventDefault();

                const href = target.getAttribute('href');
                let page = 1;
                if (href) {
                    try {
                        const u = new URL(href, window.location.origin);
                        page = u.searchParams.get('page') || 1;
                    } catch (ex) {
                        page = target.dataset.page || 1;
                    }
                } else {
                    page = target.dataset.page || 1;
                }

                const url = new URL(window.location.href);
                url.searchParams.set('page', page);
                history.replaceState(null, '', url.toString());

                refreshFull(page);
                refreshStats(page);
            });

            // start timers
            let statsTimer = setInterval(() => refreshStats(), POLL_STATS_MS);
            let fullTimer  = setInterval(() => refreshFull(), POLL_FULL_MS);

            // initial
            initCatalogs();
            refreshStats();
            // refreshFull(); // uncomment if you want an immediate full HTML load on page open
        });
    </script>
@endsection
