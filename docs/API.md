# telegram.websol.uz API

Telegram'ga foydalanuvchining **o'z akkauntidan** (bot emas) xabar va fayl yuborish uchun API.
Postman kolleksiyasi: [`docs/postman/telegram-websol.postman_collection.json`](postman/telegram-websol.postman_collection.json)
va muhit (environment): [`docs/postman/telegram-websol.postman_environment.json`](postman/telegram-websol.postman_environment.json).
Postman → Import → ikkala faylni tanlang, muhitda `base_url`, `email`, `password` ni to'ldiring.

## Umumiy qoidalar

| | |
|---|---|
| Base URL | `https://telegram.websol.uz/api` |
| Avtorizatsiya | `Authorization: Bearer <token>` (token `POST /api/login` dan olinadi) |
| Format | `Accept: application/json`; so'rov tanasi JSON, fayl yuborilganda `multipart/form-data` |
| Til | `Accept-Language: uz` (standart) yoki `ru`; yoki `?lang=ru`. Xato matnlari shu tilda |
| Vaqt | `Asia/Tashkent`, `YYYY-MM-DD HH:MM:SS` (ba'zi eski maydonlar `DD.MM.YYYY HH:MM` yoki ISO 8601 UTC) |

**Yuborish asinxron.** `POST /api/tg/send` darhol `group_id` qaytaradi, xabarlar esa fonda,
navbat orqali ketadi. Natijani `GET /api/groups/{group_id}` bilan kuzating (har 3–10 soniyada).
Akkaunt xabarlar orasida pauza qiladi (standart 2–5 soniya) va kunlik limitga ega (standart 1000),
shuning uchun 1000 ta qabul qiluvchiga yuborish bir necha soat davom etishi mumkin.
24 soat ichida yuborilmagan xabar `failed` (`error_key: expired`) bo'ladi.

### Limitlar (so'rovlar soni)

| Endpoint | Limit |
|---|---|
| `POST /login` | 5/daqiqa (email+IP), 20/daqiqa (IP) |
| `POST /tg/auth`, `/tg/code`, `/tg/password` | 10/daqiqa |
| `POST /tg/send` | 60/daqiqa |
| `POST /telegram/single-messages` | 60/daqiqa |
| Qolganlari | 120/daqiqa (foydalanuvchiga) |

Limitdan oshsa: `429 Too Many Attempts.` va `Retry-After` sarlavhasi.

### Xatolar

Barcha xatolar bir xil ko'rinishda:

```json
{ "status": "error", "message": "Not found." }
{ "status": "validation_error", "message": "...", "errors": { "peer": ["..."] } }
{ "status": "error", "success": false, "reason": "catalog_cooldown", "message": "Katalog hali taymerda: Mijozlar (30 daqiqa).", "details": { "catalogs": "Mijozlar (30 daqiqa)" } }
```

| HTTP | Qachon |
|---|---|
| 401 | token yo'q yoki noto'g'ri |
| 403 | boshqa foydalanuvchining akkaunti/katalogi/yuborishi (`reason: catalog_not_allowed`) |
| 404 | topilmadi |
| 409 | amalni hozir bajarib bo'lmaydi (`reason` ga qarang) |
| 410 | eskirgan endpoint |
| 422 | validatsiya (`status: validation_error`) yoki `reason`: `phone_not_ready`, `no_peers`, `nothing_to_send`, `attachment_removed` |
| 429 | so'rovlar limiti yoki katalog taymeri (`reason: catalog_cooldown`) |

`reason` kodlari (o'zgarmaydi, kodda shularga tayaning): `phone_invalid`, `phone_taken`, `already_authorized`,
`login_in_progress`, `code_not_expected`, `password_not_expected`, `not_authorized`, `logout_first`,
`has_history`, `phone_not_ready`, `catalog_not_allowed`, `no_peers`, `catalog_cooldown`,
`nothing_to_retry`, `nothing_to_send`, `attachment_removed`.

## 1. Kirish

### `POST /api/login`
`{ "email": "...", "password": "..." }` → `{ "user": {...}, "token": "1|abc..." }`. Noto'g'ri bo'lsa `401`.
Token muddatsiz; `POST /api/logout` faqat shu tokenni o'chiradi. `GET /api/profile` — joriy foydalanuvchi.

## 2. Telegram akkauntlar

`GET /api/phones` — akkauntlar ro'yxati (`data.my_phones.items[]`: `id`, `phone`, `status`, `session_exists`).
Yuborish uchun `status: "authorized"` bo'lgan akkaunt kerak.

### Akkauntni ulash (telefon → kod → 2FA)

1. `POST /api/tg/auth` `{ "phone": "+998901234567" }` → `{ "status": "sms_sent", "phone_id": 5, ... }`.
   Kod fonda so'raladi.
2. `GET /api/tg/status?phone=+998901234567` ni har 2 soniyada so'rang. `awaits`:
   - `"code"` → kodni yuboring: `POST /api/tg/code` `{ "phone": "...", "code": "12345" }`;
   - `"password"` → 2FA: `POST /api/tg/password` `{ "phone": "...", "password": "..." }` (`password_hint` — eslatma);
   - `null` va `status: "authorized"` → tayyor; `status: "failed"`/`"code_invalid"`/`"password_invalid"` →
     `error` / `error_text` da sabab (`code_invalid` da kodni qayta yuborish mumkin).

`status` qiymatlari: `requesting_code`, `code_sent`, `verifying`, `need_password`, `code_invalid`,
`password_invalid`, `failed`, `authorized`, `revoked` (Telegram sessiyani yopgan — qayta ulash kerak),
`logging_out`, `logged_out`.

`POST /api/tg/logout/{phone_id}` — akkauntdan chiqish (fonda).

## 3. Xabar yuborish — `POST /api/tg/send`

| Maydon | Turi | |
|---|---|---|
| `phone_id` **yoki** `phone` **yoki** `telegram_user_id` | int / string | qaysi akkauntdan (o'zingizniki) |
| `peer` **yoki** `catalog_ids[]` | string / int[] | **faqat bittasi**: bitta qabul qiluvchi yoki kataloglar |
| `message` | string, ≤ 4096 | HTML matn. `file` bo'lmasa majburiy |
| `file` | fayl, ≤ 50 MB | ixtiyoriy, `multipart/form-data` |
| `repeat_every_minutes` | int 1…43200 | ixtiyoriy: takroriy yuborish (pastga qarang) |
| `max_rounds` | int ≥ 1 | ixtiyoriy: nechta aylanadan keyin to'xtash (bo'lmasa — to'xtatilguncha) |

**`peer` formatlari:** `@username`, `username`, `https://t.me/username`, `+998901234567` (telefon raqami —
`+` bilan; raqam egasi topilmasa yoki yashirgan bo'lsa `error_key: phone_not_found`), guruh/kanal ID
(`-100…`), `https://t.me/+invite`.

**Matn** — Telegram HTML: `<b>`, `<i>`, `<u>`, `<s>`, `<code>`, `<pre>`, `<a href="...">`, `<tg-spoiler>`.

**Fayl:** jpeg/png/webp rasm (≤ 10 MB) rasm sifatida, qolgan hamma narsa hujjat (fayl) sifatida ketadi.
Matn fayl ostidagi izoh bo'ladi; matn 1024 belgidan uzun bo'lsa — fayldan keyin alohida xabar bo'lib ketadi.
Fayl serverda faqat kerak bo'lguncha saqlanadi: yuborish tugagach (va takroriy yuborish to'xtagach)
1 soatdan keyin o'chiriladi.

Javob (`200`):
```json
{ "status": "success", "group_id": 12, "peers_count": 1 }
{ "status": "success", "group_id": 13, "loop_id": 4, "peers_count": 1 }   // takroriy bo'lsa
```

Akkaunt topilmasa / sizniki bo'lmasa / ulanmagan bo'lsa: `422 {"status":"error","message":"Phone not found or not yours."}`.

### Takroriy yuborish

`repeat_every_minutes: 60` — har bir aylana (round) **tugaganidan** 60 daqiqa o'tib keyingisi boshlanadi.
Har bir aylana oddiy yuborish (o'z `group_id` si bilan). Kataloglar har aylanada qayta o'qiladi (katalogga
qo'shilgan qabul qiluvchilar keyingi aylanaga tushadi).

- `GET /api/loops?status=active|stopped|finished` — ro'yxat;
- `GET /api/loops/{loop_id}` — holat va oxirgi 20 aylana (`rounds[]`);
- `POST /api/loops/{loop_id}/stop` — to'xtatish (ketayotgan aylana bekor qilinadi).

Aylanani `POST /api/groups/{id}/cancel` bilan bekor qilish ham takrorlashni to'xtatadi.
`status`: `active`, `stopped` (`stop_reason`: `canceled`, `phone_not_ready`, `attachment_removed`, `no_peers`),
`finished` (`max_rounds` ga yetdi).

## 4. Natijani kuzatish

### `GET /api/groups/{group_id}`
`data.group.status`: `pending` → `sending` → `completed` (kamida bitta yetkazildi) | `failed` (birortasi ham) |
`canceled`. `data.overview`: `total_messages`, `sent`, `pending`, `failed`, `canceled`.
`data.peers[].messages[]`: har bir qabul qiluvchi bo'yicha `status` (`pending`, `sending`, `sent`, `failed`,
`canceled`), `error_key`, `sent_at`, `telegram_message_id`. `data.group.attachment` — fayl
(`original_name`, `size`, `kind`, `removed_at`), `data.group.loop` — takroriy yuborish.

`POST /api/groups/{group_id}/cancel` — navbatdagilarni bekor qilish (ketayotgan bitta xabar tugaydi).

`GET /api/history?search=&page=` — yuborishlar tarixi (15 tadan), eski format saqlangan.

### `error_key` qiymatlari (xabar nega ketmadi)

| Kalit | Ma'nosi | Qayta urinish |
|---|---|---|
| `peer_not_found` | username/havola mavjud emas | yo'q |
| `phone_not_found` | bu raqamda Telegram yo'q yoki raqam yashirin | yo'q |
| `privacy_restricted` | qabul qiluvchi maxfiylik sozlamalari bilan yopgan | yo'q |
| `user_blocked` | qabul qiluvchi akkauntni bloklagan | yo'q |
| `chat_write_forbidden`, `chat_private`, `chat_guest_send_forbidden`, `user_banned` | chatga yozib bo'lmaydi | yo'q |
| `peer_is_bot`, `peer_deactivated` | bot / o'chirilgan akkaunt | yo'q |
| `message_too_long`, `message_empty`, `message_markup_invalid` | matndagi muammo | matnni tuzating |
| `attachment_invalid`, `attachment_missing` | fayl qabul qilinmadi / serverda yo'q | yangi yuborish |
| `session_revoked`, `session_missing`, `session_logged_out`, `account_banned` | akkaunt sessiyasi yopilgan | akkauntni qayta ulang |
| `telegram_internal`, `network_error`, `worker_lost` | vaqtinchalik xato (3 urinishdan keyin) | ha |
| `expired` | 24 soat ichida yuborilmadi (pauza, limit) | ha |
| `unknown_error` | boshqa | — |

Akkaunt Telegram'dan `FLOOD_WAIT` yoki `PEER_FLOOD` olsa, xabarlar yo'qolmaydi: ular `pending` bo'lib qoladi,
akkaunt pauzaga tushadi va keyin o'zi davom etadi. (`flood_wait_N` kabi kalitlar faqat eski yozuvlarda uchraydi.)

## 5. Kataloglar (qabul qiluvchilar ro'yxati)

- `GET /api/catalogs?tab=my|all&search=&per_page=` — `data.my_catalogs` / `data.all_catalogs` (sahifalangan);
- `POST /api/catalogs` `{ "title": "Mijozlar", "peers": ["@alice", "t.me/bob", "+998901234567"], "timer_minutes": 30 }`
  (yoki `peers_text`: har qatorda bitta). `timer_minutes` — bitta akkauntdan shu katalogga necha daqiqada bir marta
  yuborish mumkin (`0`/`null` — cheklovsiz; band bo'lsa `429 catalog_cooldown`);
- `GET|PUT|DELETE /api/catalogs/{id}`; `POST /api/catalogs/duplicate` `{catalog_id, user_ids[]}` (faqat superadmin).

## 6. Yakka xabar (Telegram ID yoki telefon bo'yicha)

`POST /api/telegram/single-messages` `{ "sender_phone_id": 5, "target_tg_id": "123456789" | "target_phone": "+998...", "message": "..." }`
→ `202 { "task_id": 1, "status": "pending" }`. Holat: `GET /api/telegram/single-messages/{task_id}`
(`pending`, `processing`, `sent`, `failed` + `error_code`), ro'yxat: `GET /api/telegram/single-messages?status=&sender_phone_id=&search=&date_from=&date_to=&per_page=`.
Yangi integratsiyalar uchun `POST /api/tg/send` + `peer` qulayroq (fayl va takrorlash ham bor).

## 7. Boshqalar

- Chat tarixi: `POST /api/fetch-messages` `{phone_id, peer}` (fonda yuklaydi) → `GET /api/chat-logs?phone_id=&peer=`.
- Foydalanuvchilar (faqat superadmin): `GET|POST /api/users`, `GET|PUT|DELETE /api/users/{id}`, `GET /api/users/roles`.
- Statistika (tashqi dashboard, `X-Stats-Token: <STATS_API_TOKEN>` sarlavhasi bilan, Bearer kerak emas):
  `GET /api/stats/top-users?period=day|week|month|year|all&type=top|bottom|both&limit=`,
  `GET /api/stats/top-users-by-status?status=sent|failed|pending|canceled&limit=&period=`,
  `GET /api/stats/dashboard-realtime?per_page=`.

### Eskirgan

`POST /api/tg/sendInf` → `410` (o'rniga `POST /api/tg/send` + `repeat_every_minutes`);
`GET /api/index/infinity` doim bo'sh; `GET /api/index/mass` = `GET /api/history`.
