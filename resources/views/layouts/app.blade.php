<!doctype html>
<html lang="uz">

<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Telegram telefonlar</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- FontAwesome CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" 
      integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" 
      crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        /* --- Body & Layout --- */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
            background: #f8f9fa; /* oqroq fon */
            color: #212529;
            display: flex;
            flex-direction: column;
        }

        header {
            background: #ffffff;
            color: #212529;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #dee2e6;
        }

        footer {
            background: #ffffff;
            color: #212529;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-top: 1px solid #dee2e6;
            margin-top: auto;
        }

        header a, footer a {
            color: #212529;
            text-decoration: none;
            font-weight: 500;
            margin-left: 12px;
        }

        header a:hover, footer a:hover {
            text-decoration: underline;
        }

        /* --- Cards --- */
        .card-glass {
            background: #ffffff; 
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            border: 1px solid #dee2e6;
            margin: 20px auto;
            width: calc(100% - 40px);
        }

        /* --- Buttons --- */
        .btn-main {
            background: #0d6efd;
            color: #fff;
            border: none;
        }
        .btn-main:hover {
            background: #0b5ed7;
        }

        .btn-sm {
            font-size: 0.75rem;
        }

        /* --- Table --- */
        .table th, .table td {
            vertical-align: middle;
        }

        /* --- Badge --- */
        .phone-badge {
            font-weight: 500;
            font-size: 13px;
            color: #0d6efd;
            background: rgba(13, 110, 253, 0.1);
            padding: 5px 10px;
            border-radius: 999px;
        }

        /* --- Dropdown --- */
        .dropdown-menu {
            max-height: 250px;
            overflow-y: auto;
        }
        .dropdown-item:hover {
            background: rgba(13, 110, 253, 0.1);
        }
    </style>
</head>

<body>

<header>
    <div class="d-flex gap-3">
        <a href="/dashboard" class="fw-bold">Telegram</a>
        @if (auth()->check() && auth()->user()->role && auth()->user()->role->name === 'superadmin')
            <a href="{{ route('users.index') }}" class="fw-bold">Users</a>
        @endif
        <a href="{{ route('catalogs.index') }}" class="fw-bold">Catalogs</a>
        <a href="{{ route('telegram.send-messages-form') }}" class="fw-bold">Send Messages</a>
        <a href="{{ route('message-groups.dashboard') }}" class="fw-bold"> Status</a>
        <a href="{{ route('messages.history') }}" class="fw-bold">History</a>
        <a href="{{ route('telegram.form') }}" class="fw-bold">Add phone</a>
        

    </div>

    @php $user = auth()->user(); @endphp

<div class="d-flex align-items-center gap-2">
    @if($user && $user->hasAvatar())
        <a href="{{ route('profile') }}" class="d-inline-block" title="Profil">
            <img src="{{ $user->avatar_url }}" alt="Avatar" style="width:36px; height:36px; object-fit:cover; border-radius:50%; border:1px solid rgba(0,0,0,0.06);" loading="lazy">
        </a>
    @else
        <a href="{{ route('profile') }}" class="d-inline-block text-decoration-none" title="Profil">
            <div style="width:36px; height:36px; border-radius:50%; background:#0d6efd; color:white; display:flex; align-items:center; justify-content:center; font-weight:600;">
                {{ $user ? $user->initial : 'U' }}
            </div>
        </a>
    @endif

    <form method="POST" action="{{ route('logout') }}" style="display:inline; margin-left:8px;">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-dark">Logout</button>
    </form>
</div>

</header>

<div class="card-glass">
    @yield('content')
</div>

<footer>
    <div>&copy; Telegram Sync</div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>