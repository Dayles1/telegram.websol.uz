<x-layouts.guest :title="__('auth.title')">
    <h1 class="text-2xl font-semibold tracking-[-0.02em] text-gray-950 dark:text-white">{{ __('auth.heading') }}</h1>
    <p class="c-muted mt-1.5 text-[13px]">{{ __('auth.subheading') }}</p>

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-4" x-data="{ busy: false }" @submit="busy = true">
        @csrf
        <x-ui.field :label="__('auth.email')" for="email" error="email">
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   class="c-input" @error('email') aria-invalid="true" @enderror>
        </x-ui.field>

        <x-ui.field :label="__('auth.password_label')" for="password" error="password">
            <input id="password" name="password" type="password" required autocomplete="current-password" class="c-input">
        </x-ui.field>

        <label class="flex items-center gap-2 text-[13px]">
            <input type="checkbox" name="remember" value="1" class="c-check"> {{ __('auth.remember') }}
        </label>

        <x-ui.button type="submit" variant="primary" size="lg" class="w-full" ::disabled="busy">{{ __('auth.submit') }}</x-ui.button>
    </form>

    <form method="POST" action="{{ route('locale', app()->getLocale() === 'ru' ? 'uz' : 'ru') }}" class="mt-8 text-center">
        @csrf
        <button class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ app()->getLocale() === 'ru' ? 'O‘zbekcha' : 'Русский' }}</button>
    </form>
</x-layouts.guest>
