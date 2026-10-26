@extends('layouts.app')

@section('content')
<div class="container" style="max-width:700px; margin-top:30px;">

    {{-- Success / Error messages --}}
    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">Mening Profilim</h3>
    </div>

    {{-- Profile Card --}}
    <div class="card shadow-sm rounded-4 p-4 mb-4" style="background:#fff;">

        {{-- AVATAR show --}}
<div class="d-flex align-items-center gap-3 mb-3">
    @if ($user->avatar && $user->avatar->path)
        <img id="currentAvatar" src="{{ asset('storage/' . $user->avatar->path) }}" alt="avatar"
            style="width:60px; height:60px; object-fit:cover; border-radius:50%;">
    @else
        <div id="currentAvatar"
            class="rounded-circle bg-primary text-white d-flex justify-content-center align-items-center"
            style="width:60px; height:60px; font-size:24px; font-weight:bold;">
            {{ strtoupper(substr($user->name, 0, 1)) }}
        </div>
    @endif

    <div>
        <h5 class="mb-0">{{ $user->name }}</h5>
        <small class="text-muted">{{ $user->email }}</small>
    </div>
</div>

{{-- Avatar upload form (AJAX) --}}
<form id="avatarForm" method="POST" action="{{ route('profile.avatar.update') }}" enctype="multipart/form-data" class="mb-3">
    @csrf
    <div class="d-flex align-items-center gap-2">
        <input type="file" name="avatar" id="avatarInput" accept="image/*" class="@error('avatar') is-invalid @enderror">
        <button type="submit" id="avatarUploadBtn" class="btn btn-sm btn-primary">Yuklash</button>

        @if ($user->avatar && $user->avatar->path)
            <button type="button" id="avatarDeleteBtn" class="btn btn-sm btn-outline-danger" style="margin-left:8px;">
                O'chirish
            </button>
        @endif
    </div>

    <div id="avatarError" class="text-danger mt-2" style="display:none;"></div>
</form>

{{-- Optional: live preview --}}
<div id="previewWrapper" class="mb-3" style="display:none;">
    <small class="text-muted">Preview:</small>
    <div>
        <img id="avatarPreview" src="#" alt="preview" style="width:80px; height:80px; object-fit:cover; border-radius:50%;">
    </div>
