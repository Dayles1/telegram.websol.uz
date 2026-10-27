<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventLevel;
use App\Models\ApiLog;
use App\Models\SystemEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Keeps the journal, the API counters and the API log from growing forever. */
final class PruneMonitoringCommand extends Command
{
    protected $signature = 'monitoring:prune {--info-days=30} {--resolved-days=90} {--api-days=180} {--api-log-days=}';

    protected $description = 'Delete old info events, old resolved problems, old API counters and old API log rows';

    public function handle(): int
    {
        $info = SystemEvent::query()
            ->where('level', EventLevel::Info)
            ->where('last_seen_at', '<', now()->subDays((int) $this->option('info-days')))
            ->delete();

        $resolved = SystemEvent::query()
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', now()->subDays((int) $this->option('resolved-days')))
            ->delete();

        $api = DB::table('api_usage')->where('day', '<', now()->subDays((int) $this->option('api-days'))->toDateString())->delete();

        $logDays = (int) ($this->option('api-log-days') ?: config('telegram.api_log.keep_days', 30));
        $logs = 0;
        do {
            $deleted = ApiLog::query()->where('created_at', '<', now()->subDays($logDays))->limit(5000)->delete();
            $logs += $deleted;
        } while ($deleted > 0);

        $this->info("Deleted {$info} info events, {$resolved} resolved problems, {$api} API counter rows, {$logs} API log rows.");

        return self::SUCCESS;
    }
}
