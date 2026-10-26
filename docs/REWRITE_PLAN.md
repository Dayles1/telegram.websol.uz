# План переписывания telegram.websol.uz

## Статус (2026-10-27)

Сделано: этапы 0, 3–8 (хотфиксы безопасности вошли в переписывание, мёртвый код удалён, новое ядро
Telegram, авторизация телефонов, воркеры отправки, мониторинг, весь интерфейс).
Выкатка — по `docs/DEPLOY.md`.

2026-10-26: свой супервизор отправки заменён стандартной очередью Laravel (`SendAccountMessages`,
одна очередь `telegramWebsol` (имя только в `DB_QUEUE`), одна программа supervisord; минутную проверку `MinuteTick` ставит в очередь сам воркер, cron не нужен); файлы до 50 МБ (удаляются, когда больше
не нужны); повторяющиеся рассылки (`send_loops`, вместо нерабочих «бесконечных»); в API `tg/send`
принимает один `peer` вместо каталогов; единый формат ошибок API; документация `docs/API.md` и
коллекция Postman `docs/postman/`.

Осталось:
- **Этап 1 на проде**: бэкап, `migrate:status`, сверка схемы (до `migrate`).
- Решения этапа 2 приняты по умолчанию: «бесконечные» отвечают 410, чат-логи и `single-messages`
  сохранены и закрыты проверкой владельца, `stats/*` закрыты токеном `STATS_API_TOKEN`,
  экспорт удалён. Пересмотреть через 2–4 недели по Мониторинг → API.
- Релиз «сузить» (удаление старых колонок/таблиц) — не раньше, чем через месяц стабильной работы.

Снимок рабочей версии до переписывания: коммит `main` на GitHub (Dayles1/telegram.websol.uz).
Образец «как я строю сейчас» — Postix, **только его новый модуль** `app/Application/Telegram/**`,
`app/Telegram/*` (старые V2/V3 в Postix — такой же долг, как здесь).

Главное ограничение: **продовая БД (MySQL) не ломается ни на одном шаге.** Переписываем на месте,
таблицы те же, все изменения схемы — по схеме «расширить → перенести → сузить»
(см. `.claude/skills/safe-prod-migrations`).

---

## 0. Что нашли (кратко)

**Живой код** — это примерно 20% проекта:

- Вход по email/паролю (web + API-токен).
- Подключение телефона: телефон → код → 2FA (`TelegramAuthJob`, `TelegramVerifyJob`, `CompleteLoginJob`).
- Каталоги (списки пиров) с таймером-кулдауном (`CatalogTimerService`).
- Массовая отправка: контроллер → `Jobs\SendTelegramMessage` → `exec(nohup … telegram:send-messages)`
  → `Console\Commands\SendTelegramMessage`. Это **единственный** живой отправщик.
- Статус / история / детали группы, отмена.
- Пользователи (суперадмин), профиль, аватар.
- API: копия всего этого + одиночные сообщения (`single-messages`), чат-логи, статистика.

**Мёртвый код** (ничто его не вызывает): команды V2 (две с одинаковой сигнатурой!), V3, V4, V5;
`Jobs/V2/*`, `SendTelegramMessages`, `TestJob`; `Services/V2`, `Services/Telegram/TelegramPeerMessagingService`,
оба `MadelineService`, оба `TelegramErrorService`; `Application/Handlers`, `Infrastructure/*`;
контроллеры `V2/*`, `Api/V2/*`, `Api/PeerController`, `Api/External/QueryController`, `Export/*`;
`app/Exports` (пакет excel даже не установлен); middleware `CheckPermission`, `CheckUserBan`, `RoleCheck`,
`SetLocale`; `EventServiceProvider`, `Exceptions/Handler`, `ApiException`; модели `Ban`, `Limit`,
`Department`, `Peer`, `Query`; `PhoneHelper`, `CustomizeFormatter`, `config/nutgram.php`; вьюхи
`welcome`, `auth/register`, `telegram/send`, `catalogs/partials/*`, `users/show` (пустой файл).

**Сломано сейчас:**

