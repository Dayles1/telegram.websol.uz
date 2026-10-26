<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Enums\AccountStatus;
use App\Enums\GroupStatus;
use App\Enums\MessageStatus;
use App\Models\MessageGroup;
use App\Models\SystemEvent;
use App\Models\TelegramMessage;
use App\Models\UserPhone;
use App\Queries\DeliveryStats;
use App\Sending\DailyLimit;
use App\Sending\SendScheduler;
use Illuminate\Support\Facades\Cache;

/**
 * One snapshot of the whole system for the monitoring page, polled every few
 * seconds: are the processes alive, what is being sent, which accounts are in
 * trouble, what went wrong lately.
 */
final class SystemOverview
{
    public function __construct(
        private readonly DailyLimit $dailyLimit,
        private readonly AlertNotifier $alerts,
        private readonly SendScheduler $scheduler,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'processes' => $this->processes(),
            'sending' => $this->sending(),
            'today' => DeliveryStats::between(now()->startOfDay(), now())->totals(),
            'last_24h' => DeliveryStats::between(now()->subHours(23)->startOfHour(), now())->series(),
            'accounts' => $this->accounts(),
            'problems' => $this->problems(),
        ];
    }

    /** @return array<string, mixed> */
    private function processes(): array
    {
        // Sending runs on the queue; telegram:tick (cron, every minute) is its safety net.
        $tick = Cache::get(SendScheduler::TICK_KEY);
        $jobs = $this->scheduler->jobs();

        return [
            'tick' => [
                'alive' => is_array($tick) && time() - (int) $tick['at'] < 180,
                'at' => is_array($tick) ? date(DATE_ATOM, (int) $tick['at']) : null,
                'queued' => is_array($tick) ? (int) ($tick['queued'] ?? 0) : 0,
            ],
            'workers' => $jobs['running'],
            'backoff' => $jobs['backoff'],
            'queue' => QueueHealth::snapshot(),
            'alerts_configured' => $this->alerts->isConfigured(),
        ];
    }

    /** @return array<string, mixed> */
    private function sending(): array
    {
        $groups = MessageGroup::query()
            ->active()
            ->with(['phone:id,user_id,phone,first_name,last_name,username', 'phone.user:id,name'])
            ->withDeliveryCounts()
            ->latest('id')
            ->limit(30)
            ->get();

        return [
            'groups' => $groups->map(fn (MessageGroup $g) => [
                'id' => $g->id,
                'status' => $g->status->value,
                'phone' => $g->phone?->phone,
                'owner' => $g->phone?->user?->name,
                'total' => (int) $g->messages_count,
                'sent' => (int) $g->sent_count,
                'failed' => (int) $g->failed_count,
                'pending' => (int) $g->pending_count,
                'progress' => $g->progressPercent(),
                'created_at' => $g->created_at?->toIso8601String(),
                'text' => mb_substr((string) $g->message_text, 0, 80),
            ])->all(),
            'queued_messages' => TelegramMessage::query()->where('status', MessageStatus::Pending)->count(),
            'in_flight' => TelegramMessage::query()->where('status', MessageStatus::Sending)->count(),
            'active_groups' => MessageGroup::query()->whereIn('status', GroupStatus::activeValues())->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function accounts(): array
    {
        $byStatus = UserPhone::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);

        $attention = UserPhone::query()
            ->with('user:id,name')
            ->where(fn ($q) => $q
                ->whereIn('status', [AccountStatus::Revoked, AccountStatus::Failed])
                ->orWhere('flood_until', '>', now()))
            ->whereNotNull('authorized_at')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (UserPhone $p) => [
                'id' => $p->id,
                'phone' => $p->phone,
                'name' => $p->display_name,
                'owner' => $p->user?->name,
                'status' => $p->status->value,
                'flood_until' => $p->flood_until?->isFuture() ? $p->flood_until->toIso8601String() : null,
                'last_error' => $p->last_error,
                'sent_today' => $this->dailyLimit->sentToday($p->id),
            ]);

        return [
            'by_status' => $byStatus,
            'attention' => $attention,
            'daily_limit' => $this->dailyLimit->limit(),
        ];
    }

    /** @return array<string, mixed> */
    private function problems(): array
    {
        return [
            'open' => SystemEvent::query()->problems()->open()->count(),
            'critical' => SystemEvent::query()->open()->whereIn('level', ['error', 'critical'])->count(),
            'latest' => SystemEvent::query()
                ->problems()
                ->open()
                ->with('phone:id,phone')
                ->latest('last_seen_at')
                ->limit(8)
                ->get()
                ->map(fn (SystemEvent $e) => [
                    'id' => $e->id,
                    'level' => $e->level->value,
                    'kind' => $e->kind->value,
                    'message' => $e->message,
                    'error_key' => $e->error_key,
                    'phone' => $e->phone?->phone,
                    'occurrences' => $e->occurrences,
                    'last_seen_at' => $e->last_seen_at->toIso8601String(),
                ]),
        ];
    }
}
