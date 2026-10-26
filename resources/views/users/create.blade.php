@extends('layouts.app')

@section('content')
<div class="d-flex justify-content-center align-items-start" style="min-height:67vh; padding-top:50px; background:#f0f2f5;">
    <div class="card p-4 shadow-lg" style="width:500px; min-height:400px; background:#fff;">
        <h3 class="mb-4 text-center">Create User</h3>

        <!-- Validation Errors -->
        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('users.store') }}">
            @csrf

            <!-- Name -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Name</label>
                <input name="name" class="form-control" value="{{ old('name') }}" required>
            </div>

            <!-- Email -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Email</label>
                <input name="email" type="email" class="form-control" value="{{ old('email') }}" required>
            </div>

            <!-- Password -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Password</label>
                <input name="password" type="password" class="form-control" required>
            </div>

            <!-- Role -->
            <div class="mb-3">
                <label class="form-label fw-semibold">Role</label>
                <select name="role_id" class="form-control" required>
                    @foreach($roles as $r)
                        <option value="{{ $r->id }}" {{ old('role_id') == $r->id ? 'selected' : '' }}>
                            {{ $r->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <button class="btn btn-primary w-100 mt-3">Save</button>
        </form>
    </div>
</div>
@endsection