- «Бесконечные» сообщения (`infinity`) создаются, но **их никто никогда не отправляет**; вставка, скорее всего, падает (NOT NULL `message_text`).
- `GET /api/groups/{id}` падает (ArgumentCountError), `/api/index/mass|infinity` — методов нет.
- Web-маршруты на несуществующие методы: `catalogs.show`, `terminateSession`, `deleteUserPhone`, `historyShow`.
- 2FA через API подключить нельзя (нет маршрута).
- GET-запросы (дашборд, history, stats) **переписывают статусы групп** и затирают `completed`/`canceled`.
- FLOOD_WAIT не обрабатывается: сообщение падает, отправка продолжается → риск PEER_FLOOD/бана аккаунта.
- Группа помечается `completed`, даже если всё упало.
- `exec(nohup …)` — процесс никто не контролирует; упал → сообщения навсегда `pending`.

**Дыры (на проде прямо сейчас):** RCE в `POST /tg/send`, SQL-инъекция без авторизации в `stats/top-users-by-status`,
`POST /api/test` без авторизации, повышение до суперадмина через `/api/users`, отправка с чужого телефона
(`single-messages`), IDOR на просмотре/отмене групп, `public/phpinfo.php` (скрипт Madeline), публичный
`public/MadelineProto.log`, пароль 2FA открытым текстом в таблице `jobs`, путь сессии из сырого `phone`
→ `File::deleteDirectory`, XSS через имя пользователя в `onclick`.

---

## Этап 0. Хотфикс безопасности (на текущем коде, до переписывания)

Отдельный коммит, выкатить на прод сразу. Ничего не ломает, схему не трогает.

1. Удалить маршрут `POST /tg/send` (`routes/Telegram/web.php:23`) и метод `TelegramController@sendMessage`.
2. Удалить `POST /api/test` (`routes/api.php`).
3. `stats/*` — внутрь `auth:sanctum` (+ проверка роли суперадмина); `status` — через `whereIn` по белому списку / биндинг.
4. `/api/users*` — `role:superadmin`.
5. `single-messages`: проверять, что `sender_phone_id` принадлежит пользователю; `show` — по владельцу.
6. Владелец на `show/cancel` групп (web + API) и `infinity-messages/{id}/cancel`.
7. `catalog_ids` при отправке — только свои/глобальные; web `catalogs/duplicate` — только суперадмин.
8. Валидация `phone` в `authRequest` (`regex:/^\+?\d{9,15}$/`) до построения пути.
9. На сервере: удалить `public/phpinfo.php`, `public/MadelineProto.log`, корневой `madeline.php`; проверить `APP_DEBUG=false`;
   сменить пароль `superadmin@example.com`, если сидер запускался на проде.
10. Login throttling: `throttle:5,1` на `/login` (web + API).
11. Репозиторий на GitHub — сделать приватным.

---

## Этап 1. Разведка на проде (только чтение, ~1 день)

Без этих данных не трогаем ни одной миграции.

1. **Бэкап**: `mysqldump --single-transaction --routines --triggers db > prod-YYYYMMDD.sql`, хранить вне сервера.
2. **Фактическая схема**: `php artisan schema:dump` с копии прода (или `SHOW CREATE TABLE` по всем таблицам).
   Сравнить с миграциями: код ссылается на `peers`, `bans`, `limits`, `departments`, `telegram_messages.batch_no`,
   `user_phones.is_active`, `users.department_id` — в миграциях их нет. Локальная sqlite содержит другую
   историю миграций → файлы переименовывались.
3. **Таблица `migrations` на проде** — выгрузить, сверить имена с `database/migrations`.
4. **Процессы**: `crontab -l`, конфиги supervisor/systemd — что реально запущено (`queue:work` с какими очередями?
   крутится ли где-то V5 или infinity?). Запускает ли кто-то вручную `telegram:logout`?
5. **Объёмы**: `COUNT(*)` по `telegram_messages`, `message_groups`, `chat_logs`; зависшие `pending` старше суток.
6. **Какие API используются** — включить логирование (см. ниже) и подождать 1–2 недели.

### Как узнать, какие API реально используются

Добавить middleware `LogApiUsage` на группу `api`: пишет в канал `api_usage` одну строку —
`route name/uri`, `method`, `user_id`, `ip`, `user-agent`, `status`. Через 1–2 недели:
`grep` по логу → список живых эндпоинтов и клиентов. После этого финально решаем, что удалить.
Плюс опросить того, кто владеет фронтендом/внешней системой (кто дёргает `stats/*` и `single-messages`?).

---

## Этап 2. Решения, которые нужны от тебя

