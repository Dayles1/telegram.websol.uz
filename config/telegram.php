<?php

return [

    /*
    | MadelineProto application credentials (my.telegram.org).
    */
    'api_id' => (int) env('TELEGRAM_API_ID', 0),
    'api_hash' => env('TELEGRAM_API_HASH', ''),

    /*
    | How the sessions present themselves to Telegram. Keep it stable: changing
    | it makes existing sessions look like a new device.
    */
    'device_model' => env('TELEGRAM_DEVICE_MODEL', 'Server'),

    /*
    | The only superadmin. Anyone else with the superadmin role is treated as a
    | plain user (see App\Models\User::isSuperadmin); admins see their own and
    | plain users' accounts, never another admin's.
    */
    'superadmin_email' => env('TELEGRAM_SUPERADMIN_EMAIL', 'superadmin@example.com'),

    /*
    | Session files. Old sessions keep the absolute path stored on their row;
    | new ones are created here.
    */
    'sessions_path' => storage_path('app/sessions'),

    /*
    | MadelineProto's own log. Never inside public/.
    */
    'madeline_log' => storage_path('logs/madeline/madeline.log'),

    /*
    | The one queue of the project: login steps, session checks, chat history, sends,
    | the minute tick. Set in one place, DB_QUEUE, which is also the default queue of
    | the queue connections (config/queue.php). The name carries the project's name:
    | the server runs other projects' workers too. One supervisor program works it
    | (deploy/supervisor.conf); no cron.
    */
    'queue' => env('DB_QUEUE', 'telegramWebsol'),

    /*
    | Login: an attempt that has not moved for this long may be started again.
    */
    'login_stale_after' => 180,

    /*
    | A session another process still has open (it may be saving it on exit):
    | how long a job waits for it before giving up with "session_busy".
    */
    'session_busy_wait' => 30,

    /*
    | Defaults for the values a superadmin can change on the settings page
    | (app_settings overrides them, see App\Settings\Settings).
    */
    'defaults' => [
        'send_delay_min' => 2,            // seconds between two messages of one account
        'send_delay_max' => 5,
        'daily_limit_per_phone' => 1000,  // messages per account per day, 0 = no limit
        'flood_wait_inline_max' => 60,    // flood waits up to this many seconds are slept off in place
        'flood_wait_jitter' => 15,        // extra random seconds added to a flood wait
        'peer_flood_rest_hours' => 24,
        'alerts_enabled' => true,
        'alert_min_level' => 'error',
        'alert_repeat_minutes' => 30,     // the same problem is sent to the bot again after this long
    ],

    'worker' => [
        // A send job stops taking new messages after this many seconds and puts itself back
        // on the queue. Its timeout is max_runtime + 120, which must stay below the queue's
        // retry_after (DB_QUEUE_RETRY_AFTER, 400), or a running job would be started twice.
        'max_runtime' => 240,
        // How many accounts send at the same time. The queue's supervisor program needs at
        // least one process more (numprocs), so a login code never waits behind the sends.
        'send_slots' => (int) env('TELEGRAM_SEND_SLOTS', 3),
        'stale_claim_minutes' => 10, // a row "sending" longer than this goes back to pending
        'max_attempts' => 3,
        // A message still pending this long after it was queued is never sent (status failed,
        // key "expired"): nobody wants yesterday's broadcast delivered today. Also protects
        // the first deploy from rows the old code left pending months ago.
        'expire_pending_hours' => 24,
        'backoff' => [10, 30, 60, 120, 300], // seconds before a failing send job runs again
    ],

    /*
    | Files sent with a message.
    */
    'attachments' => [
        'max_kb' => 51200, // 50 MB; PHP upload_max_filesize/post_max_size and nginx client_max_body_size must allow it
        // A file is deleted from disk once nothing can send it any more: no unfinished send,
        // no active repeating send. It is kept this long after the last send finished, so
        // "send the failed ones again" still has the file.
        'keep_minutes' => 60,
    ],

    /*
    | Alerts to a Telegram chat through a bot (BotFather token + chat id).
    */
    'alerts' => [
        'bot_token' => env('TELEGRAM_ALERT_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_ALERT_CHAT_ID'),
    ],

    /*
    | API request log (Monitoring -> API): body and response of every call with one
    | of these methods. Passwords, tokens and login codes are masked; bodies longer
    | than max_body bytes are cut. monitoring:prune deletes rows older than keep_days.
    */
    'api_log' => [
        'methods' => ['POST'],
        'max_body' => 65536,
        'keep_days' => 30,
    ],

    /*
    | Token for the external statistics dashboard (header X-Stats-Token).
    */
    'stats_token' => env('STATS_API_TOKEN'),
];
