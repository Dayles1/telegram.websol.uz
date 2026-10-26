@props(['title', 'description' => null])

{{-- Page title, one quiet line under it, actions on the right (secondary first, primary last). --}}
<header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @isset($eyebrow)
            <div class="c-eyebrow mb-1">{{ $eyebrow }}</div>
        @endisset
        <h1 class="truncate text-[22px] font-semibold leading-7 tracking-[-0.015em] text-gray-950 sm:text-2xl sm:leading-8 dark:text-white">{{ $title }}</h1>
        @if ($description)
            <p class="c-muted mt-1 max-w-3xl text-[13px] leading-relaxed">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">{{ $actions }}</div>
    @endisset
</header>
