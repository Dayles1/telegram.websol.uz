<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EventLevel;
use App\Models\SystemEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Keeps the journal and the API counters from growing forever. */
final class PruneMonitoringCommand extends Command
{
    protected $signature = 'monitoring:prune {--info-days=30} {--resolved-days=90} {--api-days=180}';

    protected $description = 'Delete old info events, old resolved problems and old API counters';

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

        $this->info("Deleted {$info} info events, {$resolved} resolved problems, {$api} API counter rows.");

        return self::SUCCESS;
    }
}
