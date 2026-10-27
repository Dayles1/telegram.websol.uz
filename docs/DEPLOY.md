# Выкатка новой версии на прод

Новая версия работает на **той же базе**. Миграции только добавляют колонки и таблицы
(файлы `2026_10_27_*`; последний только переносит ждущие задачи в единую очередь), старые данные и старые сессии Telegram продолжают работать —
аккаунты перелогинивать не нужно.

Всё — вход в аккаунт, проверки, отправка, минутная проверка — идёт задачами одной очереди
`telegramWebsol`. На сервере нужна **одна** программа supervisord (`queue:work`, см.
`deploy/supervisor.conf`). Cron не нужен: минутную проверку ставит в очередь сам воркер.

## Сервер

- ISPmanager: проект в `/var/www/www-root/data/www/telegram.websol.uz`, владелец файлов и
  пользователь PHP — **`www-root`** (не `www-data`). PHP: `/opt/php83/bin/php`.
- `artisan` на сервере запускать от него: `sudo -u www-root php artisan ...`. Команда от root
  создаёт в `storage/` файлы (дневной лог), в которые воркер потом не может писать.
- Никогда `chown www-data` на `storage`: воркер падает с `Permission denied`, очередь стоит.
- Код заливается файлами (не `git pull`). Программа supervisor на сервере исторически называлась
  `websol-telegram-worker` (один процесс); нужна `telegram-websol` из `deploy/supervisor.conf`
  (4 процесса, `startsecs=0`), старую — убрать.

## 0. Один раз до выкатки

1. **Бэкап базы** (обязательно, хранить вне сервера):
   ```bash
   mysqldump --single-transaction --routines --triggers -u USER -p telegram_websol > backup-$(date +%F).sql
   ```
2. **Бэкап сессий**: `tar czf sessions-$(date +%F).tgz storage/app/sessions`.
3. **Проверить, что прод совпадает с файлами миграций**:
   ```bash
   php artisan migrate:status
   ```
   Все старые миграции должны быть `Ran`, новыми должны быть только файлы `2026_10_27_*`.
   Таблиц `attachments` и `send_loops` на проде быть не должно (их создаёт новая миграция).
   Если в списке есть что-то неожиданное (код старой версии ссылался на таблицы `peers`, `bans`,
   `departments`, которых нет в миграциях) — остановиться и разобраться до `migrate`.
4. **Порепетировать на копии**: залить бэкап в отдельную базу и выполнить
   `DB_DATABASE=copy php artisan migrate --pretend`, затем `migrate`, открыть панель.
5. Удалить с сервера опасные файлы старой версии (если ещё не удалены):
   `public/phpinfo.php`, `public/MadelineProto.log`, `MadelineProto.log`, `madeline.php`.
6. **Файлы до 50 МБ**: поднять лимиты загрузки, иначе большие файлы отклонит сервер, а не панель.
   - PHP (`php.ini` для FPM): `upload_max_filesize = 55M`, `post_max_size = 60M`, перезапустить php-fpm.
   - nginx: `client_max_body_size 60m;` в `server { }`, `nginx -s reload`.
   Файлы лежат в `storage/app/private/attachments` (не в `public/`) и удаляются сами,
   когда их больше нечему отправлять (через час после последней отправки).

## 1. Выкатка

```bash
cd /var/www/www-root/data/www/telegram.websol.uz
php artisan down --retry=60

# остановить ТОЛЬКО процессы этого проекта (на сервере есть чужие воркеры: не "stop all")
supervisorctl status | grep -i websol        # какие программы у этого проекта
supervisorctl stop <имя>:*                  # каждую программу websol из списка выше
pkill -f "/var/www/www-root/data/www/telegram.websol.uz/artisan telegram:" || true  # старые nohup-процессы отправки

git fetch && git checkout <релиз>            # или залить файлы
composer install --no-dev --optimize-autoloader
npm ci && npm run build                      # public/build не в git

# .env — ДО optimize (иначе в кэш попадут старые значения). Новые ключи из .env.example:
#   APP_DEBUG=false, APP_LOCALE=uz, DB_QUEUE_RETRY_AFTER=400,
#   DB_QUEUE=telegramWebsol (имя очереди задаётся только здесь), TELEGRAM_SEND_SLOTS=3
#   (TELEGRAM_QUEUE и TELEGRAM_SEND_QUEUE больше не читаются — удалить),
#   TELEGRAM_ALERT_BOT_TOKEN / TELEGRAM_ALERT_CHAT_ID, STATS_API_TOKEN

php artisan migrate --force      # только новые миграции 2026_10_27_*, все «expand»
php artisan storage:link         # аватары
php artisan optimize:clear && php artisan optimize

# заменить старый конфиг новым: в нём ОДНА программа telegram-websol
cp deploy/supervisor.conf /etc/supervisor/conf.d/telegram-websol.conf   # поправить пути, php и user
supervisorctl reread && supervisorctl update     # update удалит старые программы и добавит новую
supervisorctl start 'telegram-websol:*'
php artisan queue:restart

php artisan up
```

