@extends('layouts.app')

@section('content')
    <h3 class="mb-3 text-center">Users</h3>

    <div class="container" style="max-width: 800px;">

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">

    {{-- Search on the left --}}
    <form method="GET" action="{{ route('users.index') }}" class="d-flex gap-2 m-0 align-items-center">
        <div class="input-group">
            <input type="text" name="search" value="{{ $search }}" id="userSearch" class="form-control"
                placeholder="Search users..." style="max-width: 260px;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i>
            </button>
        </div>
    </form>

    {{-- Create button on the right --}}
    <a href="{{ route('users.create') }}" class="btn btn-success btn-sm px-3">
        + Create User
    </a>

</div>





        @if (session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <div class="table-responsive" style="min-height:70vh;">
            <table class="table table-bordered bg-white align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="usersTable">
                    @foreach ($users as $u)
                        <tr data-user-name="{{ strtolower($u->name) }}">
                            <td>{{ $u->id }}</td>
                            <td>{{ $u->name }}</td>
                            <td>{{ $u->email }}</td>
                            <td>{{ $u->role?->name }}</td>
                            <td>
                                @if ($u->role?->name !== 'superadmin')
                                    <div class="d-flex gap-2 justify-content-center">
                                        <a href="{{ route('users.edit', $u->id) }}" class="btn btn-sm btn-primary"
                                            style="width:32px; height:32px; display:flex; justify-content:center; align-items:center;">
                                            <i class="fas fa-pen"></i>
                                        </a>

                                        <form action="{{ route('users.destroy', $u->id) }}" method="POST"
                                            onsubmit="return confirm('Delete?');" class="m-0 p-0">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-danger"
                                                style="width:32px; height:32px; display:flex; justify-content:center; align-items:center;">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-muted">No actions</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($users->lastPage() > 1)
<div class="d-flex justify-content-center align-items-center gap-2 mt-3">
    {{-- Previous --}}
    @if($users->onFirstPage())
        <span class="btn btn-light btn-sm disabled">&laquo;</span>
    @else
        <a href="{{ $users->previousPageUrl() }}" class="btn btn-light btn-sm">&laquo;</a>
    @endif

    {{-- Page numbers (faqat 5 ta atrofidagi raqam) --}}
    @for ($i = max(1, $users->currentPage()-2); $i <= min($users->lastPage(), $users->currentPage()+2); $i++)
        @if ($i == $users->currentPage())
            <span class="btn btn-primary btn-sm">{{ $i }}</span>
        @else
            <a href="{{ $users->url($i) }}" class="btn btn-light btn-sm">{{ $i }}</a>
        @endif
    @endfor

    {{-- Next --}}
    @if($users->hasMorePages())
        <a href="{{ $users->nextPageUrl() }}" class="btn btn-light btn-sm">&raquo;</a>
    @else
        <span class="btn btn-light btn-sm disabled">&raquo;</span>
    @endif
</div>
@endif

    </div>



    <style>
        .table td,
        .table th {
            vertical-align: middle;
        }

        input#userSearch {
            border-radius: 0.3rem;
        }

        /* Minimal Create User link */
        .create-user-link {
            text-decoration: none;
            font-weight: 600;
            color: inherit;
        }

        .create-user-link:hover {
            text-decoration: underline;
        }
    </style>
@endsection
