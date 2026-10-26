<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The project works one queue now. Send jobs still waiting on the old sending
 * queue move to it: left behind, nobody would take them, and their unique lock
 * would keep the account from being queued again for an hour.
 * Only rows of the jobs table, safe to run while nothing works the old queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('jobs')
            ->where('queue', 'telegramWebsol-sending')
            ->update(['queue' => config('telegram.queue')]);
    }

    public function down(): void
    {
        // Irreversible on purpose: the moved jobs can't be told apart, and either queue name
        // is worked by the one worker of this version.
    }
};
