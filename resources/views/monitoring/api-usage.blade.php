@php
    use Illuminate\Support\Carbon;

    $methodTones = ['GET' => 'info', 'POST' => 'success', 'PUT' => 'warning', 'PATCH' => 'warning', 'DELETE' => 'error'];
    $totalCalls = (int) $used->sum('calls');
    $totalErrors = (int) $used->sum('errors');
@endphp

<x-layouts.app :title="__('monitoring.api.title')" section="monitoring">
    <div x-data="{ q: '' }">
        <x-ui.page-header :title="__('monitoring.api.title')" :description="__('monitoring.api.description')">
            <x-slot:actions>
                <div class="inline-flex gap-0.5 rounded-xl border border-[var(--line-strong)] bg-[var(--surface)] p-0.5">
                    @foreach ([7, 30, 90, 180] as $window)
                        <a href="{{ route('monitoring.api', ['days' => $window]) }}"
                           @class([
                               'inline-flex h-8 items-center rounded-lg px-3 text-xs font-medium whitespace-nowrap transition-colors',
                               'bg-gray-900 text-white dark:bg-white dark:text-gray-900' => $days === $window,
                               'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-400 dark:hover:bg-white/[0.07] dark:hover:text-white' => $days !== $window,
                           ])
                           @if ($days === $window) aria-current="page" @endif>{{ __('monitoring.api.days', ['n' => $window]) }}</a>
                    @endforeach
                </div>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Why this page exists --}}
        <div class="mb-5 flex items-start gap-3 rounded-2xl border border-info-200 bg-info-50 px-4 py-3 text-[13px] text-info-800 dark:border-info-500/30 dark:bg-info-500/10 dark:text-info-200">
            <x-ui.icon name="info" class="mt-0.5" />
            <div class="space-y-1">
                <p>{{ __('monitoring.api.purpose') }}</p>
                <p class="text-xs opacity-80">
                    @if ($tracking)
                        {{ __('monitoring.api.tracking_since', ['date' => Carbon::parse($tracking)->format('d.m.Y'), 'days' => (int) Carbon::parse($tracking)->startOfDay()->diffInDays(now()->startOfDay()) + 1]) }}
                    @else
                        {{ __('monitoring.api.tracking_none') }}
                    @endif
                </p>
            </div>
        </div>

        <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <x-ui.stat :label="__('monitoring.api.used_endpoints')" icon="code">{{ number_format($used->count()) }}</x-ui.stat>
            <x-ui.stat :label="__('monitoring.api.never_called')" icon="alert" :tone="$never->isNotEmpty() ? 'warning' : null">{{ number_format($never->count()) }}</x-ui.stat>
            <x-ui.stat :label="__('monitoring.api.calls')" icon="activity">{{ number_format($totalCalls) }}</x-ui.stat>
            <x-ui.stat :label="__('monitoring.api.errors')" icon="x-circle" :tone="$totalErrors ? 'error' : null">
                {{ number_format($totalErrors) }}
                @if ($totalCalls)
                    <x-slot:hint>{{ __('monitoring.api.error_share', ['pct' => round($totalErrors / $totalCalls * 100, 1)]) }}</x-slot:hint>
                @endif
            </x-ui.stat>
        </div>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
            {{-- Used endpoints --}}
            <section class="c-surface min-w-0 overflow-hidden">
                <div class="c-surface__head">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.api.used_title') }}</h2>
                    <div class="relative w-48 sm:w-64">
                        <x-ui.icon name="search" class="c-muted pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2" />
                        <input type="search" x-model="q" data-page-search class="c-input h-8 pl-8 text-[13px]" placeholder="{{ __('monitoring.api.filter') }}">
                    </div>
                </div>
                @if ($used->isEmpty())
                    <x-ui.empty icon="code" :title="__('monitoring.api.no_calls')" :text="__('monitoring.api.no_calls_text')" />
                @else
                    <div class="c-scroll-x">
                        <table class="c-table">
                            <thead>
                                <tr>
                                    <th>{{ __('monitoring.api.endpoint') }}</th>
                                    <th class="text-right">{{ __('monitoring.api.calls') }}</th>
                                    <th class="text-right">{{ __('monitoring.api.errors') }}</th>
                                    <th>{{ __('monitoring.api.last_call') }}</th>
                                    <th>{{ __('monitoring.api.callers') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($used as $row)
                                    @php
                                        $errorPct = $row->calls ? round($row->errors / $row->calls * 100, 1) : 0;
                                        $haystack = mb_strtolower($row->method.' '.$row->endpoint.' '.$row->callers);
                                    @endphp
                                    <tr class="c-row" x-show="!q || @js($haystack).includes(q.toLowerCase())">
                                        <td>
                                            <span class="inline-flex items-center gap-2">
                                                <x-ui.badge :tone="$methodTones[$row->method] ?? 'neutral'" class="w-14 justify-center font-mono">{{ $row->method }}</x-ui.badge>
                                                <span class="c-break font-mono text-xs text-gray-900 dark:text-white">{{ $row->endpoint }}</span>
                                            </span>
                                        </td>
                                        <td class="text-right font-semibold tabular-nums">{{ number_format((int) $row->calls) }}</td>
                                        <td class="text-right tabular-nums">
                                            @if ($row->errors)
                                                <span @class(['text-error-600' => $errorPct >= 5, 'text-warning-600' => $errorPct < 5])>{{ number_format((int) $row->errors) }}</span>
                                                <span class="c-muted text-xs">{{ $errorPct }}%</span>
                                            @else
                                                <span class="c-muted">0</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap">
                                            @if ($row->last_called_at)
                                                @php $iso = Carbon::parse($row->last_called_at)->toIso8601String(); @endphp
                                                <span :title="$fmt.dateTime(@js($iso))" x-text="$fmt.relative(@js($iso))"></span>
                                            @else
                                                <span class="c-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="c-muted max-w-[260px] text-xs"><span class="c-break line-clamp-2" title="{{ $row->callers }}">{{ $row->callers }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- Never called --}}
            <section class="c-surface min-w-0 overflow-hidden">
                <div class="c-surface__head">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('monitoring.api.never_title') }}</h2>
                    <span class="c-muted text-xs tabular-nums">{{ $never->count() }}</span>
                </div>
                <p class="c-muted border-b border-[var(--line)] px-4 py-2.5 text-xs">{{ __('monitoring.api.never_hint', ['n' => $days]) }}</p>
                @if ($never->isEmpty())
                    <x-ui.empty icon="check-circle" :title="__('monitoring.api.all_used')" class="!py-8" />
                @else
                    <ul class="max-h-[640px] divide-y divide-[var(--line)] overflow-y-auto">
                        @foreach ($never as $route)
                            @php [$method, $uri] = explode(' ', $route, 2); @endphp
                            <li class="flex items-center gap-2 px-4 py-2" x-show="!q || @js(mb_strtolower($route)).includes(q.toLowerCase())">
                                <x-ui.badge :tone="$methodTones[$method] ?? 'neutral'" class="w-14 justify-center font-mono">{{ $method }}</x-ui.badge>
                                <span class="c-break min-w-0 flex-1 font-mono text-xs text-gray-800 dark:text-gray-200">{{ $uri }}</span>
                                <button type="button" class="c-muted shrink-0 hover:text-gray-900 dark:hover:text-white" @click="$copy(@js($route))" title="{{ __('monitoring.copy') }}"><x-ui.icon name="copy" class="h-3.5 w-3.5" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
