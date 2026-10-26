@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-center align-items-start" style="min-height:60vh; background:#f0f2f5; padding-top:50px;">
    <div class="card p-4 shadow-lg" style="width:600px; min-height:500px; background:#fff;">
        <h3 class="mb-4 text-center">Create Catalog</h3>

        <form action="{{ route('catalogs.store') }}" method="POST">
            @csrf

            <!-- Catalog Title -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Catalog Title</label>
                <input type="text" name="title" class="form-control" placeholder="Catalog nomi" value="{{ old('title') }}" required>
            </div>

            <!-- is_global (faqat superadminga ko'rinsin) -->
            @if(auth()->user()->hasRole('superadmin'))
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_global" id="is_global" value="1" {{ old('is_global') ? 'checked' : '' }}>
                    <label class="form-check-label" for="is_global">Global (hamma uchun ko'rinadigan)</label>
                </div>
            @else
                <!-- non-admin uchun hidden saqlaymiz (default 0) -->
                <input type="hidden" name="is_global" value="0">
            @endif

            <!-- Timer (daqiqada) - hamma uchun ko'rinadi -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Timer (Standart 10 daqiqa)</label>
                <input type="number" name="timer_minutes" class="form-control" min="1" placeholder="Masalan: 10 yoki 30" value="10">
                <div class="form-text">Agar to‘ldirsangiz — shu catalogni har bir telefon shu muddat ichida qayta ishlay olmaydi.</div>
            </div>

            <!-- Peers label -->
            <label class="form-label fw-semibold">Peers (Telegram link yoki username)</label>

            <!-- Peers container -->
            <div id="peersContainer" class="p-2 mb-2" style="border:1px solid #ccc; min-height:150px; max-height:250px; overflow-y:auto; border-radius:6px; background:#f8f9fa;">
                <!-- Qo‘shilgan peers shu yerga keladi -->
            </div>

            <!-- Add new peer(s) manually -->
            <div class="d-flex gap-2 mb-2">
                <textarea id="peer-input" class="form-control" placeholder="@username" rows="4"></textarea>
                <div class="d-flex flex-column gap-2">
                    <button type="button" id="add-peer" class="btn btn-secondary">Add</button>
                    {{-- <button type="button" id="clear-peers" class="btn btn-outline-danger" title="Hammasini o‘chiradi">Clear</button> --}}
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 mt-3">Save Catalog</button>
        </form>
    </div>
</div>

<script>
    const addBtn = document.getElementById('add-peer');
    const input = document.getElementById('peer-input');
    const peersContainer = document.getElementById('peersContainer');
    // const clearBtn = document.getElementById('clear-peers'); // commented in template

    function existingPeersSet() {
        const sets = new Set();
        peersContainer.querySelectorAll('input[name="peers[]"]').forEach(i => {
            if (i.value) sets.add(i.value.trim());
        });
        return sets;
    }

    function createPeerElement(value) {
        const div = document.createElement('div');
        div.className = 'peer-item d-flex justify-content-between align-items-center';
        div.style.borderBottom = '1px solid #ddd';
        div.style.padding = '4px 0';

        const span = document.createElement('span');
        span.textContent = value;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-sm btn-danger remove-peer';
        removeBtn.textContent = 'x';
        removeBtn.addEventListener('click', () => div.remove());

        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'peers[]';
        hidden.value = value;

        div.appendChild(span);
        div.appendChild(removeBtn);
        div.appendChild(hidden);

        return div;
    }

    function normalizePeer(raw) {
        let v = raw.trim();
        if (!v) return null;
        if (!v.startsWith('http') && !v.startsWith('@')) {
            v = '@' + v;
        }
        return v;
    }

    function splitBulk(value) {
        return value.split(/(?:\r?\n|->|,|;|\s)+/).filter(Boolean);
    }

    function addPeersFromString(value) {
        const parts = splitBulk(value);
        const existing = existingPeersSet();
        let added = 0;
        for (let p of parts) {
            const n = normalizePeer(p);
            if (!n) continue;
            if (existing.has(n)) continue;
            const el = createPeerElement(n);
            peersContainer.appendChild(el);
            existing.add(n);
            added++;
        }
        return added;
    }

    addBtn.addEventListener('click', () => {
        const value = input.value.trim();
        if (!value) return;
        const count = addPeersFromString(value);
        if (count > 0) {
            addBtn.textContent = `Added ${count}`;
            setTimeout(() => addBtn.textContent = 'Add', 900);
        } else {
            addBtn.textContent = 'Nothing new';
            setTimeout(() => addBtn.textContent = 'Add', 900);
        }
        input.value = '';
        input.focus();
    });

    input.addEventListener('keydown', (e) => {
        if (e.ctrlKey && e.key === 'Enter') {
            e.preventDefault();
            addBtn.click();
        }
    });

    // clearBtn && clearBtn.addEventListener('click', ... ) // disabled as in your original
</script>
@endsection
