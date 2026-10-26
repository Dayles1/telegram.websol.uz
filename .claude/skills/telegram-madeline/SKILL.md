---
name: telegram-madeline
description: How this project talks to Telegram through MadelineProto user accounts - login state machine, sessions, send workers, flood/dead-session handling, tests. Load before touching app/Telegram, app/Accounts, app/Sending, app/Jobs, app/Console/Commands, or any code that sends messages or logs a phone in.
---

# Telegram via MadelineProto

## Golden rule

**MadelineProto never runs inside a web request.** Controllers change DB state and
dispatch; a CLI process (queue worker or send worker) owns the session and writes the
outcome back to the row; the UI polls JSON. No `exec()`, no shell strings. Child processes
are started with `Symfony\Component\Process\Process` and an argument array of ids only.

All Telegram calls go through `App\Telegram\Contracts\TelegramGateway` /
`TelegramSession` (real: `App\Telegram\Madeline\*`, tests: `tests/Fakes/FakeTelegram`).

## Sessions

- One session per phone at `storage/app/sessions/{digits}_user_{userId}.madeline`
  (`App\Telegram\SessionStore::pathFor`). Old rows keep the absolute path stored in
  `user_phones.session_path` — never move existing sessions.
- Only `SessionStore::delete()` removes session files, and only inside the sessions dir.
- Phones are normalized to `+digits` by `App\Telegram\Phone`; lookups use the
  `UserPhone::wherePhone()` scope (old rows may lack the "+").
- Never two full processes on one session: `App\Telegram\ProcessLock::for("account-{id}")`
  (flock, released by the OS on crash).
- One configuration for every session: `App\Telegram\Madeline\SettingsFactory` (same device
  model, no prompts, Madeline log in `storage/logs/madeline/`, never in `public/`). Values come
  from `config/telegram.php`; `env()` only in config.
- Vendor calls are wrapped in `App\Telegram\Madeline\NoticeShield::guard()`.

## Login state machine

`requesting_code → code_sent → verifying → [need_password → verifying] → authorized`
Errors: `code_invalid` (type the code again), `password_invalid`, `failed` (start again).
Later: `authorized → revoked` (dead session), `authorized → logging_out → logged_out`.

- `App\Enums\AccountStatus` on `user_phones.status`, driven by `App\Accounts\AccountLogin`
  (web/API side) and `app/Jobs/Accounts/*` (Telegram side).
- Start in `DB::transaction` + `lockForUpdate`; refuse when in flight unless stale
  (`config('telegram.login_stale_after')`); dispatch with `->afterCommit()`.
- The 2FA password never goes into a job payload or a log: encrypted in the cache for five
  minutes (`AccountLogin::passwordCacheKey`), the job carries only the account id.
- Login jobs: `$tries = 1` (a login step must not be replayed), encrypted payload,
  `failed()` never leaves the row in flight.

## Sending

1. `App\Sending\MassSend::create(User, UserPhone, SendOrder)`: ownership of phone and catalogs,
   unique peers of catalogs + direct peers (`App\Sending\Peers`; "+998..." is a phone and is
   resolved by number), catalog cooldown (`CatalogCooldown`, same transaction), optional file
   (`Attachments`, local disk, never public) and repeat (`SendLoop`), group + one pending row
   per peer, then `SendAccountMessages::dispatch($phoneId)`. Nothing is sent here.
2. `App\Jobs\SendAccountMessages` (queue `telegramWebsol-sending`, `ShouldBeUnique` per account, worker
   started with `--max-jobs=1`) runs `SendWorker` for `worker.max_runtime` seconds; time up
   with rows left → `release(pause)`, failing → `release(backoff)`, busy → `release(30)`.
3. `telegram:tick` (`App\Sending\SendScheduler`, every minute): sweeps stale claims and
   orphaned rows, starts overdue repeat rounds, queues every account with due rows,
   deletes attachments nothing can send any more (`telegram.attachments.keep_minutes`).
4. `App\Sending\SendWorker` claims each row with a conditional UPDATE
   (`pending → sending`) — a row can't go out twice; paces with the settings' delays (also
   across runs, from `last_sent_at`); checks the daily limit; re-reads the account every
   message; yields after the message in hand on SIGTERM or `queue:restart`. A file goes with
   the text as caption (≤1024, longer text follows as a second message); the upload is
   reused for the next peers of the same run.
5. `App\Sending\GroupProgress` is the only place a group status changes after creation; a
   finished round of a repeat schedules `StartLoopRound` after `every_minutes`; canceling a
   round stops the repeat. GET handlers never write.

Worker outcomes: `0` done, `1` retry with backoff, `2` not startable, `3` busy, `4` more (run again).

## Errors

`App\Telegram\Errors\ErrorMap::classify($e)` → `TelegramError {key, kind, waitSeconds}`.
Keys are stored on rows and translated in `lang/*/errors.php` (`codes.*`).

- `Wait` (FLOOD_WAIT_N): short waits are slept off; long ones set `flood_until` (+ jitter),
  the row goes back to pending, the worker exits.
- `PeerFlood`: the account rests `peer_flood_rest_hours`, alert.
- `Frozen`: the account stops for 7 days, critical alert.
- `DeadSession`: `App\Accounts\AccountSessions::retire()` → `revoked`, pending rows fail.
- `Transient`: retry up to `worker.max_attempts`; a streak makes the worker exit.
- `Peer` / unknown: fail that row only.

Everything worth seeing later goes through `App\Monitoring\EventRecorder`
(journal `system_events`, log channel `telegram`, alert bot for errors).

## Testing

Never hit Telegram in tests. `tests/TestCase.php` binds `FakeTelegram`; script failures with
`$this->telegram->failPeer('@x', 'FLOOD_WAIT_600')` or
`$this->telegram->failNext('submitCode', 'PHONE_CODE_INVALID')`. Run a worker with
`app(SendWorker::class)->sleepUsing(fn () => null)->run($phoneId, 60)`. Send jobs are faked in
`tests/TestCase.php` (`Bus::fake([SendAccountMessages::class, StartLoopRound::class])`); use
`Storage::fake('local')` for files and `$this->telegram->filesSent()` to check them.