| # | Вопрос | Моё предложение |
|---|---|---|
| 1 | «Бесконечные» сообщения — нужны? Сейчас не работают вообще. | Удалить (таблицу оставить до этапа «сузить»). Если нужны — делать заново как «повторяющаяся рассылка» поверх общего воркера. |
| 2 | Чат-логи (`fetch-messages`, `chat-logs`, ежедневный `start:fetching`) — кто пользуется? | Решить по логам API. Если нет — удалить вместе с таблицами (после бэкапа). |
| 3 | `single-messages` (1-к-1 по tg_id/телефону) — внешняя интеграция? | Оставить, но через общий отправщик и с проверкой владельца. |
| 4 | `stats/*` — для внешнего дашборда? | Оставить, закрыть токеном/ролью, переписать на нормальные запросы. |
| 5 | Excel-экспорт (код есть, пакет не установлен) | Удалить; при необходимости сделать CSV-стрим позже. |
| 6 | Язык интерфейса | `uz` по умолчанию + `ru`, тексты в `lang/`. `en` — по желанию. |
| 7 | API-контракт | Сохранить URL и форму ответов для core-набора (login, phones, catalogs, send, history, group) — клиенты не должны ничего менять. |
| 8 | Роли | Оставить `user` и `superadmin`. `admin`/`manager`/departments/bans — не делать, пока не понадобятся. |

---

## Этап 3. Фундамент

1. **Инструменты**: Pint (`pint.json`), Larastan уровень 5→8, PHPUnit на **MySQL** (Docker или отдельная БД),
   GitHub Actions: pint + phpstan + tests.
2. **Удалить мёртвый код** (список в разделе 0) — один коммит на группу (команды, сервисы, контроллеры,
   вьюхи, middleware). Проверка после каждого: `php artisan route:list`, `php artisan test`.
3. **Конфиг** `config/telegram.php`: `api_id`, `api_hash`, `device_model`, `sessions_path`,
   пауза между сообщениями (min/max), дневной лимит на аккаунт, флуд-джиттер, `php_binary`.
   Убрать `env()` из джобов и хардкод `/opt/php83/bin/php`.
4. **Enums** (`app/Enums`): `Role`, `TelegramAccountStatus`, `AuthSessionStatus`, `MessageGroupStatus`,
   `TelegramMessageStatus`, `SendErrorKey` (с методами `tone()`, `label()`, `isFinal()`).
   Колонки остаются `string` — БД не трогаем.
5. **Базовая схема**: `schema:dump` с прода → `database/schema/mysql-schema.sql`; старые миграции
   убрать **только** после сверки с таблицей `migrations` прода. Новые миграции идут после неё.
6. **Логирование**: каналы `telegram` (daily, 14 дней), `api_usage`; формат «короткое английское
   сообщение + context». Madeline-логгер — только в `storage/logs/madeline/`, уровень ERROR.
   Удалить 189 шумных `Log::` вызовов из мёртвого кода (уйдут вместе с ним), в живом — переписать.

---

## Этап 4. Ядро Telegram (`app/Telegram`)

Перенести из Postix паттерны (не файлы целиком):

| Класс | Зачем | Источник в Postix |
|---|---|---|
| `TelegramClient` (интерфейс) + `MadelineClient` | вся работа с Madeline за одним интерфейсом → тесты с фейком | идея из тестов Postix |
| `ClientFactory` | один `Settings/AppInfo`, один device model, логгер в storage | `OpensTelegramAccountSession` |
| `SessionStore` | путь сессии по нормализованному телефону, удаление только внутри `storage/app/telegram-sessions` | `TelegramAccount::sessionPathFor` |
| `ProcessLock` | flock: один процесс на сессию | `app/Telegram/TelegramProcessLock.php` |
| `NoticeShield` | notice из vendor не валит операцию | `Support/VendorNoticeShield.php` |
| `DeadSession` | коды мёртвой сессии → аккаунт `revoked`, никогда не бросает | `Support/DeadSession.php` |
| `ErrorMap` | RPC-ошибка → `SendErrorKey` (одно место вместо 4 копий) | `RPC_ERROR_KEYS` |

### Логин телефона

- Машина состояний на существующей таблице `telegram_auth_sessions` (статусы привести к enum):
  `pending → code_sent → verifying → [need_password → verifying] → success | code_invalid | password_invalid | failed`.
- `StartPhoneLogin` Action: транзакция + `lockForUpdate`, отказ если «в полёте» и не протух (180 с),
  `dispatch()->afterCommit()`.
