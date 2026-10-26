@props(['label', 'icon' => null, 'tone' => null])

{{-- A number with a label. The value is the slot (often an x-text span); `hint` slot under it. --}}
@php
    $toneClass = ['success' => 'text-success-600', 'error' => 'text-error-600', 'warning' => 'text-warning-600', 'brand' => 'text-brand-600'][$tone] ?? 'text-gray-950 dark:text-white';
@endphp

<div {{ $attributes->merge(['class' => 'c-surface p-4']) }}>
    <div class="c-eyebrow flex items-center gap-1.5">
        @if ($icon)<x-ui.icon :name="$icon" class="h-3.5 w-3.5" />@endif
        {{ $label }}
    </div>
    <div class="mt-2 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums {{ $toneClass }}">{{ $slot }}</div>
    @isset($hint)
        <div class="c-muted mt-2 text-xs">{{ $hint }}</div>
    @endisset
</div>
