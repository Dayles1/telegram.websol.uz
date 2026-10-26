@php($isAdmin = auth()->user()->isSuperadmin())

<x-layouts.app :title="__('catalogs.title')">
    <div x-data="catalogsPage({
        urls: @js([
            'data' => route('catalogs.data'),
            'users' => route('catalogs.users'),
            'edit' => route('catalogs.edit', '__ID__'),
            'destroy' => route('catalogs.destroy', '__ID__'),
            'copy' => route('catalogs.copy', '__ID__'),
            'distribute' => route('catalogs.distribute', '__ID__'),
        ]),
        t: @js(__('catalogs') + ['delete' => __('common.delete')]),
        isAdmin: @js($isAdmin),
    })">
        <x-ui.page-header :title="__('catalogs.title')" :description="__('catalogs.description')">
            <x-slot:actions>
                <x-ui.button :href="route('send.create')" icon="send">{{ __('nav.send') }}</x-ui.button>
                <x-ui.button :href="route('catalogs.create')" variant="primary" icon="plus">{{ __('catalogs.new') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <section class="c-surface overflow-hidden">
            {{-- Toolbar: tabs + search --}}
            <div class="flex flex-col gap-3 border-b border-[var(--line)] p-3 md:flex-row md:items-center md:justify-between">
                <div class="flex gap-1 overflow-x-auto" role="tablist">
                    <template x-for="item in tabs" :key="item.key">
                        <button type="button" role="tab" :aria-selected="tab === item.key" @click="switchTab(item.key)"
                                class="inline-flex h-8 shrink-0 items-center gap-2 rounded-lg px-3 text-[13px] font-medium transition-colors"
                                :class="tab === item.key ? 'bg-gray-900 text-white dark:bg-white dark:text-gray-900' : 'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/[0.07]'">
                            <span x-text="item.label"></span>
                            <span class="rounded-md px-1.5 text-[11px] font-semibold tabular-nums"
                                  :class="tab === item.key ? 'bg-white/15 dark:bg-gray-900/10' : 'bg-gray-100 text-gray-500 dark:bg-white/[0.06]'"
                                  x-text="$fmt.number(counts[item.key] ?? 0)"></span>
                        </button>
                    </template>
                </div>
                <div class="relative md:w-72">
                    <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                    <input type="search" x-model="search" data-page-search class="c-input h-9 pl-9 pr-10" placeholder="{{ __('catalogs.search_placeholder') }}" autocomplete="off">
                    <span class="c-kbd pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2" x-show="!search">/</span>
                </div>
            </div>

            {{-- Column labels --}}
            <div class="c-eyebrow hidden items-center gap-4 border-b border-[var(--line)] bg-[var(--surface-2)] px-4 py-2 md:flex">
                <span class="flex-1 pl-7">{{ __('catalogs.col_title') }}</span>
                <span class="w-28 text-right">{{ __('catalogs.col_peers') }}</span>
                <span class="w-32">{{ __('catalogs.col_updated') }}</span>
                <span class="w-[188px] text-right">{{ __('common.actions') }}</span>
            </div>

            {{-- Loading --}}
            <div x-show="loading && !catalogs.length" class="divide-y divide-[var(--line)]">
                <template x-for="i in 5" :key="i">
                    <div class="flex items-center gap-4 px-4 py-3.5">
                        <div class="c-skeleton h-4 w-4"></div>
                        <div class="flex-1 space-y-2"><div class="c-skeleton h-3.5 w-1/3"></div><div class="c-skeleton h-3 w-1/5"></div></div>
                        <div class="c-skeleton h-4 w-16"></div>
                    </div>
                </template>
            </div>

            {{-- Empty --}}
            <div x-show="!loading && !catalogs.length" x-cloak>
                <div class="flex flex-col items-center justify-center px-6 py-14 text-center">
                    <div class="mb-3 grid h-11 w-11 place-items-center rounded-2xl border border-[var(--line)] bg-[var(--surface-2)] text-gray-400">
                        <x-ui.icon name="list" class="h-5 w-5" />
                    </div>
                    <p class="text-sm font-semibold text-gray-900 dark:text-white" x-text="emptyTitle"></p>
                    <p x-show="emptyText" class="c-muted mt-1 max-w-sm text-[13px]" x-text="emptyText"></p>
                    <div x-show="tab === 'mine' && !search.trim()" class="mt-4">
                        <x-ui.button :href="route('catalogs.create')" variant="primary" icon="plus">{{ __('catalogs.new') }}</x-ui.button>
                    </div>
                </div>
            </div>

            {{-- Rows --}}
            <ul class="divide-y divide-[var(--line)] transition-opacity" :class="loading && catalogs.length && 'opacity-60'">
                <template x-for="c in catalogs" :key="c.id">
                    <li>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 transition-colors hover:bg-[var(--surface-2)]">
                            <button type="button" class="flex min-w-0 flex-1 basis-60 items-start gap-3 text-left" @click="toggle(c)"
                                    :aria-expanded="!!open[c.id]" :title="open[c.id] ? t.hide_peers : t.show_peers">
                                <x-ui.icon name="chevron-right" class="c-muted mt-0.5 transition-transform" ::class="open[c.id] && 'rotate-90'" />
                                <span class="min-w-0">
                                    <span class="flex flex-wrap items-center gap-1.5">
                                        <span class="c-break text-[13px] font-semibold text-gray-950 dark:text-white" x-text="c.title"></span>
                                        <span x-show="c.is_global" class="inline-flex items-center gap-1 rounded-md px-1.5 text-[11px] font-semibold leading-5" :class="$fmt.tone('brand')">
                                            <x-ui.icon name="globe" class="h-3 w-3" />{{ __('catalogs.global') }}
                                        </span>
                                        <span x-show="c.timer_minutes" class="inline-flex items-center gap-1 rounded-md px-1.5 text-[11px] font-semibold leading-5" :class="$fmt.tone('neutral')">
                                            <x-ui.icon name="clock" class="h-3 w-3" /><span x-text="t.timer.replace(':time', duration(c.timer_minutes))"></span>
                                        </span>
                                    </span>
                                    <span class="c-muted mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                                        <span x-show="c.owner && !c.is_mine" class="inline-flex items-center gap-1"><x-ui.icon name="user" class="h-3 w-3" /><span x-text="c.owner?.name"></span></span>
                                        <span class="md:hidden" x-text="$fmt.relative(c.updated_at)"></span>
                                    </span>
                                </span>
                            </button>

                            <div class="w-28 text-right text-[13px] tabular-nums">
                                <span class="font-semibold text-gray-900 dark:text-white" x-text="$fmt.number(c.peers_count)"></span>
                            </div>
                            <div class="c-muted hidden w-32 text-xs md:block" x-text="$fmt.relative(c.updated_at)" :title="$fmt.dateTime(c.updated_at)"></div>

                            <div class="ml-auto flex items-center justify-end gap-1 md:ml-0 md:w-[188px]">
                                <template x-if="!c.is_mine">
                                    <x-ui.button size="sm" variant="ghost" icon="copy" @click="copy(c)" ::disabled="busy">{{ __('catalogs.copy_to_me') }}</x-ui.button>
                                </template>
                                <template x-if="isAdmin">
                                    <x-ui.button size="icon-sm" variant="ghost" @click="openGive(c)" :title="__('catalogs.give')"><x-ui.icon name="users" /></x-ui.button>
                                </template>
                                <template x-if="c.can.update">
                                    <x-ui.button as="a" size="icon-sm" variant="ghost" ::href="urls.edit.replace('__ID__', c.id)" :title="__('common.edit')"><x-ui.icon name="edit" /></x-ui.button>
                                </template>
                                <template x-if="c.can.delete">
                                    <x-ui.button size="icon-sm" variant="danger-ghost" @click="remove(c)" :title="__('common.delete')"><x-ui.icon name="trash" /></x-ui.button>
                                </template>
                            </div>
                        </div>

                        {{-- Peers --}}
                        <div x-show="open[c.id]" x-cloak class="border-t border-[var(--line)] bg-[var(--surface-2)] px-4 py-3 md:pl-11">
                            <p x-show="!c.peers.length" class="c-muted text-xs">{{ __('catalogs.no_peers') }}</p>
                            <template x-if="open[c.id] && c.peers.length">
                                <div>
                                    <div class="flex max-h-56 flex-wrap gap-1.5 overflow-y-auto">
                                        <template x-for="peer in c.peers.slice(0, 500)" :key="peer">
                                            <button type="button" class="inline-flex items-center rounded-md border border-[var(--line)] bg-[var(--surface)] px-2 py-0.5 font-mono text-xs text-gray-700 hover:border-gray-400 dark:text-gray-300"
                                                    @click="$copy(peer)" x-text="peer"></button>
                                        </template>
                                        <span x-show="c.peers.length > 500" class="c-muted self-center text-xs" x-text="t.preview_more.replace(':n', $fmt.number(c.peers.length - 500))"></span>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <x-ui.button size="sm" variant="secondary" icon="copy" @click="$copy(c.peers.join('\n'))">{{ __('catalogs.copy_peers') }}</x-ui.button>
                                        <span class="c-muted text-xs tabular-nums" x-text="t.peers_count.replace(':n', $fmt.number(c.peers_count))"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </li>
                </template>
            </ul>

            <x-ui.pager />
        </section>

        {{-- Give copies to users (superadmin) --}}
        @if ($isAdmin)
            <x-ui.modal show="give" :title="__('catalogs.give_title')" width="max-w-md">
                <p class="c-muted mb-4 text-[13px]" x-text="t.give_text.replace(':title', giveCatalog?.title ?? '')"></p>
                <div class="relative mb-3">
                    <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                    <input type="search" x-ref="userSearch" x-model="userSearch" class="c-input pl-9" placeholder="{{ __('catalogs.users_search') }}" autocomplete="off">
                </div>
                <div class="mb-2 flex items-center justify-between text-xs">
                    <span class="c-muted tabular-nums" x-text="t.users_selected.replace(':n', picked.length)"></span>
                    <div class="flex gap-1">
                        <x-ui.button size="sm" variant="ghost" @click="pickAll()">{{ __('send.select_all') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" @click="picked = []" ::disabled="!picked.length">{{ __('send.select_none') }}</x-ui.button>
                    </div>
                </div>
                <div class="max-h-72 overflow-y-auto rounded-xl border border-[var(--line)]">
                    <div x-show="users === null" class="space-y-2 p-3"><div class="c-skeleton h-8"></div><div class="c-skeleton h-8"></div><div class="c-skeleton h-8"></div></div>
                    <ul class="divide-y divide-[var(--line)]">
                        <template x-for="u in filteredUsers" :key="u.id">
                            <li>
                                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-[var(--surface-2)]">
                                    <input type="checkbox" class="c-check" :checked="picked.includes(u.id)" @change="pick(u)">
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="u.name"></span>
                                        <span class="c-muted block truncate text-xs" x-text="u.email"></span>
                                    </span>
                                    <span x-show="giveCatalog && giveCatalog.owner?.id === u.id" class="c-muted text-[11px]">{{ __('catalogs.col_owner') }}</span>
                                </label>
                            </li>
                        </template>
                    </ul>
                    <p x-show="users !== null && !filteredUsers.length" class="c-muted px-3 py-6 text-center text-xs">{{ __('catalogs.users_empty') }}</p>
                </div>
                <x-slot:footer>
                    <x-ui.button variant="ghost" @click="give = false">{{ __('common.cancel') }}</x-ui.button>
                    <x-ui.button variant="primary" icon="users" @click="submitGive()" ::disabled="busy || !picked.length">{{ __('catalogs.give_action') }}</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endif
    </div>
</x-layouts.app>