- Пароль 2FA: `Crypt::encryptString` в кэш на 5 минут, в джобе только id. Джобы — `ShouldBeEncrypted` на всякий случай.
- Ошибки Telegram наружу не отдаём — только ключ ошибки → перевод.
- Один набор Actions для web и API; API получает недостающий шаг `POST /api/tg/password` и `GET /api/tg/status`.
- `UserPhone`: уникальность `(user_id, phone)` — сначала найти/слить дубли данными, потом индекс (миграция «расширить»).
- Logout и «удалить телефон» — реализовать (сейчас маршруты есть, методов нет).

---

## Этап 5. Отправка (главное улучшение)

Сейчас: один `nohup`-процесс на группу, `sleep(1)`, флуд = провал, нет блокировок, статус считается в GET.

**Станет:**

1. `CreateMassSend` Action (одна для web и API): Policy на телефон и каждый каталог → кулдаун каталогов
   (`CatalogTimerService` → `ReserveCatalogs`, **в той же транзакции**, что и создание группы) →
   группа + pivot + сообщения (bulk insert чанками по 1000) → коммит.
2. **Воркер на аккаунт** `telegram:send-worker {phoneId}` (долгоживущий, под supervisor):
   - берёт `ProcessLock` на сессию;
   - **захватывает** строки атомарно (`status pending → sending`, `claimed_at`) — повторная отправка невозможна;
   - пауза между сообщениями — случайная из конфига, дневной бюджет на аккаунт;
   - перед каждой отправкой перечитывает статус (отмена работает мгновенно);
   - FLOOD_WAIT → строка обратно в `pending`, у аккаунта `flood_until = now + N + джиттер`, воркер ждёт;
   - PEER_FLOOD → аккаунт отдыхает 24 ч, алерт; мёртвая сессия → `DeadSession`, оставшиеся строки
     `failed: session_revoked`;
   - после каждой строки пересчитывает статус группы: `sending → completed | partially_failed | failed | canceled`.
3. Супервизия: один `telegram:send-supervisor` (или supervisord-программа на шаблон), который держит по
   воркеру на аккаунт с ожидающими сообщениями; таймауты на всё. `nohup` и `exec` уходят полностью.
4. «Зависшие» строки (`sending` дольше 10 мин) — команда-уборщик по расписанию возвращает в `pending`.
5. Одиночные сообщения (`telegram_message_tasks`) — тот же воркер, отдельный тип задачи.

**Схема (только «расширить»):**

- `telegram_messages`: `+claimed_at` nullable, индексы `(message_group_id, status)`, `(status, send_at)`.
- `user_phones`: `+status`, `+flood_until`, `+last_error`, `+last_checked_at` (nullable), индекс `phone`.
- `message_groups`: индекс `status`; новые статусы — просто новые строковые значения.
- `catalog_message_group`: уникальность пары (после проверки дублей).
- Чистка позже («сузить», отдельный релиз): `telegram_messages.message_text`, `user_phones.session_delete_at`,
  `catalogs.user_phone_id`, `telegram_auth_sessions.code_used`, `password_resets`, `infinity_messages` (если решили удалить).

---

## Этап 6. HTTP-слой

- **Policies**: `UserPhonePolicy`, `CatalogPolicy`, `MessageGroupPolicy`, `UserPolicy` (+ `before()` для суперадмина).
- **FormRequests** на каждую запись; правила общие для web и API.
- **Actions / Queries** вместо логики в контроллерах (сейчас контроллеры по 400+ строк, логика скопирована в 3 места).
- **Resources** на каждый JSON-ответ; API-ответы core-набора — в **прежнем формате** (снимаем «золотые» JSON-фикстуры
  со старой версии и проверяем тестами).
- Роутинг: `routes/web.php` (страницы) + `routes/api.php` (`/api/...`, тот же префикс, что сейчас). Убрать двойной
  `/dashboard`, `use PeerController`, закомментированный хлам.
- GET никогда не пишет в БД.

### API: что с каждым эндпоинтом

