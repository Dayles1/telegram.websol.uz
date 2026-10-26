@php
    $editing = $user->exists;
    $title = $editing ? __('users.edit_title', ['name' => $user->name]) : __('users.create_title');
    $currentRole = old('role', $editing ? $user->roleName()->value : \App\Enums\Role::User->value);
@endphp

<x-layouts.app :title="$title">
    <x-ui.page-header :title="$title" :description="$editing ? $user->email : __('users.create_text')">
        <x-slot:eyebrow>
            <a href="{{ route('users.index') }}" class="inline-flex items-center gap-1 hover:text-gray-900 dark:hover:text-white">
                <x-ui.icon name="chevron-left" class="h-3.5 w-3.5" /> {{ __('users.title') }}
            </a>
        </x-slot:eyebrow>
    </x-ui.page-header>

    <form method="POST" action="{{ $editing ? route('users.update', $user) : route('users.store') }}" class="max-w-2xl">
        @csrf
        @if ($editing) @method('PUT') @endif

        <x-ui.surface>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field :label="__('users.name')" for="name" error="name">
                    <input id="name" name="name" type="text" class="c-input" value="{{ old('name', $user->name) }}" required maxlength="255" autofocus
                           @error('name') aria-invalid="true" @enderror>
                </x-ui.field>
                <x-ui.field :label="__('users.email')" for="email" error="email">
                    <input id="email" name="email" type="email" class="c-input" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="off"
                           @error('email') aria-invalid="true" @enderror>
                </x-ui.field>
                <x-ui.field :label="__('users.role')" for="role" error="role" :hint="__('users.role_hint')">
                    <select id="role" name="role" class="c-input" @error('role') aria-invalid="true" @enderror>
                        @foreach (\App\Enums\Role::cases() as $role)
                            <option value="{{ $role->value }}" @selected($currentRole === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>
                <x-ui.field :label="__('users.password')" for="password" error="password" :hint="$editing ? __('users.password_keep') : __('users.password_hint')">
                    <input id="password" name="password" type="password" class="c-input" minlength="8" autocomplete="new-password" @required(! $editing)
                           @error('password') aria-invalid="true" @enderror>
                </x-ui.field>
            </div>
        </x-ui.surface>

        <div class="mt-4 flex justify-end gap-2">
            <x-ui.button :href="route('users.index')" variant="ghost">{{ __('common.cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" icon="check">{{ $editing ? __('common.save') : __('users.create') }}</x-ui.button>
        </div>
    </form>
</x-layouts.app>
