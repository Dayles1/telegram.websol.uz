@props(['title' => null, 'padded' => true])

{{-- A flat panel with a hairline. Optional title row with actions on the right. --}}
<section {{ $attributes->merge(['class' => 'c-surface overflow-hidden']) }}>
    @if ($title || isset($actions))
        <div class="c-surface__head">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['p-4 sm:p-5' => $padded])>{{ $slot }}</div>
</section>
