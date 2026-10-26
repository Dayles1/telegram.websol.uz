# telegram.websol.uz

Панель рассылок в Telegram от собственных аккаунтов пользователей (MadelineProto, не боты).
Пользователь подключает телефон, ведёт списки получателей (каталоги), отправляет текст и/или
файл (до 50 МБ) всем получателям выбранных каталогов или одному получателю (API), может
повторять рассылку по расписанию и в реальном времени видит доставку.

Laravel 12 · PHP 8.2+ · MySQL · MadelineProto 8 · Blade + Alpine + Tailwind 4 (Vite) · Sanctum API.

## Как это устроено

```
Браузер / API ──► контроллер ──► Action (транзакция, проверки) ──► строки в БД
                                                                     │
   queue:work --queue=telegramWebsol ◄──────────────────────────────┘
   (одна программа supervisord, cron не нужен; всё — задачи одной очереди)
      ├─ логин, проверки сессий, история чатов
      ├─ SendAccountMessages {account}: строки pending → sending (атомарно), шлёт, пишет итог;
      │  время вышло — джоба сама возвращается в очередь; одновременно не больше
      │  TELEGRAM_SEND_SLOTS аккаунтов, чтобы вход не ждал рассылки
      └─ MinuteTick (воркер сам ставит её раз в минуту): починка, следующие круги повторов,
         аккаунты с ожидающими сообщениями → в очередь, удаление ненужных файлов,
         раз в день — проверка сессий, история чатов, чистка журнала
```

- **MadelineProto никогда не запускается в веб-запросе.** Веб меняет строки, процессы шлют,
  страницы опрашивают JSON.
- Один процесс на сессию (flock), строка уходит ровно один раз (условный UPDATE).
- FLOOD_WAIT ставит аккаунт на паузу, а не роняет сообщения; PEER_FLOOD — отдых 24 ч;
  мёртвая сессия — аккаунт `revoked`, алерт.
- Всё важное пишется в журнал `system_events` (Мониторинг → События) и при ошибках уходит в
  Telegram-чат через бота.

Подробнее: [`CLAUDE.md`](CLAUDE.md), навыки в [`.claude/skills`](.claude/skills),
план и история переписывания — [`docs/REWRITE_PLAN.md`](docs/REWRITE_PLAN.md),
выкатка — [`docs/DEPLOY.md`](docs/DEPLOY.md), API — [`docs/API.md`](docs/API.md) и коллекция Postman
в [`docs/postman/`](docs/postman).

## Локально

```bash
composer install
npm install && npm run build      # или npm run dev
cp .env.example .env && php artisan key:generate   # APP_ENV=local, APP_DEBUG=true, своя БД
php artisan migrate
php artisan serve
php artisan queue:work --queue=telegramWebsol --sleep=3 --tries=3 --timeout=370 --max-time=3600   # всё: логин, отправка, минутная проверка
```

## Тесты

```bash
mysql -uroot -e "CREATE DATABASE telegram_websol_test"
php artisan test
```

Тесты идут на MySQL с настоящими миграциями; Telegram подменяется `tests/Fakes/FakeTelegram`.

## Команды

| Команда | Что делает |
|---|---|
| `telegram:check-sessions` | ставит в очередь проверку всех сессий (MinuteTick запускает её раз в день после 06:00) |
| `chat-logs:refetch` | ставит в очередь перечитывание отслеживаемых чатов (после 00:00) |
| `monitoring:prune` | чистит старый журнал и счётчики API (после 03:30) |