| Эндпоинт | Решение |
|---|---|
| `POST /login` | оставить, + throttle, + `POST /logout` |
| `GET /phones`, `/tg/auth`, `/tg/code`, `/tg/logout/{phone}` | оставить; добавить `/tg/password`, `/tg/status` |
| `POST /tg/send` | оставить (core), через общий Action, с кулдауном и проверкой каталогов |
| `GET /history`, `GET /groups/{id}`, `POST /groups/{id}/cancel` | оставить (core), починить `/groups/{id}` |
| CRUD `/catalogs`, `/catalogs/duplicate` | оставить (core); добавить `is_global`, `timer_minutes` как в web |
| `/profile` | оставить |
| `/users*` | только суперадмин; удалить, если по логам не используется |
| `/tg/sendInf`, `/infinity-messages/{id}/cancel`, `/index/mass`, `/index/infinity` | удалить (не работают) — после логов |
| `/fetch-messages`, `/chat-logs` | по решению #2 |
| `/telegram/single-messages*` | по решению #3 |
| `stats/*` | по решению #4 |
| `/test` | удалить (этап 0) |

---

## Этап 7. Интерфейс

Сейчас: Bootstrap + Font Awesome с CDN, сотни строк inline CSS/JS на каждой странице, смесь узбекского/
английского/русского, `alert()`/`confirm()`, таблица статусов перерисовывается через `innerHTML` и ломает модалки,
Vite/Tailwind настроены, но не используются.

Станет (см. `.claude/skills/ui-console-design`):

- Tailwind v4 + Alpine через Vite, токены «Console» из Postix, компоненты `x-ui.*`.
- Один layout: тёмная верхняя панель, разделы: **Телефоны · Каталоги · Отправка · Статус · История · Пользователи**.
- Страницы:
  - **Телефоны** — свои (и все — для суперадмина), статус сессии, мастер подключения (таймер, «отправить код ещё раз», отмена),
    выход/удаление.
  - **Каталоги** — список с вкладками «Мои / Все», поиск, одна форма для создания и редактирования (сейчас две копии),
    импорт пиров списком, кнопка «копировать себе» / «раздать пользователям».
  - **Отправка** — выбор телефона (`?phone=` предвыбирает), каталоги с подсчётом уникальных пиров, предупреждение о кулдауне до отправки.
  - **Статус** — живые группы, прогресс, отмена; опрос JSON, не HTML.
  - **История / детали** — фильтры с сохранением в URL, причины ошибок переводом из `SendErrorKey`.
  - **Пользователи / Профиль** — как сейчас, без пустой `show`.
- i18n: `lang/uz`, `lang/ru`; переводы в Alpine через `@js(__('...'))`; `SetLocale` реально подключить.
- Тёмная тема, ширина 375 px.

---

## Этап 8. Тесты

- Feature-тесты на MySQL с **настоящими миграциями** (baseline + новые).
- Фейк `TelegramClient`: записывает вызовы, умеет бросать `FLOOD_WAIT_30`, `PEER_FLOOD`, `AUTH_KEY_UNREGISTERED`.
- Обязательные сценарии: доступ к чужим телефону/каталогу/группе → 403; кулдаун; отмена посреди отправки;
  флуд → пауза, а не провал; мёртвая сессия; повторный запуск воркера не шлёт дубли; формат API-ответов core-набора
  совпадает с фикстурами старой версии.

---

## Этап 9. Выкатка

1. Каждый этап — серия маленьких коммитов, выкатка этапами (не «большим взрывом»).
2. Перед каждой выкаткой с миграциями: бэкап, `migrate --pretend` на копии прода, остановка воркеров,
   `migrate`, `queue:restart`, смоук-тест (войти, открыть историю, отправить 1 сообщение на тестовый пир).
3. Переключение отправки: новый воркер включается флагом `config('telegram.sender') = v2`; старый путь
   (`nohup`) оставляем ещё один релиз как откат.
4. Через 1–2 недели стабильной работы — релиз «сузить»: удаление старых колонок/таблиц и флага.

---

## Порядок работы (коммиты)

1. `fix(security): ...` — этап 0 (сразу на прод).
2. `chore(api): log which endpoints are called` — middleware логов API.
3. Разведка на проде → дополнить этот файл фактической схемой.
4. `chore: remove dead send generations v2-v5` и остальные удаления мёртвого кода.
5. `chore: pint, larastan, mysql tests, ci`.
6. `feat(telegram): enums, config/telegram.php, client boundary`.
7. `feat(telegram): phone login state machine` (+ 2FA в API).
8. `feat(sending): per-account worker with claiming and flood handling` (под флагом).
9. `refactor(http): policies, requests, actions, resources` — web и API на общих Actions.
10. `feat(ui): console design, components, i18n` — страница за страницей.
11. Релиз «сузить».
