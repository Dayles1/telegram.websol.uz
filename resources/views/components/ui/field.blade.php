@props(['label' => null, 'for' => null, 'hint' => null, 'error' => null])

{{-- Label, control (slot), then a hint or the validation error for `error` (a field name). --}}
<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="c-label">{{ $label }}</label>
    @endif

    {{ $slot }}

    @if ($error && $errors->has($error))
        <p class="mt-1.5 text-xs text-error-600 dark:text-error-400">{{ $errors->first($error) }}</p>
    @elseif ($hint)
        <p class="c-muted mt-1.5 text-xs">{{ $hint }}</p>
    @endif
</div>