</div>


        {{-- PROFILE update form (name/email/password) --}}
        <form method="POST" action="{{ route('profile.update') }}" autocomplete="off">
            @csrf
            @method('PUT')

            <div class="mb-3">
                <label class="form-label">Ism</label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                    value="{{ old('name', $user->name) }}" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                    value="{{ old('email', $user->email) }}" required>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">
                    Yangi parol
                    <small class="text-muted">(bo'sh qoldirilsa o‘zgarmaydi)</small>
                </label>

                <div class="input-group">
                    <input type="password" name="password" id="password"
                        class="form-control @error('password') is-invalid @enderror"
                        placeholder="Yangi parol (min 6 belgidan)" autocomplete="new-password" />

                    <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()">
                        <i class="fa fa-eye" id="passwordIcon"></i>
                    </button>

                    @error('password')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Parolni tasdiqlash</label>
                <input type="password" name="password_confirmation" class="form-control"
                    placeholder="Parolni tasdiqlang">
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">Update</button>
            </div>
        </form>

    </div>

    {{-- User Phones --}}
    <div class="card shadow-sm rounded-4 p-4 mb-4">
        <h5 class="mb-3">Telefonlar ({{ $user->phones->count() }})</h5>
        @forelse($user->phones as $phone)
            <div class="mb-3 p-3 border rounded-3">
                <strong>{{ $phone->phone }}</strong>
                <small class="text-muted">Session path: {{ $phone->session_path ?? '-' }}</small>
                <div class="mt-2">
                    <span class="badge bg-success">Message Groups: {{ $phone->messageGroups->count() }}</span>
                    <span class="badge bg-primary">Catalogs: {{ $phone->catalogs->count() }}</span>
                </div>
            </div>
        @empty
            <p class="text-muted">Foydalanuvchining telefonlari mavjud emas.</p>
        @endforelse
    </div>

</div>

<style>
    .card:hover {
        transform: translateY(-3px);
        transition: all 0.3s ease;
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const avatarForm = document.getElementById('avatarForm');
    const avatarInput = document.getElementById('avatarInput');
    const avatarUploadBtn = document.getElementById('avatarUploadBtn');
    const avatarDeleteBtn = document.getElementById('avatarDeleteBtn');
    const avatarError = document.getElementById('avatarError');
    const previewWrapper = document.getElementById('previewWrapper');
    const avatarPreview = document.getElementById('avatarPreview');
    const currentAvatar = document.getElementById('currentAvatar');

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Preview
    if (avatarInput) {
        avatarInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            if (file.size > 2 * 1024 * 1024) {
                alert('Fayl hajmi 2MB dan katta bo\'lishi mumkin emas.');
                e.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (ev) {
                previewWrapper.style.display = 'block';
                avatarPreview.src = ev.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    // Upload via fetch
    if (avatarForm) {
        avatarForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            avatarError.style.display = 'none';
            avatarError.textContent = '';

            const file = avatarInput.files[0];
            if (!file) {
                avatarError.style.display = 'block';
                avatarError.textContent = 'Iltimos, avatar tanlang.';
                return;
            }

            const formData = new FormData();
            formData.append('avatar', file);

            avatarUploadBtn.disabled = true;
            avatarUploadBtn.textContent = 'Yuklanmoqda...';

            try {
                const res = await fetch(avatarForm.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: formData,
                    credentials: 'same-origin'
                });

                if (res.status === 422) {
                    const data = await res.json();
                    const msg = (data && data.errors && data.errors.avatar) ? data.errors.avatar[0] : 'Validation error';
                    avatarError.style.display = 'block';
                    avatarError.textContent = msg;
                } else if (!res.ok) {
                    const data = await res.json().catch(()=>null);
                    avatarError.style.display = 'block';
                    avatarError.textContent = (data && data.error) ? data.error : 'Server error';
                } else {
                    const data = await res.json();
                    if (data && data.success) {
                        // update UI: replace currentAvatar with IMG if needed
                        const newUrl = data.url + '?t=' + Date.now(); // cache-bust
                        if (currentAvatar.tagName.toLowerCase() === 'img') {
                            currentAvatar.src = newUrl;
                        } else {
                            // replace div with img
                            const img = document.createElement('img');
                            img.id = 'currentAvatar';
                            img.src = newUrl;
                            img.alt = 'avatar';
                            img.style = 'width:60px; height:60px; object-fit:cover; border-radius:50%;';
                            // insert before the name/email block (currentAvatar div is first child)
                            currentAvatar.parentNode.replaceChild(img, currentAvatar);
                        }

                        // hide preview
                        previewWrapper.style.display = 'none';
                        avatarInput.value = '';

                        // if delete button doesn't exist, add one
                        if (!document.getElementById('avatarDeleteBtn')) {
                            const delBtn = document.createElement('button');
                            delBtn.type = 'button';
                            delBtn.id = 'avatarDeleteBtn';
                            delBtn.className = 'btn btn-sm btn-outline-danger';
                            delBtn.style.marginLeft = '8px';
                            delBtn.textContent = "O'chirish";
                            avatarUploadBtn.insertAdjacentElement('afterend', delBtn);
                            delBtn.addEventListener('click', avatarDeleteHandler);
                        }

                        // optional small success flash
                        const flash = document.createElement('div');
                        flash.className = 'alert alert-success mt-2';
                        flash.textContent = 'Avatar muvaffaqiyatli yangilandi.';
                        avatarForm.parentNode.insertBefore(flash, avatarForm.nextSibling);
                        setTimeout(()=>flash.remove(), 3000);
                    } else {
                        avatarError.style.display = 'block';
                        avatarError.textContent = 'Nomaʼlum xatolik';
                    }
                }
            } catch (err) {
                avatarError.style.display = 'block';
                avatarError.textContent = 'Netvork yoki server xatolik.';
            } finally {
                avatarUploadBtn.disabled = false;
                avatarUploadBtn.textContent = 'Yuklash';
            }
        });
    }

    // Delete handler
    async function avatarDeleteHandler(e) {
        if (!confirm("Avatarni o'chirishni xohlaysizmi?")) return;

        const deleteUrl = "{{ route('profile.avatar.delete') }}"; // blade will render route
        try {
            const res = await fetch(deleteUrl, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const data = await res.json().catch(()=>null);

            if (res.ok && data && data.success) {
                // replace image with initial-letter circle
                const avatarEl = document.getElementById('currentAvatar');
                const wrapper = document.createElement('div');
                wrapper.id = 'currentAvatar';
                wrapper.className = 'rounded-circle bg-primary text-white d-flex justify-content-center align-items-center';
                wrapper.style = 'width:60px; height:60px; font-size:24px; font-weight:bold;';
                wrapper.textContent = '{{ strtoupper(substr($user->name, 0, 1)) }}';

                avatarEl.parentNode.replaceChild(wrapper, avatarEl);

                // remove delete button
                const delBtn = document.getElementById('avatarDeleteBtn');
                if (delBtn) delBtn.remove();

                // show small flash
                const flash = document.createElement('div');
                flash.className = 'alert alert-success mt-2';
                flash.textContent = 'Avatar o‘chirildi.';
                avatarForm.parentNode.insertBefore(flash, avatarForm.nextSibling);
                setTimeout(()=>flash.remove(), 3000);
            } else {
                alert((data && data.message) ? data.message : 'O\'chirishda xatolik yuz berdi.');
            }
        } catch (err) {
            alert('Server yoki tarmoq xatoligi.');
        }
    }

    if (avatarDeleteBtn) {
        avatarDeleteBtn.addEventListener('click', avatarDeleteHandler);
    }
});
</script>


{{-- Javascript: preview + toggle password --}}
<script>
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const icon = document.getElementById('passwordIcon');

        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    const avatarInput = document.getElementById('avatarInput');
    if (avatarInput) {
        avatarInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            if (file.size > 2 * 1024 * 1024) {
                alert('Fayl hajmi 2MB dan katta bo\'lishi mumkin emas.');
                e.target.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (ev) {
                const previewWrapper = document.getElementById('previewWrapper');
                const preview = document.getElementById('avatarPreview');
                preview.src = ev.target.result;
                previewWrapper.style.display = 'block';
            };
            reader.readAsDataURL(file);
        });
    }
</script>
@endsection
