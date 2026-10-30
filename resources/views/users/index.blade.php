<x-layouts.app :title="__('users.title')">
    <x-ui.page-header :title="__('users.title')" :description="__('users.description')">
        <x-slot:actions>
            <x-ui.button :href="route('users.create')" variant="primary" icon="plus">{{ __('users.create') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <section class="c-surface overflow-hidden">
        <form method="GET" action="{{ route('users.index') }}" class="flex flex-col gap-2 border-b border-[var(--line)] p-3 sm:flex-row sm:items-center">
            <label class="relative block min-w-0 flex-1 sm:max-w-md">
                <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-3 top-1/2 -translate-y-1/2" />
                <input type="search" name="search" value="{{ $search }}" data-page-search class="c-input pl-9" placeholder="{{ __('users.search_placeholder') }}" aria-label="{{ __('common.search') }}">
            </label>
            <div class="flex gap-2">
                <x-ui.button type="submit" variant="secondary">{{ __('common.search') }}</x-ui.button>
                @if ($search !== '')
                    <x-ui.button :href="route('users.index')" variant="ghost" icon="x">{{ __('common.reset') }}</x-ui.button>
                @endif
            </div>
            <span class="c-muted text-xs sm:ml-auto">{{ __('users.month_hint') }}</span>
        </form>

        @if ($users->isEmpty())
            <x-ui.empty icon="users" :title="$search !== '' ? __('common.nothing_found') : __('users.empty')" />
        @else
            {{-- Phone: one card per user --}}
            <ul class="divide-y divide-[var(--line)] sm:hidden">
                @foreach ($users as $u)
                    @php
                        $stats = $month->get($u->id) ?? ['groups' => 0, 'sent' => 0, 'failed' => 0];
                        $role = $u->roleName();
                    @endphp
                    <li class="p-3">
                        <div class="flex items-start gap-3">
                            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $u->initials }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="truncate font-medium text-gray-900 dark:text-white">{{ $u->name }}</span>
                                    @if ($u->id === auth()->id())<span class="c-muted shrink-0 text-xs">· {{ __('users.you') }}</span>@endif
                                    <x-ui.badge :tone="$role->tone()" class="ml-auto shrink-0">{{ $role->label() }}</x-ui.badge>
                                </div>
                                <div class="c-muted truncate text-xs">{{ $u->email }}</div>
                                <div class="c-muted mt-1 text-xs tabular-nums">
                                    {{ __('users.col_phones') }}: <span class="font-semibold text-gray-900 dark:text-white">{{ $u->phones_count }}</span>
                                    · {{ __('users.col_sent') }}: <span class="font-semibold text-gray-900 dark:text-white">{{ number_format($stats['sent'], 0, '.', ' ') }}</span>
                                    @if ($stats['failed'])
                                        · <span class="text-error-600 dark:text-error-400">{{ __('users.col_failed') }}: {{ number_format($stats['failed'], 0, '.', ' ') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 flex items-center gap-1">
                            @can('create', [App\Models\UserPhone::class, $u])
                                <x-ui.button :href="route('phones.index', ['owner' => $u->id]).'#connect'" size="sm" variant="secondary" icon="phone" class="flex-1">{{ __('users.connect_phone') }}</x-ui.button>
                            @endcan
                            @include('users.row-actions')
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="hidden overflow-x-auto sm:block">
                <table class="c-table min-w-[820px]">
                    <thead>
                        <tr>
                            <th>{{ __('users.col_user') }}</th>
                            <th class="w-32">{{ __('users.role') }}</th>
                            <th class="w-24 text-right">{{ __('users.col_phones') }}</th>
                            <th class="w-24 text-right">{{ __('users.col_catalogs') }}</th>
                            <th class="w-24 text-right">{{ __('users.col_groups') }}</th>
                            <th class="w-28 text-right">{{ __('users.col_sent') }}</th>
                            <th class="w-28 text-right">{{ __('users.col_failed') }}</th>
                            <th class="w-48"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $u)
                            @php
                                $stats = $month->get($u->id) ?? ['groups' => 0, 'sent' => 0, 'failed' => 0];
                                $done = $stats['sent'] + $stats['failed'];
                                $role = $u->roleName();
                            @endphp
                            <tr class="c-row">
                                <td>
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">{{ $u->initials }}</span>
                                        <div class="min-w-0">
                                            @can('update', $u)
                                                <a href="{{ route('users.edit', $u) }}" class="block truncate font-medium text-gray-900 hover:underline dark:text-white">
                                                    {{ $u->name }}
                                                    @if ($u->id === auth()->id())<span class="c-muted font-normal">· {{ __('users.you') }}</span>@endif
                                                </a>
                                            @else
                                                <span class="block truncate font-medium text-gray-900 dark:text-white">{{ $u->name }}</span>
                                            @endcan
                                            <div class="c-muted truncate text-xs">{{ $u->email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td><x-ui.badge :tone="$role->tone()">{{ $role->label() }}</x-ui.badge></td>
                                <td class="text-right tabular-nums">{{ number_format($u->phones_count, 0, '.', ' ') }}</td>
                                <td class="text-right tabular-nums">{{ number_format($u->catalogs_count, 0, '.', ' ') }}</td>
                                <td class="text-right tabular-nums">{{ number_format($stats['groups'], 0, '.', ' ') }}</td>
                                <td class="text-right tabular-nums font-semibold text-gray-900 dark:text-white">{{ number_format($stats['sent'], 0, '.', ' ') }}</td>
                                <td class="text-right tabular-nums">
                                    @if ($stats['failed'])
                                        <span class="text-error-600 dark:text-error-400">{{ number_format($stats['failed'], 0, '.', ' ') }}</span>
                                        <span class="c-muted block text-[11px]">{{ round($stats['failed'] / max(1, $done) * 100) }}%</span>
                                    @else
                                        <span class="c-muted">0</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center justify-end gap-1">
                                        @can('create', [App\Models\UserPhone::class, $u])
                                            <x-ui.button :href="route('phones.index', ['owner' => $u->id]).'#connect'" size="sm" variant="secondary" icon="phone">{{ __('users.connect_phone') }}</x-ui.button>
                                        @endcan
                                        @include('users.row-actions')
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $users->links() }}
        @endif
    </section>
</x-layouts.app>
