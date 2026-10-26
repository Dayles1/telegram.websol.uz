<x-layouts.app :title="__('dashboard.title')">
    <div x-data="dashboardPage({ url: @js(route('dashboard.data')) })">
        <x-ui.page-header :title="__('dashboard.hello', ['name' => auth()->user()->name])" :description="__('dashboard.description')">
            <x-slot:actions>
                <x-ui.button :href="route('catalogs.create')" icon="plus">{{ __('nav.new_catalog') }}</x-ui.button>
                <x-ui.button :href="route('send.create')" variant="primary" icon="send">{{ __('dashboard.new_send') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Today --}}
        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat :label="__('dashboard.sent_today')" icon="check-circle" tone="success">
                <span x-text="$fmt.number(today.sent)"></span>
                <x-slot:hint><span x-show="successRate !== null" x-text="@js(__('dashboard.success_rate')).replace(':rate', successRate)"></span></x-slot:hint>
            </x-ui.stat>
            <x-ui.stat :label="__('dashboard.failed_today')" icon="x-circle" tone="error"><span x-text="$fmt.number(today.failed)"></span></x-ui.stat>
            <x-ui.stat :label="__('dashboard.in_queue')" icon="clock"><span x-text="$fmt.number(today.pending)"></span></x-ui.stat>
            <x-ui.stat :label="__('dashboard.active_sends')" icon="zap" tone="brand">
                <span class="inline-flex items-center gap-2"><span x-show="active.length" class="c-dot is-live h-2 w-2 text-brand-500"></span><span x-text="active.length"></span></span>
            </x-ui.stat>
        </div>

        <div class="grid gap-5 xl:grid-cols-[1fr_380px]">
            <div class="min-w-0 space-y-5">
                {{-- Live --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-white">
                            <span class="c-dot text-brand-500" :class="active.length && 'is-live'"></span> {{ __('dashboard.live') }}
                        </h2>
                        <a href="{{ route('sends.index', ['status' => 'active']) }}" class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ __('dashboard.all_active') }} →</a>
                    </div>
                    <div x-show="loading" class="space-y-3 p-4"><div class="c-skeleton h-12"></div><div class="c-skeleton h-12"></div></div>
                    <div x-show="!loading && !active.length" x-cloak>
                        <x-ui.empty icon="send" :title="__('dashboard.nothing_sending')" :text="__('dashboard.nothing_sending_text')" />
                    </div>
                    <div class="divide-y divide-[var(--line)]">
                        <template x-for="g in active" :key="g.id"><x-ui.group-row /></template>
                    </div>
                </section>

                {{-- Recent --}}
                <section class="c-surface overflow-hidden">
                    <div class="c-surface__head">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('dashboard.recent') }}</h2>
                        <a href="{{ route('sends.index') }}" class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ __('dashboard.history') }} →</a>
                    </div>
                    <div x-show="!loading && !recent.length" x-cloak><x-ui.empty icon="history" :title="__('dashboard.no_history')" /></div>
                    <div class="divide-y divide-[var(--line)]">
                        <template x-for="g in recent" :key="g.id"><x-ui.group-row /></template>
                    </div>
                </section>
            </div>

            <aside class="min-w-0 space-y-5">
                <x-ui.surface :title="__('dashboard.week')">
                    <x-ui.bar-chart series="week" :height="120" />
                    <div class="c-muted mt-3 flex gap-4 text-xs">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-brand-500"></span>{{ __('dashboard.delivered') }}</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-error-400"></span>{{ __('dashboard.errors') }}</span>
                    </div>
                </x-ui.surface>

                <x-ui.surface :title="__('dashboard.my_phones')" :padded="false">
                    <x-slot:actions><a href="{{ route('phones.index') }}" class="c-muted text-xs hover:text-gray-900 dark:hover:text-white">{{ __('dashboard.manage') }} →</a></x-slot:actions>
                    <div x-show="!loading && !phones.length" x-cloak>
                        <x-ui.empty icon="phone" :title="__('phones.empty_title')">
                            <x-ui.button :href="route('phones.index').'#connect'" size="sm" variant="primary" icon="plus">{{ __('phones.connect') }}</x-ui.button>
                        </x-ui.empty>
                    </div>
                    <ul class="divide-y divide-[var(--line)]">
                        <template x-for="p in phones" :key="p.id">
                            <li class="flex items-center gap-3 px-4 py-3">
                                <span class="c-dot" :class="$fmt.dot(p.tone)"></span>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-[13px] font-medium text-gray-900 dark:text-white" x-text="p.name"></div>
                                    <div class="c-muted font-mono text-xs" x-text="p.phone"></div>
                                </div>
                                <span class="text-[11px] font-semibold" :class="$fmt.dot(p.tone)" x-text="p.flood_until ? @js(__('phones.paused')) : p.status_label"></span>
                            </li>
                        </template>
                    </ul>
                </x-ui.surface>
            </aside>
        </div>
    </div>
</x-layouts.app>
