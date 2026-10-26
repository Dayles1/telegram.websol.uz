<?php

return [
    'title' => 'Yangi yuborish',
    'description' => 'Akkauntni, qabul qiluvchilar kataloglarini tanlang va matnni yozing. Har bir qabul qiluvchiga xabar bir marta boradi.',
    'history' => 'Tarix',
    'queued' => 'Yuborish navbatga qo‘yildi',

    'step_account' => 'Akkaunt',
    'step_catalogs' => 'Kataloglar',
    'step_message' => 'Xabar',
    'step_repeat' => 'Takrorlash',

    // accounts
    'no_phones_title' => 'Ulangan akkaunt yo‘q',
    'no_phones_text' => 'Xabar yuborish uchun Telegram akkauntini ulang.',
    'connect_phone' => 'Akkaunt ulash',
    'not_ready' => 'Yuborishga tayyor emas',
    'paused_until' => 'Telegram pauzasi tugashi',
    'sent_today' => 'Bugun',
    'mine' => 'Meniki',

    // catalogs
    'no_catalogs_title' => 'Hali katalog yo‘q',
    'no_catalogs_text' => 'Katalog — qabul qiluvchilar ro‘yxati: username, t.me havolalari yoki id.',
    'new_catalog' => 'Yangi katalog',
    'search_catalogs' => 'Nomi yoki egasi bo‘yicha qidirish',
    'select_all' => 'Hammasini tanlash',
    'select_none' => 'Tanlovni bekor qilish',
    'selected' => 'Tanlandi: :n',
    'peers' => 'ta',
    'global' => 'Umumiy',
    'timer' => 'Taymer :time',
    'cooldown' => ':time dan keyin',
    'no_catalogs_found' => 'Hech narsa topilmadi',

    // message
    'message_label' => 'Xabar matni',
    'message_placeholder' => 'Assalomu alaykum! …',
    'html_hint' => 'HTML belgilash ishlaydi:',
    'too_long' => 'Matn :max belgidan uzun',

    // file
    'file_drop' => 'Fayl biriktiring: shu yerga tashlang yoki bosib tanlang',
    'file_limits' => 'Istalgan fayl, :max gacha. JPG, PNG va WebP rasmlar (10 MB gacha) rasm bo‘lib, qolganlari hujjat bo‘lib boradi.',
    'file_remove' => 'Faylni olib tashlash',
    'file_too_big' => 'Fayl juda katta: eng ko‘pi :max.',
    'file_as_photo' => 'rasm bo‘lib boradi',
    'file_as_document' => 'hujjat bo‘lib boradi',
    'caption_hint' => 'Fayl bilan matnning :max belgigacha bo‘lgani uning izohi bo‘ladi; uzunroq matn fayldan keyin alohida xabar bo‘lib boradi.',
    'caption_split' => 'Matn :max belgidan uzun: u fayldan keyin alohida xabar bo‘lib boradi.',
    'message_optional' => 'Fayl biriktirilgan bo‘lsa, matn ixtiyoriy.',

    // repeat
    'repeat_toggle' => 'Takroriy yuborish',
    'repeat_hint' => 'Har bir raund — oddiy yuborish. Keyingi raund oldingisi tugaganidan keyin shu vaqt o‘tib boshlanadi. Raundni to‘xtatish takrorlashni ham to‘xtatadi.',
    'repeat_every' => 'Har',
    'repeat_unit' => 'O‘lchov birligi',
    'repeat_units' => ['min' => 'daqiqa', 'hour' => 'soat', 'day' => 'kun'],
    'repeat_rounds' => 'Raundlar soni',
    'repeat_rounds_placeholder' => 'to‘xtatilguncha',
    'repeat_rounds_hint' => 'Bo‘sh qoldirilsa — qo‘lda to‘xtatilguncha takrorlanadi.',
    'repeat_invalid' => 'Takrorlash oralig‘i 1 daqiqadan :max gacha bo‘lishi kerak',
    'repeat_rounds_invalid' => 'Raundlar soni 1 dan :max gacha bo‘lishi kerak',

    // summary
    'summary' => 'Xulosa',
    'account' => 'Akkaunt',
    'not_chosen' => 'tanlanmagan',
    'catalogs_count' => 'Kataloglar',
    'file' => 'Fayl',
    'repeat' => 'Takrorlash',
    'every' => 'har :time',
    'rounds' => ':n raund',
    'until_stopped' => 'to‘xtatilguncha',
    'unique_peers' => 'Qabul qiluvchilar',
    'duplicates' => 'Takrorlar olib tashlandi',
    'today_usage' => 'Bugun ishlatilgan',
    'no_limit' => 'limitsiz',
    'over_limit' => 'Yuborish kunlik limitdan oshadi: bugun yana :n ta yuborish mumkin.',
    'limit_reached' => 'Akkauntning kunlik limiti tugagan.',
    'cooldown_blocked' => 'Ba’zi kataloglar bu akkaunt uchun hali taymerda: ularni olib tashlang yoki kuting.',
    'preview' => 'Ko‘rinishi',
    'preview_empty' => 'Xabaringiz shu yerda ko‘rinadi',
    'send' => 'Yuborish',
    'sending' => 'Yuborilmoqda…',
    'uploading' => 'Fayl yuklanmoqda… :p%',

    // why the button is disabled
    'need_phone' => 'Akkauntni tanlang',
    'need_catalogs' => 'Kamida bitta katalog tanlang',
    'need_message' => 'Matn yozing yoki fayl biriktiring',
    'need_peers' => 'Tanlangan kataloglarda qabul qiluvchi yo‘q',

    'confirm_title' => 'Yuborish boshlansinmi?',
    'confirm_body' => ':account akkauntidan :n ta qabul qiluvchiga.',
    'confirm_title_repeat' => 'Takroriy yuborish boshlansinmi?',
    'confirm_file' => 'Fayl: :name (:size).',
    'confirm_repeat' => 'Har :every takrorlanadi, to‘xtatilguncha.',
    'confirm_repeat_rounds' => 'Har :every takrorlanadi, jami :rounds raund.',
    'confirm_action' => 'Yuborish',
    'minutes' => ':n daq',
];
