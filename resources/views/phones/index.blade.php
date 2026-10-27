<x-layouts.app :title="__('phones.title')">
    <div x-data="phonesPage({
        urls: @js([
            'data' => route('phones.data'),
            'store' => route('phones.store'),
            'code' => route('phones.code', '__ID__'),
            'password' => route('phones.password', '__ID__'),
            'relogin' => route('phones.relogin', '__ID__'),
            'cancel' => route('phones.cancel', '__ID__'),
            'check' => route('phones.check', '__ID__'),
            'logout' => route('phones.logout', '__ID__'),
            'destroy' => route('phones.destroy', '__ID__'),
        ]),
        t: @js(__('phones.js')),
        canSeeAll: @js(auth()->user()->seesOthers()),
    })">
        <x-ui.page-header :title="__('phones.title')" :description="__('phones.description')">
            <x-slot:actions>
                <template x-if="canSeeAll">
                    <x-ui.button variant="secondary" icon="users" @click="toggleAll()">
                        <span x-text="showAll ? @js(__('phones.only_mine')) : @js(__('phones.show_all'))"></span>
                    </x-ui.button>
                </template>
                <x-ui.button variant="primary" icon="plus" @click="openWizard()">{{ __('phones.connect') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Loading --}}
        <div x-show="loading" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <template x-for="i in 3"><div class="c-skeleton h-40 rounded-2xl"></div></template>
        </div>

        {{-- Empty --}}
        <div x-show="!loading && !phones.length" x-cloak class="c-surface">
            <x-ui.empty icon="phone" :title="__('phones.empty_title')" :text="__('phones.empty_text')">
                <x-ui.button variant="primary" icon="plus" @click="openWizard()">{{ __('phones.connect') }}</x-ui.button>
            </x-ui.empty>
        </div>

        {{-- Cards --}}
        <template x-for="(group, gi) in [{ key: 'mine', items: mine }, { key: 'others', items: others }]" :key="group.key">
            <section x-show="!loading && group.items.length" x-cloak class="mb-6">
                <h2 x-show="group.key === 'others'" class="c-eyebrow mb-3">{{ __('phones.others') }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <template x-for="phone in group.items" :key="phone.id">
                        <article class="c-surface flex flex-col p-4">
                            <div class="flex items-start gap-3">
                                <div class="grid h-10 w-10 shrink-0 place-items-center rounded-2xl bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300"
                                     x-text="(phone.name || '?').replace('@', '').slice(0, 1).toUpperCase()"></div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-sm font-semibold text-gray-950 dark:text-white" x-text="phone.name"></div>
                                    <button type="button" class="c-muted inline-flex items-center gap-1 font-mono text-xs hover:text-gray-900 dark:hover:text-white" @click="$copy(phone.phone)">
                                        <span x-text="phone.phone"></span><x-ui.icon name="copy" class="h-3 w-3" />
                                    </button>
                                    <div x-show="phone.owner && !phone.is_mine" class="c-muted truncate text-xs" x-text="'👤 ' + phone.owner?.name"></div>
                                </div>
                                <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-semibold" :class="$fmt.tone(phone.tone)">
                                    <span class="c-dot h-1.5 w-1.5" :class="phone.in_flight && 'is-live'"></span>
                                    <span x-text="phone.flood_until ? @js(__('phones.paused')) : phone.status_label"></span>
                                </span>
                            </div>

                            {{-- Health --}}
                            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-xs">
                                <div>
                                    <dt class="c-muted">{{ __('phones.sent_today') }}</dt>
                                    <dd class="mt-0.5 font-semibold tabular-nums text-gray-900 dark:text-white">
                                        <span x-text="$fmt.number(sentToday[phone.id] ?? 0)"></span><span class="c-muted font-normal" x-show="dailyLimit" x-text="' / ' + $fmt.number(dailyLimit)"></span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="c-muted">{{ __('phones.last_sent') }}</dt>
                                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-white" x-text="$fmt.relative(phone.last_sent_at)"></dd>
                                </div>
                                <div>
                                    <dt class="c-muted">{{ __('phones.checked') }}</dt>
                                    <dd class="mt-0.5 font-medium text-gray-900 dark:text-white" x-text="$fmt.relative(phone.last_checked_at)"></dd>
                                </div>
                                <div x-show="phone.username">
                                    <dt class="c-muted">Username</dt>
                                    <dd class="mt-0.5 truncate font-medium text-gray-900 dark:text-white" x-text="'@' + phone.username"></dd>
                                </div>
                            </dl>
                            <div x-show="dailyLimit" class="c-progress mt-3"><span class="bg-brand-500" :style="`width:${usage(phone)}%`"></span></div>

                            <p x-show="phone.flood_until" class="mt-3 rounded-xl bg-warning-50 px-3 py-2 text-xs text-warning-800 dark:bg-warning-500/10 dark:text-warning-300">
                                {{ __('phones.flood_until') }} <span class="font-semibold" x-text="$fmt.relative(phone.flood_until)"></span>
                            </p>
                            <p x-show="phone.last_error_text && !phone.flood_until" class="mt-3 rounded-xl bg-error-50 px-3 py-2 text-xs text-error-700 dark:bg-error-500/10 dark:text-error-300" x-text="phone.last_error_text"></p>

                            {{-- Actions --}}
                            <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-4">
                                <template x-if="phone.can.send">
                                    <x-ui.button as="a" size="sm" variant="primary" icon="send" ::href="@js(route('send.create')) + '?phone=' + phone.id">{{ __('phones.send') }}</x-ui.button>
                                </template>
                                <template x-if="phone.awaits">
                                    <x-ui.button size="sm" variant="primary" icon="key" @click="resume(phone)">{{ __('phones.continue_login') }}</x-ui.button>
                                </template>
                                <template x-if="phone.can.relogin && !phone.awaits">
                                    <x-ui.button size="sm" variant="secondary" icon="refresh" @click="relogin(phone)">{{ __('phones.relogin') }}</x-ui.button>
                                </template>
                                <template x-if="phone.can.check">
                                    <x-ui.button size="sm" variant="ghost" icon="shield" @click="check(phone)" ::disabled="busy">{{ __('phones.check') }}</x-ui.button>
                                </template>
                                <div class="ml-auto flex gap-1">
                                    <template x-if="phone.can.logout">
                                        <x-ui.button size="icon-sm" variant="danger-ghost" @click="logout(phone)" :title="__('phones.logout')"><x-ui.icon name="logout" /></x-ui.button>
                                    </template>
                                    <template x-if="phone.can.remove">
                                        <x-ui.button size="icon-sm" variant="danger-ghost" @click="remove(phone)" :title="__('phones.remove')"><x-ui.icon name="trash" /></x-ui.button>
                                    </template>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>
            </section>
        </template>

        {{-- Connect wizard --}}
        <x-ui.modal show="wizard" :title="__('phones.connect')" width="max-w-md">
            {{-- Steps --}}
            <ol class="mb-5 flex items-center gap-2 text-xs font-medium">
                @foreach (['phone' => __('phones.step_phone'), 'code' => __('phones.step_code'), 'password' => __('phones.step_password')] as $key => $label)
                    <li class="flex items-center gap-2" :class="['phone','code','password','done'].indexOf(step) >= {{ $loop->index }} ? 'text-gray-950 dark:text-white' : 'c-muted'">
                        <span class="grid h-5 w-5 place-items-center rounded-full border text-[10px]"
                              :class="['phone','code','password','done'].indexOf(step) > {{ $loop->index }} ? 'border-brand-500 bg-brand-500 text-white' : 'border-current'">{{ $loop->iteration }}</span>
                        {{ $label }}
                        @unless ($loop->last)<span class="c-muted">→</span>@endunless
                    </li>
                @endforeach
            </ol>

            {{-- Working... --}}
            <div x-show="account?.in_flight" class="mb-4 flex items-center gap-3 rounded-2xl bg-brand-50 px-4 py-3 text-[13px] text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
                <span class="c-dot is-live text-brand-500"></span>
                <span x-text="account?.status === 'requesting_code' ? @js(__('phones.requesting_code')) : @js(__('phones.verifying'))"></span>
            </div>

            <p x-show="error" x-cloak class="mb-4 rounded-2xl bg-error-50 px-4 py-3 text-[13px] text-error-700 dark:bg-error-500/10 dark:text-error-300" x-text="error"></p>

            {{-- 1. phone --}}
            <form x-show="step === 'phone' && !account?.in_flight" @submit.prevent="submitPhone()" class="space-y-4">
                <x-ui.field :label="__('phones.phone_label')" :hint="__('phones.phone_hint')">
                    <input x-ref="phoneInput" x-model="phoneInput" type="tel" inputmode="tel" autocomplete="off" class="c-input font-mono" placeholder="+998 90 123 45 67" required>
                </x-ui.field>
                <x-ui.button type="submit" variant="primary" class="w-full" ::disabled="busy || phoneInput.length < 9">{{ __('phones.get_code') }}</x-ui.button>
            </form>

            {{-- 2. code --}}
            <form x-show="step === 'code' && !account?.in_flight" x-cloak @submit.prevent="submitCode()" class="space-y-4">
                <p class="c-muted text-[13px]">{!! __('phones.code_sent_to', ['phone' => '<span class="font-mono font-semibold text-gray-900 dark:text-white" x-text="account?.phone"></span>']) !!}</p>
                <x-ui.field :label="__('phones.code_label')">
                    <input x-ref="codeInput" x-model="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="12"
                           class="c-input h-12 text-center font-mono text-2xl tracking-[0.5em]" placeholder="•••••" required>
                </x-ui.field>
                <x-ui.button type="submit" variant="primary" class="w-full" ::disabled="busy || code.length < 3">{{ __('phones.confirm') }}</x-ui.button>
                <button type="button" class="c-muted w-full text-center text-xs hover:text-gray-900 disabled:opacity-60 dark:hover:text-white" :disabled="resendIn > 0" @click="resend()">
                    <span x-show="resendIn > 0" x-text="@js(__('phones.resend_in')).replace(':time', $fmt.clock(resendIn))"></span>
                    <span x-show="resendIn === 0">{{ __('phones.resend') }}</span>
                </button>
            </form>

            {{-- 3. password --}}
            <form x-show="step === 'password' && !account?.in_flight" x-cloak @submit.prevent="submitPassword()" class="space-y-4">
                <p class="c-muted text-[13px]">{{ __('phones.password_text') }}</p>
                <x-ui.field :label="__('phones.password_label')">
                    <input x-ref="passwordInput" x-model="password" type="password" autocomplete="off" class="c-input" required>
                </x-ui.field>
                <p x-show="account?.password_hint" class="c-muted text-xs">{{ __('phones.hint') }}: <span class="font-semibold" x-text="account?.password_hint"></span></p>
                <x-ui.button type="submit" variant="primary" class="w-full" ::disabled="busy || !password">{{ __('phones.confirm') }}</x-ui.button>
            </form>

            {{-- done --}}
            <div x-show="step === 'done'" x-cloak class="py-4 text-center">
                <div class="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/15">
                    <x-ui.icon name="check" class="h-6 w-6" />
                </div>
                <p class="text-base font-semibold text-gray-950 dark:text-white">{{ __('phones.connected') }}</p>
                <p class="c-muted mt-1 text-[13px]" x-text="account?.name + ' · ' + account?.phone"></p>
                <div class="mt-5 flex justify-center gap-2">
                    <x-ui.button variant="secondary" @click="wizard = false">{{ __('common.close') }}</x-ui.button>
                    <x-ui.button as="a" variant="primary" icon="send" ::href="@js(route('send.create')) + '?phone=' + account?.id">{{ __('phones.send') }}</x-ui.button>
                </div>
            </div>

            <x-slot:footer>
                <x-ui.button variant="ghost" x-show="step !== 'done'" @click="cancelWizard()">{{ __('phones.cancel_login') }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </div>
</x-layouts.app>
