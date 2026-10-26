@props(['icon' => 'layers', 'title', 'text' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <div class="mb-3 grid h-11 w-11 place-items-center rounded-2xl border border-[var(--line)] bg-[var(--surface-2)] text-gray-400">
        <x-ui.icon :name="$icon" class="h-5 w-5" />
    </div>
    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</p>
    @if ($text)
        <p class="c-muted mt-1 max-w-sm text-[13px]">{{ $text }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
