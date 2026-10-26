@props(['tone' => 'neutral', 'dot' => false])

{{-- A small tag. Server side: pass a tone; in Alpine templates bind :class="$fmt.tone(...)". --}}
@php
    $tones = [
        'success' => 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-300',
        'error' => 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-300',
        'warning' => 'bg-warning-50 text-warning-800 dark:bg-warning-500/15 dark:text-warning-300',
        'info' => 'bg-info-50 text-info-700 dark:bg-info-500/15 dark:text-info-300',
        'brand' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
        'neutral' => 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold leading-5 '.($tones[$tone] ?? $tones['neutral'])]) }}>
    @if ($dot)<span class="c-dot h-1.5 w-1.5"></span>@endif
    {{ $slot }}
</span>