Cron для этого проекта не нужен. Если в `crontab -l` есть строка
`cd /var/www/www-root/data/www/telegram.websol.uz && php artisan schedule:run` — удалить её (`crontab -e`).

Очередь теперь одна и называется `telegramWebsol` (по имени проекта видно, чьи это воркеры). Задачи старой версии в очередях `telegram` / `default` новые воркеры не берут —
это классы, которых больше нет. Посмотреть: `SELECT queue, COUNT(*) FROM jobs GROUP BY queue;`,
при желании убрать после проверки: `php artisan queue:clear database --queue=telegram` (и `--queue=default`).

Что произойдёт с данными старой версии при первой минутной проверке (в течение минуты после старта воркера):
- сообщения `pending`, созданные больше 24 часов назад (зависшие у старого кода), **не будут
  отправлены**: они получат статус «ошибка» с причиной «не отправлено вовремя»
  (`config/telegram.php` → `worker.expire_pending_hours`);
- группы без оставшихся сообщений получат итоговый статус (`completed` / `failed`);
- свежие `pending` (младше 24 ч) будут поставлены в очередь `telegramWebsol` и отправлены.

## 2. Проверка после выкатки (5 минут)

- Мониторинг → Обзор: «Отправка» (последняя минутная проверка меньше 3 минут назад) и «Очередь» — зелёные.
- `supervisorctl status`: только `telegram-websol:telegram-websol_00..03` в `RUNNING`
  (раз в час каждый процесс перезапускается сам: `--max-time=3600`; процесс, который открывал
  сессию Telegram, завершается сразу после этой задачи — поэтому в конфиге нужен `startsecs=0`,
  иначе supervisor после частых перезапусков переведёт программу в `FATAL`).
- Команда воркера: `php artisan queue:work --queue=telegramWebsol --sleep=3 --tries=3 --timeout=370 --max-time=3600`.
- Аккаунты: после миграции `2026_10_27_000008` **все** телефоны в статусе «Отключён» (так задумано,
  см. ниже). Подключить заново один свой → «Подключён»; нажать «Проверить» — через пару секунд
  обновится «Проверен».
- Отправить 1 сообщение на свой тестовый username → в деталях рассылки статус «Доставлено».
- Отправить файл (PDF или фото) на тот же username, затем файл больше 10 МБ: оба доходят.
- API: `POST /api/tg/send` с `peer` вместо `catalog_ids` (см. `docs/postman/`).
- Настройки → «Отправить тестовый алерт» (если бот настроен).
- Внешний дашборд статистики: выдать ему `STATS_API_TOKEN` (заголовок `X-Stats-Token`) —
  без токена `/api/stats/*` теперь отвечают 403.

## Все номера → «Отключён» (миграция `2026_10_27_000008`, один раз)

По запросу от 2026-10-26: при `migrate` у **всех** телефонов статус в базе становится
`logged_out`, чтобы каждый аккаунт подключили заново из панели.

- Telegram не трогается: файлы сессий остаются, новый вход сам удаляет старый файл.
  В Telegram у этих аккаунтов старое «устройство» останется в активных сеансах.
- Пока аккаунт не подключён заново, с него ничего не отправляется; сообщения, которые ждали
  отправки, первая же минутная проверка пометит ошибкой `session_revoked` — они не уйдут
  и после повторного подключения (при необходимости: «Отправить неудачные заново»).
