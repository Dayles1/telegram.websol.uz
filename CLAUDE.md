# telegram.websol.uz

Panel for sending messages to Telegram peers from users' own Telegram accounts
(MadelineProto user sessions, not bots). Users connect a phone, keep peer lists
("catalogs"), send a text to all peers of chosen catalogs, and watch delivery.

Laravel 12, PHP 8.2+, MySQL in production, MadelineProto 8, Sanctum API.
Rewritten in place in 2026-10 (plan: `docs/REWRITE_PLAN.md`, rollout: `docs/DEPLOY.md`).
The reference for "how we build now" is the newer project Postix
(https://github.com/Dayles1/Postix, its `app/Application/Telegram/**` module, not its old V2/V3 code).

## Hard rules

- **The production database must never break.** Before touching migrations, models'
  tables, or any `artisan migrate|db:*` command, load the `safe-prod-migrations` skill.
- **MadelineProto never runs in a web request**, and nothing builds a shell string from
  input. Load the `telegram-madeline` skill before touching login, sessions or sending.
- Any UI work: load the `ui-console-design` skill first.
- Every query that reads or changes a phone, catalog, group or message is scoped by
  ownership through a Policy / `User::oversees()` + `limitToOverseen()` (`superadmin`, one user by
  `telegram.superadmin_email`, sees everything; `admin` has the same rights except accounts: own + plain
  users', never another admin's, and cannot edit another admin). No IDOR.
- Never write files into `public/` from code (Madeline logs, sessions, phars).
- Do not delete API endpoints until `docs/REWRITE_PLAN.md` marks them as confirmed unused
  (API consumers are external and not fully known).

## Architecture

```
app/
  Enums/                    backed string enums: AccountStatus, GroupStatus, MessageStatus, TaskStatus,
                            EventLevel, EventKind, Role (user/admin/superadmin) (label()/tone() for the UI)
  Models/                   Eloquent: relations, casts (Casts/LenientEnum for status columns), scopes
  Policies/                 ownership; superadmin passes in before()
  Accounts/                 AccountLogin (phone -> code -> 2FA state machine), AccountSessions (check,
                            logout, remove, retire a dead session)
  Sending/                  MassSend (create a send / a repeat round), SendOrder, CatalogCooldown,
                            Peers (normalization), GroupProgress (the only place a group status
                            changes; schedules repeat rounds), DailyLimit, Attachments (files, cleanup),
                            SendWorker (one account, one process), SendScheduler (minute tick)
  Telegram/                 the MadelineProto boundary: Contracts/TelegramGateway + TelegramSession,
                            Madeline/* (real implementation), Errors/ErrorMap (RPC error -> key + kind),
                            SessionStore, ProcessLock, Phone
  Monitoring/               EventRecorder (system_events journal), AlertNotifier (Telegram bot),
                            QueueHealth, SystemOverview, ApiLogRecorder (api_logs: POST body + response,
                            secrets masked; Monitoring -> API)
  Queries/                  read side: GroupList, DeliveryStats (analytics)
  Settings/                 runtime settings (app_settings over config/telegram.php defaults)
  ChatLogs/                 chat history copy (API fetch-messages / chat-logs)
  Jobs/                     everything on the one queue "telegramWebsol": SendAccountMessages (unique per
                            account, with FetchChatHistory at most worker.send_slots at once: Jobs/WorkerSlots), MinuteTick (the clock, queued by the
                            worker itself; no cron), StartLoopRound, Accounts/* login steps, FetchChatHistory;
                            ids only in payloads
  Console/Commands/         telegram:check-sessions, chat-logs:refetch, monitoring:prune (MinuteTick runs them daily)
  Http/Controllers/Web/     Blade pages + their JSON endpoints (Alpine polls them)
  Http/Controllers/Api/V1/  the external API: old URLs and response shapes kept on purpose
  Http/Resources/           JSON for the web pages
resources/views/components/{layouts,ui}/   shell and the UI kit (x-ui.*)
resources/js/pages/<page>.js               one Alpine factory per page, auto-registered as <page>Page
tests/Fakes/FakeTelegram.php               Telegram in tests
```

- `declare(strict_types=1);`, `final` classes, constructor promotion, `readonly` where possible.
- Controllers: validate → authorize (Policy/Gate) → call Accounts/Sending/... → return view/Resource/JSON.
- Refusals the user can understand: throw `App\Exceptions\ActionRefused('reason')` (lang `errors.refused.*`).
- Anything worth seeing later goes through `EventRecorder` (journal + `telegram` log channel + alert bot).
- API changes: keep old fields, only add. `api_usage` (Monitoring -> API) shows what is called.
- Statuses are string columns + PHP backed enums; never compare raw strings in code.
- Config via `config/telegram.php`; `env()` only inside `config/`.
- Logs: short English message + context array (`account_id`, `group_id`, `error_key`),
  channel `telegram` for the Telegram side. No emoji, no Uzbek/Russian in logs.
- UI strings: `lang/{uz,ru}` (Uzbek in Latin script); default locale `uz`, fallback `ru`.

## Commands

- Tests: `php artisan test` (MySQL test database, see `phpunit.xml`).
- Style: `vendor/bin/pint`.
- Frontend: `npm run dev` / `npm run build`.
- Queue: `php artisan queue:work --queue=telegramWebsol --sleep=3 --tries=3 --timeout=370 --max-time=3600` — the only process the project needs
  (the name carries the project's name: the server runs other projects' workers too).
  Production: one supervisor program, numprocs = TELEGRAM_SEND_SLOTS + 1. The queue name is set
  only in .env `DB_QUEUE` (read by config/queue.php and config('telegram.queue'))
  (`deploy/supervisor.conf`). No cron, no scheduler, no long-running commands of our own.
- API docs: `docs/API.md` + `docs/postman/*.json` — update them with every API change.

## Git

Conventional commits with a scope, lowercase plain-English subject describing the outcome:
`feat(sending): a flood wait pauses the account instead of failing the message`.
Small commits per step. Never create or switch branches without the user's explicit permission.
