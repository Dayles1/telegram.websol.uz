@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-center align-items-start" style="min-height:80vh; padding-top:50px; background:#f0f2f5;">
    <div class="card p-4 shadow-lg" style="width:500px; min-height:400px; background:#fff;">
        <h3 class="mb-4 text-center">Edit User</h3>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('users.update', $user->id) }}">
            @csrf
            @method('PUT')

            <!-- Name -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Name</label>
                <input name="name" class="form-control" value="{{ old('name', $user->name) }}" required>
            </div>

            <!-- Email -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input name="email" type="email" class="form-control" value="{{ old('email', $user->email) }}" required>
            </div>

            <!-- Password -->
            <div class="mb-3">
                <label class="form-label fw-semibold">New Password</label>
                <input name="password" type="password" class="form-control">
                <small class="text-muted">Agar o‘zgartirmoqchi bo‘lmasang — bo‘sh qoldir</small>
            </div>

            <!-- Role -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Role</label>
                <select name="role_id" class="form-control" required>
                    @foreach($roles as $r)
                        <option value="{{ $r->id }}" @selected(old('role_id', $user->role_id) == $r->id)>
                            {{ $r->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button class="btn btn-primary w-100 mt-3">Update</button>
        </form>
    </div>
</div>
@endsection
