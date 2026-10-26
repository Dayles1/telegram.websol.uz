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
    | Session files. Old sessions keep the absolute path stored on their row;
    | new ones are created here.
    */
    'sessions_path' => storage_path('app/sessions'),

    /*
    | MadelineProto's own log. Never inside public/.
    */
    'madeline_log' => storage_path('logs/madeline/madeline.log'),

    /*
    | Queues. Every name starts with "telegramWebsol", so this project's workers are
    | easy to tell apart from other projects' on the server. "telegramWebsol" takes
    | login steps, session checks and everything else (it is also the connection's
    | default queue); "telegramWebsol-sending" takes the per-account send jobs (each
    | runs up to worker.max_runtime). Separate so a long send never makes a login
    | code wait.
    */
    'queue' => env('TELEGRAM_QUEUE', 'telegramWebsol'),
    'send_queue' => env('TELEGRAM_SEND_QUEUE', 'telegramWebsol-sending'),

    /*
    | Login: an attempt that has not moved for this long may be started again.
    */
    'login_stale_after' => 180,

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
    | Token for the external statistics dashboard (header X-Stats-Token).
    */
    'stats_token' => env('STATS_API_TOKEN'),
];
