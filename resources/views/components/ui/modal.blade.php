@props(['show', 'title' => null, 'width' => 'max-w-lg'])

{{--
    A dialog bound to an Alpine boolean: <x-ui.modal show="open" title="...">.
    Esc and a click on the backdrop close it.
--}}
<template x-teleport="body">
    <div x-show="{{ $show }}" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4"
         @keydown.escape.window="{{ $show }} = false" role="dialog" aria-modal="true">
        <div x-show="{{ $show }}" x-transition.opacity class="absolute inset-0 bg-gray-950/50 backdrop-blur-[2px]" @click="{{ $show }} = false"></div>

        <div x-show="{{ $show }}"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-[0.98]"
             x-transition:leave="transition duration-100 ease-in" x-transition:leave-end="opacity-0"
             {{ $attributes->merge(['class' => "relative w-full {$width} max-h-[92vh] overflow-y-auto rounded-t-3xl border border-[var(--line)] bg-[var(--surface)] shadow-2xl sm:rounded-3xl"]) }}>
            @if ($title)
                <div class="flex items-center justify-between gap-3 border-b border-[var(--line)] px-5 py-4">
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">{{ $title }}</h2>
                    <button type="button" class="c-muted rounded-lg p-1 hover:bg-gray-100 dark:hover:bg-white/5" @click="{{ $show }} = false" aria-label="{{ __('common.close') }}">
                        <x-ui.icon name="x" />
                    </button>
                </div>
            @endif
            <div class="p-5">{{ $slot }}</div>
            @isset($footer)
                <div class="flex flex-col-reverse gap-2 border-t border-[var(--line)] px-5 py-4 sm:flex-row sm:justify-end">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</template>
