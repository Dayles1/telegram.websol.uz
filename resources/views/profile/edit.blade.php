<x-layouts.app :title="__('profile.title')">
    <div x-data="profilePage({
        urls: @js(['avatar' => route('profile.avatar'), 'avatarDelete' => route('profile.avatar.delete')]),
        avatar: @js($user->avatar_url),
        t: @js(__('profile.js')),
    })">
        <x-ui.page-header :title="__('profile.title')" :description="__('profile.description')" />

        <div class="grid gap-5 lg:grid-cols-[300px_1fr]">
            {{-- Who I am --}}
            <aside class="min-w-0">
                <section class="c-surface p-5 text-center">
                    <div class="relative mx-auto h-24 w-24">
                        <template x-if="preview || avatar">
                            <img :src="preview || avatar" alt="" class="h-24 w-24 rounded-full border border-[var(--line)] object-cover" :class="busy && 'opacity-60'">
                        </template>
                        <template x-if="!preview && !avatar">
                            <span class="grid h-24 w-24 place-items-center rounded-full bg-brand-50 text-2xl font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $user->initials }}</span>
                        </template>
                        <span x-show="busy" x-cloak class="absolute inset-0 grid place-items-center"><span class="c-dot is-live text-brand-500"></span></span>
                    </div>
                    <div class="mt-3 truncate text-base font-semibold text-gray-950 dark:text-white">{{ $user->name }}</div>
                    <div class="c-muted truncate text-[13px]">{{ $user->email }}</div>
                    <div class="mt-2"><x-ui.badge :tone="$user->isSuperadmin() ? 'brand' : 'neutral'">{{ $user->roleName()->label() }}</x-ui.badge></div>

                    <input type="file" x-ref="avatarInput" accept="image/*" class="hidden" @change="upload($event)">
                    <div class="mt-4 flex justify-center gap-2">
                        <x-ui.button size="sm" variant="secondary" icon="upload" @click="pick()" ::disabled="busy">{{ __('profile.avatar_upload') }}</x-ui.button>
                        <template x-if="avatar">
                            <x-ui.button size="sm" variant="danger-ghost" icon="trash" @click="removeAvatar()" ::disabled="busy">{{ __('profile.avatar_delete') }}</x-ui.button>
                        </template>
                    </div>
                    <p class="c-muted mt-2 text-xs">{{ __('profile.avatar_hint') }}</p>
                </section>
            </aside>

            <div class="min-w-0 space-y-5">
                {{-- Name and email --}}
                <x-ui.surface :title="__('profile.account')">
                    <form method="POST" action="{{ route('profile.update') }}" class="grid gap-4 sm:grid-cols-2">
                        @csrf
                        @method('PUT')
                        <x-ui.field :label="__('profile.name')" for="name" :error="old('_form') === 'token' ? null : 'name'">
                            <input id="name" name="name" type="text" class="c-input" value="{{ old('_form') === 'token' ? $user->name : old('name', $user->name) }}" required maxlength="255" autocomplete="name"
                                   @if ($errors->has('name') && old('_form') !== 'token') aria-invalid="true" @endif>
                        </x-ui.field>
                        <x-ui.field :label="__('profile.email')" for="email" error="email">
                            <input id="email" name="email" type="email" class="c-input" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="email"
                                   @error('email') aria-invalid="true" @enderror>
                        </x-ui.field>
                        <div class="sm:col-span-2 flex justify-end">
                            <x-ui.button type="submit" variant="primary">{{ __('common.save') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.surface>

                {{-- Password --}}
                <x-ui.surface :title="__('profile.password_title')">
                    <form method="POST" action="{{ route('profile.update') }}" class="grid gap-4 sm:grid-cols-3">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="name" value="{{ $user->name }}">
                        <input type="hidden" name="email" value="{{ $user->email }}">
                        <x-ui.field :label="__('profile.current_password')" for="current_password" error="current_password">
                            <input id="current_password" name="current_password" type="password" class="c-input" required autocomplete="current-password"
                                   @error('current_password') aria-invalid="true" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('profile.new_password')" for="password" error="password" :hint="__('profile.password_hint')">
                            <input id="password" name="password" type="password" class="c-input" required minlength="8" autocomplete="new-password"
                                   @error('password') aria-invalid="true" @enderror>
                        </x-ui.field>
                        <x-ui.field :label="__('profile.confirm_password')" for="password_confirmation">
                            <input id="password_confirmation" name="password_confirmation" type="password" class="c-input" required minlength="8" autocomplete="new-password">
                        </x-ui.field>
                        <div class="sm:col-span-3 flex justify-end">
                            <x-ui.button type="submit" variant="secondary" icon="key">{{ __('profile.change_password') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.surface>

                {{-- API tokens --}}
                <x-ui.surface :title="__('profile.tokens_title')" :padded="false">
                    <div class="space-y-4 p-4 sm:p-5">
                        <p class="c-muted max-w-2xl text-[13px] leading-relaxed">{{ __('profile.tokens_text') }}</p>
                        <div class="rounded-xl border border-[var(--line)] bg-[var(--surface-2)] px-3 py-2 font-mono text-xs text-gray-700 dark:text-gray-300 c-break">
                            Authorization: Bearer &lt;token&gt;<br>
                            <span class="c-muted">{{ url('/api') }}/…</span>
                        </div>

                        @if (session('new_token'))
                            <div x-show="showToken" class="rounded-2xl border border-warning-500/40 bg-warning-50 p-4 dark:bg-warning-500/10">
                                <div class="flex items-start gap-2 text-[13px] font-semibold text-warning-800 dark:text-warning-300">
                                    <x-ui.icon name="alert" class="mt-0.5" /> {{ __('profile.token_created') }}
                                </div>
                                <p class="mt-1 text-xs text-warning-800 dark:text-warning-300">{{ __('profile.token_once') }}</p>
                                <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                    <input type="text" readonly class="c-input font-mono text-xs" value="{{ session('new_token') }}" @focus="$el.select()">
                                    <x-ui.button variant="primary" icon="copy" @click="$copy(@js(session('new_token')))">{{ __('profile.copy') }}</x-ui.button>
                                </div>
                            </div>
                        @endif

                        <form method="POST" action="{{ route('profile.tokens.store') }}" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                            <input type="hidden" name="_form" value="token">
                            @csrf
                            <x-ui.field class="flex-1" :error="old('_form') === 'token' ? 'name' : null">
                                <input name="name" type="text" class="c-input" required maxlength="100" placeholder="{{ __('profile.token_name_placeholder') }}" aria-label="{{ __('profile.token_name') }}">
                            </x-ui.field>
                            <x-ui.button type="submit" variant="secondary" icon="plus">{{ __('profile.token_create') }}</x-ui.button>
                        </form>
                    </div>

                    @if ($tokens->isEmpty())
                        <div class="border-t border-[var(--line)]">
                            <x-ui.empty icon="key" :title="__('profile.tokens_empty')" />
                        </div>
                    @else
                        <div class="overflow-x-auto border-t border-[var(--line)]">
                            <table class="c-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('profile.token_name') }}</th>
                                        <th>{{ __('profile.token_last_used') }}</th>
                                        <th>{{ __('common.created') }}</th>
                                        <th class="w-12"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($tokens as $token)
                                        <tr class="c-row">
                                            <td class="font-medium text-gray-900 dark:text-white">{{ $token->name }}</td>
                                            <td class="c-muted whitespace-nowrap" title="{{ $token->last_used_at?->format('d.m.Y H:i') }}">
                                                {{ $token->last_used_at ? $token->last_used_at->diffForHumans() : __('common.never') }}
                                            </td>
                                            <td class="c-muted whitespace-nowrap">{{ $token->created_at?->format('d.m.Y H:i') }}</td>
                                            <td class="text-right">
                                                <form method="POST" action="{{ route('profile.tokens.destroy', $token->id) }}"
                                                      @submit.prevent="confirmSubmit($el, { title: @js(__('profile.js.token_delete_title')), body: @js(__('profile.js.token_delete_body', ['name' => $token->name])), action: @js(__('profile.js.token_delete')) })">
                                                    @csrf
                                                    @method('DELETE')
                                                    <x-ui.button type="submit" size="icon-sm" variant="danger-ghost" :title="__('profile.js.token_delete')"><x-ui.icon name="trash" /></x-ui.button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.surface>
            </div>
        </div>
    </div>
</x-layouts.app>
