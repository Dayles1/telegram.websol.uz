# Выкатка новой версии на прод

Новая версия работает на **той же базе**. Миграции только добавляют колонки и таблицы
(6 файлов `2026_10_27_*`), старые данные и старые сессии Telegram продолжают работать —
аккаунты перелогинивать не нужно.

Отправка идёт через стандартную очередь Laravel: своего «супервизора» больше нет.
На сервере нужны только supervisord (два `queue:work`, см. `deploy/supervisor.conf`) и cron.

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
   Все старые миграции должны быть `Ran`, новыми должны быть только 6 файлов `2026_10_27_*`.
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
cd /var/www/telegram.websol.uz
php artisan down --retry=60

# остановить ТОЛЬКО процессы этого проекта (на сервере есть чужие воркеры: не "stop all")
supervisorctl status | grep -i websol        # какие программы у этого проекта
supervisorctl stop telegram-websol-queue telegram-websol-sender   # имена из старого конфига
pkill -f "/var/www/telegram.websol.uz/artisan telegram:" || true  # старые nohup-процессы отправки

git fetch && git checkout <релиз>            # или залить файлы
composer install --no-dev --optimize-autoloader
npm ci && npm run build                      # public/build не в git

# .env — ДО optimize (иначе в кэш попадут старые значения). Новые ключи из .env.example:
#   APP_DEBUG=false, APP_LOCALE=uz, DB_QUEUE_RETRY_AFTER=400,
#   DB_QUEUE=telegramWebsol, TELEGRAM_QUEUE=telegramWebsol, TELEGRAM_SEND_QUEUE=telegramWebsol-sending
#   (если остался TELEGRAM_QUEUE=telegram — заменить, иначе ничего не отправится!),
#   TELEGRAM_ALERT_BOT_TOKEN / TELEGRAM_ALERT_CHAT_ID, STATS_API_TOKEN

php artisan migrate --force      # только 6 новых миграций, все «expand»
php artisan storage:link         # аватары
php artisan optimize:clear && php artisan optimize

# заменить старый конфиг (в нём была программа telegram:supervisor) новым
cp deploy/supervisor.conf /etc/supervisor/conf.d/telegram-websol.conf   # поправить пути, php и user
supervisorctl reread && supervisorctl update     # update трогает только изменённые программы
supervisorctl start 'telegram-websol-queue:*' 'telegram-websol-sending:*'
php artisan queue:restart

php artisan up
```

Cron (если ещё нет): `* * * * * cd /var/www/telegram.websol.uz && php artisan schedule:run >> /dev/null 2>&1`

Очереди теперь называются `telegramWebsol` и `telegramWebsol-sending` (по имени проекта видно,
чьи это воркеры). Задачи старой версии в очередях `telegram` / `default` новые воркеры не берут —
это классы, которых больше нет. Посмотреть: `SELECT queue, COUNT(*) FROM jobs GROUP BY queue;`,
при желании убрать после проверки: `php artisan queue:clear database --queue=telegram` (и `--queue=default`).

Что произойдёт с данными старой версии при первом `telegram:tick` (cron, в течение минуты):
- сообщения `pending`, созданные больше 24 часов назад (зависшие у старого кода), **не будут
  отправлены**: они получат статус «ошибка» с причиной «не отправлено вовремя»
  (`config/telegram.php` → `worker.expire_pending_hours`);
- группы без оставшихся сообщений получат итоговый статус (`completed` / `failed`);
- свежие `pending` (младше 24 ч) будут поставлены в очередь `telegramWebsol-sending` и отправлены.

## 2. Проверка после выкатки (5 минут)

- Мониторинг → Обзор: «Отправка» (последний `telegram:tick` меньше 3 минут назад) и «Очередь» — зелёные.
- `supervisorctl status`: `telegram-websol-queue_*` и `telegram-websol-sending_*` в `RUNNING`.
- Аккаунты: старые телефоны видны со статусом «Подключён». Нажать «Проверить» на одном —
  через пару секунд обновится «Проверен».
- Отправить 1 сообщение на свой тестовый username → в деталях рассылки статус «Доставлено».
- Отправить файл (PDF или фото) на тот же username, затем файл больше 10 МБ: оба доходят.
- API: `POST /api/tg/send` с `peer` вместо `catalog_ids` (см. `docs/postman/`).
- Настройки → «Отправить тестовый алерт» (если бот настроен).
- Внешний дашборд статистики: выдать ему `STATS_API_TOKEN` (заголовок `X-Stats-Token`) —
  без токена `/api/stats/*` теперь отвечают 403.

## 3. Откат

Новые миграции ничего не удаляют, поэтому старый код работает и на новой схеме.
Откат = вернуть старый релиз кода (`git checkout <старый>`), `composer install`, перезапустить
воркеры. `migrate:rollback` не нужен (и не нужно его делать без причины).

## 4. Через 2–4 недели

Мониторинг → API показывает, какие эндпоинты никто не вызывает. После этого можно удалять
лишнее API и (отдельным релизом, после бэкапа) старые неиспользуемые колонки/таблицы —
список в `docs/REWRITE_PLAN.md`, раздел «сузить».
