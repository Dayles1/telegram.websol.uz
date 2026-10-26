@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-center align-items-center" style="min-height:85vh; background:#f0f2f5;">
        <div class="card shadow-lg p-4 rounded-4" style="max-width:600px; width:100%;">

            <h3 class="text-center mb-4 fw-bold">Telegram Xabar Yuborish</h3>
            <div class="position-fixed top-0 end-0 p-3" style="z-index: 9999">
                <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
                    <div class="d-flex">
                        <div class="toast-body" id="toastMessage">
                            Success message
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto"
                            data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>

            <!-- Errors -->
            <ul id="errorList" class="list-group mb-3 d-none"></ul>

            <div class="mb-3 phone-wrapper">
                <label class="form-label fw-semibold">Jo‘natuvchi (Phone)</label>

                <div id="phoneDropdown" class="phone-dropdown" onclick="togglePhoneDropdown()">
                    <span id="phoneSelected">Tanlang...</span>
                    <span class="arrow">▾</span>
                </div>

                <!-- ✅ OCHILGAN LIST FORMANI SILJITMAYDI -->
                <div id="phoneList" class="phone-list d-none">
                    {{-- Superadminning o‘z telefonlari --}}
                    @foreach ($ownPhones as $ownPhone)
                        <div class="phone-item" onclick="selectPhone('{{ $ownPhone->id }}', '{{ $ownPhone->phone }}')">
                            {{ $ownPhone->phone }}
                        </div>
                    @endforeach

                    {{-- Boshqa foydalanuvchilar telefonlari --}}
                    @foreach ($otherPhones as $phone)
                        <div class="phone-item"
                            onclick="selectPhone('{{ $phone->id }}', '{{ $phone->user->name ?? 'Noma’lum' }}: {{ $phone->phone }}')">
                            {{ Str::limit($phone->user->name ?? 'Noma’lum', 18) }}: {{ $phone->phone }}
                        </div>
                    @endforeach
                </div>

                <input type="hidden" id="phone" name="phone_id">
                <small class="text-muted">Bitta telefon tanlanadi</small>
            </div>




            <!-- CATALOG DROPDOWN -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Qabul qiluvchi (Catalog)</label>

                <div class="dropdown">
                    <button
                        class="btn btn-outline-secondary w-100 text-start d-flex justify-content-between align-items-center"
                        type="button" id="catalogDropdown" data-bs-toggle="dropdown">
                        <span id="selectedCatalogs">Kataloglarni tanlang...</span>
                        <span class="bi bi-chevron-down"></span>
                    </button>

                    <div class="dropdown-menu w-100" aria-labelledby="catalogDropdown"
                        style="max-height:300px; overflow-y:auto;" onclick="event.stopPropagation()">


                        <!-- 🔍 SEARCH -->
                        {{-- <input type="text"
                           id="catalogSearch"
                           class="form-control mb-2"
                           placeholder="Qidirish..."
                           onkeyup="filterCatalogs()"> --}}

                        <div id="catalogList">

                            {{-- USER CATALOGS --}}
                            @foreach ($catalogs as $cat)
                                <div class="dropdown-item px-3 d-flex align-items-center catalog-item"
                                    onclick="toggleCatalogByDomId('user_cat_{{ $cat->id }}')">
                                    <input class="form-check-input me-2" type="checkbox" value="{{ $cat->id }}"
                                        id="user_cat_{{ $cat->id }}" data-cat-id="{{ $cat->id }}"
                                        onclick="event.stopPropagation(); toggleCatalogByDomId(this.id)">
                                    <label class="form-check-label flex-grow-1" for="user_cat_{{ $cat->id }}">
                                        {{ $cat->title }}
                                    </label>
                                </div>
                            @endforeach


                            {{-- GLOBAL SEPARATOR --}}
                            @if ($globalCatalogs->count())
                                <div class="dropdown-divider"></div>
                                <div class="px-3 py-1 text-muted small fw-bold">
                                    🌐 Global kataloglar
                                </div>
                            @endif

                            {{-- GLOBAL CATALOGS --}}
                            @foreach ($globalCatalogs as $cat)
                                <div class="dropdown-item px-3 d-flex align-items-center catalog-item global"
                                    onclick="toggleCatalogByDomId('global_cat_{{ $cat->id }}')">
                                    <input class="form-check-input me-2" type="checkbox" value="{{ $cat->id }}"
                                        id="global_cat_{{ $cat->id }}" data-cat-id="{{ $cat->id }}"
                                        onclick="event.stopPropagation(); toggleCatalogByDomId(this.id)">
                                    <label class="form-check-label flex-grow-1 text-primary"
                                        for="global_cat_{{ $cat->id }}">
                                        {{ $cat->title }}
                                    </label>
                                </div>
                            @endforeach

                        </div>


                    </div>
                </div>

                <small class="text-muted">Bir nechta katalog tanlashingiz mumkin</small>
            </div>

            <!-- MESSAGE -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Xabar matni</label>
                <textarea id="message" class="form-control" rows="4" placeholder="Xabar matni" required></textarea>
            </div>

            <!-- LOOPCOUNT + INTERVAL -->
            {{-- <div class="mb-3 row">
                <div class="col-6">
                    <label class="form-label fw-semibold">Nechta marta</label>
                    <input type="number" id="loopcount" class="form-control" placeholder="1-1000" min="1"
                        max="50">
                    <small class="text-muted">Ixtiyoriy (standart: 1)</small>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Interval (sekund)</label>
                    <input type="number" id="interval" class="form-control" placeholder="10-36000" min="10"
                        max="36000">
                    <small class="text-muted">Ixtiyoriy (standart: 10)</small>
                </div>
            </div> --}}

            <!-- SUBMIT -->
            <button id="btnSend" class="btn btn-primary w-100 py-2 mt-3" onclick="sendMassMessage()">Yuborish</button>

        </div>
    </div>

    <!-- ==================== CSS ==================== -->
    <style>
        .card {
            border-radius: 1rem;
        }

        textarea {
            resize: none;
        }

        .list-group-item {
            border: none;
            padding: .4rem .8rem;
            font-size: .875rem;
        }

        .list-group-item-danger {
            background: #f8d7da;
            color: #842029;
            border-radius: .5rem;
        }

        .catalog-item.global {
            background: #f5f9ff;
        }

        .catalog-item.global:hover {
            background: #e9f1ff;
        }

        .catalog-item input[type="checkbox"] {
            pointer-events: none;
        }


        .spinner {
            width: 18px;
            height: 18px;
            border: 3px solid #fff;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            display: inline-block;
            margin-right: 5px;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .dropdown-menu {
            z-index: 9999 !important;
        }

        .dropdown-item {
            cursor: pointer;
            user-select: none;
        }

        .phone-dropdown {
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 6px 10px;
            background: #fff;

            font-size: 13px;
            cursor: pointer;

            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .phone-list {
            border: 1px solid #ced4da;
            border-radius: 6px;
            margin-top: 4px;
            max-height: 180px;
            overflow-y: auto;
            background: #fff;
            z-index: 9999;
            position: relative;
        }

        .catalog-item input:checked+label {
            font-weight: 600;
        }


        .phone-item {
            padding: 6px 10px;
            font-size: 13px;
            cursor: pointer;
            white-space: nowrap;
        }

        .phone-item:hover {
            background: #f1f1f1;
        }

        .arrow {
            font-size: 12px;
        }

        .phone-wrapper {
            position: relative;
            /* ✅ ANCHOR */
        }

        .phone-list {
            position: absolute;
            /* ✅ OQIMDAN CHIQDI */
            top: 100%;
            /* ✅ dropdown ostidan ochiladi */
            left: 0;
            width: 100%;
            z-index: 9999;

            border: 1px solid #ced4da;
            border-radius: 6px;
            margin-top: 4px;
            max-height: 180px;
            overflow-y: auto;
            background: #fff;
        }
    </style>

    <!-- ==================== JAVASCRIPT ==================== -->
    <script>
        function toggleCatalogByDomId(domId) {
            const box = document.getElementById(domId);
            if (!box) return;

            // new state we want (toggle current)
            const newState = !box.checked;

            // canonical catalog id (as string)
            const catId = box.dataset.catId;
            if (!catId) return;

            // find all checkboxes that represent this catalog (user/global duplicates)
            const all = document.querySelectorAll(`input[type="checkbox"][data-cat-id="${catId}"]`);
            all.forEach(cb => {
                cb.checked = newState;
            });

            updateSelectedCatalogs();
        }

        function updateSelectedCatalogs() {
            // all checked boxes that have data-cat-id attribute
            const checkedBoxes = Array.from(document.querySelectorAll('input[type=checkbox][data-cat-id]:checked'));

            // Map to ensure uniqueness by catalog id
            const unique = new Map();
            checkedBoxes.forEach(cb => {
                const id = cb.dataset.catId;
                if (!unique.has(id)) {
                    // try to get label text: prefer closest label
                    let label = '';
                    // prefer label with for attribute referencing this checkbox
                    const lbl = document.querySelector(`label[for="${cb.id}"]`);
                    if (lbl) {
                        label = lbl.innerText.trim();
                    } else {
                        // fallback
                        const possible = cb.nextElementSibling || cb.closest('.catalog-item')?.querySelector(
                            'label');
                        label = (possible && possible.innerText) ? possible.innerText.trim() : id;
                    }
                    unique.set(id, label);
                }
            });

            const names = Array.from(unique.values());
            const ids = Array.from(unique.keys());

            const span = document.getElementById('selectedCatalogs');
            if (!span) return;

            if (names.length === 0) {
                span.textContent = "Kataloglarni tanlang...";
            } else if (names.length > 3) {
                span.textContent = `${names.slice(0,3).join(', ')} va yana ${names.length - 3} ta`;
            } else {
                span.textContent = names.join(', ');
            }

            // store ids somewhere if you want (optional). For form submission we read them in sendMassMessage()
            // e.g. window.selectedCatalogIds = ids;
        }
        const checkedBoxes = Array.from(document.querySelectorAll('input[type=checkbox][data-cat-id]:checked'));
        const uniqueIds = Array.from(new Set(checkedBoxes.map(cb => cb.dataset.catId))).map(x => Number(x));

        // payload
        const payload = {
            phone_id: document.getElementById('phone').value,
            catalog_ids: uniqueIds,
            message: document.getElementById('message').value,
            loopcount: 1,
            interval: 10
        };

        function togglePhoneDropdown() {
            document.getElementById('phoneList').classList.toggle('d-none');
        }

        function selectPhone(id, text) {
            document.getElementById('phone').value = id; // POST ga ketadi
            document.getElementById('phoneSelected').innerText = text;
            document.getElementById('phoneList').classList.add('d-none');
        }

        // TASHQARIGA BOSILSA YOPILADI
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.phone-dropdown') && !e.target.closest('.phone-list')) {
                document.getElementById('phoneList').classList.add('d-none');
            }
        });

        // QATOR BOSILGANDA CHECKBOX TOGGLE
        function toggleCatalog(id) {
            const box = document.getElementById('cat_' + id);
            box.checked = !box.checked;
            updateSelectedCatalogs();
        }


        // TANLANGAN CATALOG KO‘RSATISH
        

        // 🔍 LIVE SEARCH
        function filterCatalogs() {
            let q = document.getElementById("catalogSearch").value.toLowerCase();
            let items = document.querySelectorAll("#catalogList .dropdown-item");

            items.forEach(item => {
                let title = item.querySelector("label").innerText.toLowerCase();
                item.style.display = title.includes(q) ? "flex" : "none";
            });
        }


        // VALIDATION
        function validateForm() {
            const errors = {};
            const phone = document.getElementById('phone').value;
            const catalog_ids = Array.from(
    new Set(
        Array.from(document.querySelectorAll(
            'input[type=checkbox][data-cat-id]:checked'
        )).map(cb => cb.dataset.catId)
    )
);

            const message = document.getElementById('message').value.trim();

            if (!phone) errors.phone = ['Telefon raqam tanlanmagan'];
            if (catalog_ids.length === 0) errors.catalogs = ['Hech bo‘lmaganda bitta katalog tanlang'];
            if (!message) errors.message = ['Xabar matni bo‘sh'];

            showErrors(errors);
            return Object.keys(errors).length === 0;
        }

        // XATO CHIQARISH
        function showErrors(errors) {
            let ul = document.getElementById('errorList');
            ul.innerHTML = '';
            if (!errors || Object.keys(errors).length === 0) {
                ul.classList.add('d-none');
                return;
            }
            for (const key in errors) {
                errors[key].forEach(msg => {
                    let li = document.createElement('li');
                    li.textContent = msg;
                    li.className = 'list-group-item list-group-item-danger';
                    ul.appendChild(li);
                });
            }
            ul.classList.remove('d-none');
        }

        // LOADING
        function loading(btn, state) {
            if (state) {
                btn.dataset.original = btn.innerHTML;
                btn.innerHTML = `<span class="spinner"></span> Yuborilmoqda...`;
                btn.disabled = true;
            } else {
                btn.innerHTML = btn.dataset.original;
                btn.disabled = false;
            }
        }

        // SEND AJAX
        function sendMassMessage() {
            if (!validateForm()) return;

            const checked = document.querySelectorAll(
    'input[type=checkbox][data-cat-id]:checked'
);

const catalog_ids = Array.from(
    new Set(Array.from(checked).map(cb => cb.dataset.catId))
).map(id => Number(id));


            const payload = {
                phone_id: document.getElementById('phone').value,
                catalog_ids: catalog_ids,
                message: document.getElementById('message').value,
                loopcount: 1,
                interval: 10
            };

            const btn = document.getElementById('btnSend');
            loading(btn, true);

            fetch('{{ route('telegram.send-messages') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify(payload)
                })
                .then(async response => {
                    loading(btn, false);
                    const data = await response.json();

                    if (!response.ok) {
                        // Backenddan kelgan xatolarni chiqarish
                        if (data.errors) {
                            showErrors(data.errors);
                        } else if (data.message) {
                            showErrors({
                                general: [data.message]
                            });
                        }
                        return;
                    }

                    // Success
                    const toastEl = new bootstrap.Toast(document.getElementById('successToast'));
                    document.getElementById('toastMessage').innerText = data.message;
                    toastEl.show();

                    // Optional: 1-2 sekunddan keyin redirect
                    setTimeout(() => {
                        window.location.href = "{{ route('message-groups.dashboard') }}";
                    }, 1500);
                })
                .catch(err => {
                    loading(btn, false);
                    console.error(err);
                    showErrors({
                        general: ['Server bilan bog‘lanishda xatolik yuz berdi']
                    });
                });
        }
    </script>
@endsection
