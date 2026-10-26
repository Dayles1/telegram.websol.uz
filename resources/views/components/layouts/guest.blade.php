@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <script>
        try { if ((localStorage.getItem('theme') ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')) === 'dark') document.documentElement.classList.add('dark'); } catch (e) {}
        window.__t = @js(__('common.js'));
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-screen lg:grid-cols-[1fr_minmax(420px,520px)]">
    <aside class="relative hidden overflow-hidden bg-[var(--ink)] p-10 text-[var(--ink-text)] lg:flex lg:flex-col">
        <div class="c-brand border-0">
            <span class="c-brand__mark"><x-ui.icon name="send" class="h-3.5 w-3.5" /></span>
            {{ config('app.name') }}
        </div>
        <div class="mt-auto max-w-md">
            <p class="text-[28px] font-semibold leading-tight tracking-[-0.02em] text-white">{{ __('auth.tagline') }}</p>
            <p class="mt-3 text-sm text-[var(--ink-dim)]">{{ __('auth.tagline_sub') }}</p>
        </div>
        <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-brand-500/20 blur-3xl"></div>
    </aside>

    <main class="flex items-center justify-center bg-[var(--surface)] px-6 py-12">
        <div class="c-page w-full max-w-sm">{{ $slot }}</div>
    </main>
</body>
</html>
