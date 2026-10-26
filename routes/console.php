<?php

use Illuminate\Support\Facades\Schedule;

// Safety net of the send queue: repairs, overdue repeat rounds, accounts with due
// messages, unused files. Sends are queued on creation; this catches the rest.
Schedule::command('telegram:tick')->everyMinute()->withoutOverlapping(10);

Schedule::command('telegram:check-sessions')->dailyAt('06:00');
Schedule::command('chat-logs:refetch')->dailyAt('00:00');
Schedule::command('monitoring:prune')->dailyAt('03:30');
Schedule::command('queue:prune-failed --hours=720')->daily();