- Старые статусы сохраняются в таблицу `user_phones_status_backup`. Вернуть их:
  `php artisan migrate:rollback --step=1` (сразу после выкатки, пока это последняя миграция;
  аккаунты, подключённые заново, свой новый статус сохранят). Таблицу удалить отдельным
  релизом, когда откат больше не понадобится.
- До выкатки: `php artisan migrate --pretend` и бэкап базы (раздел 0).

## Переход с двух программ и cron на одну (если уже выкатывали версию с `telegramWebsol-sending`)

```bash
cd /var/www/www-root/data/www/telegram.websol.uz
supervisorctl stop 'telegram-websol-queue:*' 'telegram-websol-sending:*'
git pull                                   # или залить файлы
# .env: DB_QUEUE=telegramWebsol, убрать TELEGRAM_QUEUE и TELEGRAM_SEND_QUEUE, добавить TELEGRAM_SEND_SLOTS=3
php artisan migrate --pretend              # прочитать: 000007 (задачи очереди) и 000008 (все номера -> logged_out)
php artisan migrate --force                # 000007: ждущие задачи из telegramWebsol-sending -> telegramWebsol; 000008: см. выше
php artisan optimize:clear && php artisan optimize
cp deploy/supervisor.conf /etc/supervisor/conf.d/telegram-websol.conf   # поправить пути, php и user
supervisorctl reread && supervisorctl update     # старые две программы уйдут, новая запустится
crontab -e                                       # удалить строку schedule:run этого проекта
```

Проверить: `supervisorctl status | grep -i websol` — одна программа `telegram-websol`;
`SELECT queue, COUNT(*) FROM jobs GROUP BY queue;` — нет `telegramWebsol-sending`.

## Если сообщения стоят в `pending`

1. `php artisan queue:failed` — упала ли `MinuteTick` (минутная проверка); текст ошибки:
   `php artisan tinker --execute="echo DB::table('failed_jobs')->where('uuid','<uuid>')->value('exception');"`.
2. `supervisorctl status` — воркеры в `RUNNING`; после правки кода — `supervisorctl restart 'telegram-websol:*'`
   (воркер читает код один раз при старте).
3. Аккаунт отправителя: статус «Подключён», нет активной паузы (flood), не исчерпан дневной лимит.
4. Зависший unique-лок задачи аккаунта минутная проверка снимает сама, если задачи этого аккаунта
   нет в таблице `jobs`.
5. `RUNNING`, но `uptime` всё время несколько секунд: смотреть, как выходит процесс —
   `grep websol /var/log/supervisor/supervisord.log | tail`.
   `exit status 0; expected` — норма (процесс, открывавший сессию Telegram, выходит после задачи).
   `exit status 1; not expected` — падает: `supervisorctl tail -5000 <программа> stdout`.
   `Permission denied` на `storage/logs` или `storage/framework/telegram-locks` — у `storage` не тот
   владелец (2026-10-27 так встала вся очередь): `chown -R www-root:www-root storage bootstrap/cache`.
6. Сколько задач и почему ждут: `FetchChatHistory` после `chat-logs:refetch` стоят с задержкой
   по минуте на чат — это не зависание.
   ```bash
   sudo -u www-root php artisan tinker --execute='dump(DB::table("jobs")->selectRaw("queue, count(*) n, sum(available_at > unix_timestamp()) delayed, sum(reserved_at is not null) reserved")->groupBy("queue")->get());'
   ```

## 3. Откат

Новые миграции ничего не удаляют, поэтому старый код работает и на новой схеме
(кроме статусов телефонов: их возвращает `migrate:rollback --step=1`, см. выше).
Откат = вернуть старый релиз кода (`git checkout <старый>`), `composer install`, перезапустить
воркеры. `migrate:rollback` не нужен (и не нужно его делать без причины).

## 4. Через 2–4 недели

Мониторинг → API показывает, какие эндпоинты никто не вызывает. После этого можно удалять
лишнее API и (отдельным релизом, после бэкапа) старые неиспользуемые колонки/таблицы —
список в `docs/REWRITE_PLAN.md`, раздел «сузить».
