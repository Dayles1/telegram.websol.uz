@props([
    'variant' => 'secondary',
    'size' => 'md',
    'href' => null,
    'icon' => null,
    'type' => 'button',
    'as' => null, // 'a' for a link whose href Alpine binds (::href)
])

{{--
    One button, five voices:
      primary   - ink on paper, the single "do it" of a view
      secondary - hairline, the everyday action
      ghost     - no frame, quiet or repeated actions
      danger / brand - only where the colour is the message
    40px on touch screens, 36px from sm up (32px for size sm).
--}}
@php
    $base = 'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-xl font-medium select-none
             transition-colors duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand-500/60 focus-visible:ring-offset-1
             disabled:cursor-not-allowed disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50';

    $variants = [
        'primary' => 'bg-gray-900 text-white hover:bg-gray-700 active:bg-black dark:bg-white dark:text-gray-900 dark:hover:bg-gray-200',
        'secondary' => 'border border-gray-300 bg-white text-gray-800 hover:border-gray-400 hover:bg-gray-50 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-100 dark:hover:border-gray-500 dark:hover:bg-white/[0.07]',
        'ghost' => 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white',
        'danger' => 'bg-error-600 text-white hover:bg-error-700',
        'danger-ghost' => 'text-error-600 hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10',
        'brand' => 'bg-brand-500 text-white hover:bg-brand-600',
    ];

    $sizes = [
        'lg' => 'h-11 px-5 text-sm sm:h-10',
        'md' => 'h-10 px-3.5 text-[13px] sm:h-9',
        'sm' => 'h-9 px-3 text-[13px] sm:h-8 sm:text-xs',
        'icon' => 'h-10 w-10 sm:h-9 sm:w-9',
        'icon-sm' => 'h-8 w-8',
    ];

    $classes = preg_replace('/\s+/', ' ', $base.' '.($variants[$variant] ?? $variants['secondary']).' '.($sizes[$size] ?? $sizes['md']));
@endphp

@if ($href || $as === 'a')
    <a @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" />@endif
        {{ $slot }}
    </button>
@endif
