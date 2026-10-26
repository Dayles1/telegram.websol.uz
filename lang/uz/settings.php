<?php

return [
    'title' => 'Sozlamalar',
    'description' => 'Yuborish tezligi, limitlar, fludga munosabat va ogohlantirishlar. Deploysiz o‘zgartiriladi.',
    'fix_errors' => 'Sozlamalar saqlanmadi: quyida belgilangan maydonlarni tuzating.',
    'saved' => 'Sozlamalar saqlandi',
    'applies_within' => 'Workerlar o‘zgarishlarni bir daqiqa ichida qabul qiladi.',

    'groups' => [
        'pacing' => [
            'title' => 'Yuborish tezligi',
            'text' => 'Bitta akkauntning ikki xabari orasidagi pauza shu oraliqda tasodifiy tanlanadi. Qanchalik sekin bo‘lsa, Telegram akkauntni shunchalik kam cheklaydi.',
        ],
        'limits' => [
            'title' => 'Limitlar',
            'text' => 'Bitta akkaunt bir kunda qancha yubora oladi.',
        ],
        'flood' => [
            'title' => 'Flud cheklovlari',
            'text' => 'Telegram kutishni so‘raganda (FLOOD_WAIT) yoki akkauntni spam uchun cheklaganda (PEER_FLOOD) nima qilish kerak. Bunda xabarlar yo‘qolmaydi: ular navbatda kutadi.',
        ],
        'alerts' => [
            'title' => 'Ogohlantirishlar',
            'text' => 'Muhim muammolar bot orqali Telegram chatga yuboriladi — nosozlikdan panelga keyingi kirishda emas, darhol xabar topasiz.',
        ],
    ],

    'fields' => [
        'send_delay_min' => [
            'label' => 'Pauza (dan)',
            'hint' => 'Bitta akkaunt xabarlari orasidagi eng kichik pauza.',
        ],
        'send_delay_max' => [
            'label' => 'Pauza (gacha)',
            'hint' => 'Eng katta pauza. Eng kichigidan kam bo‘lmasin.',
        ],
        'daily_limit_per_phone' => [
            'label' => 'Akkauntning kunlik limiti',
            'hint' => 'Bir akkauntdan bir kunda yuboriladigan xabarlar. Qolgani ertangi kunni kutadi. 0 — limitsiz.',
        ],
        'flood_wait_inline_max' => [
            'label' => 'Qisqa FLOOD_WAIT ni joyida kutish',
            'hint' => 'Shu uzunlikkacha kutishni worker o‘zi kutib turadi; uzunrog‘i akkauntni pauzaga qo‘yadi va slotni bo‘shatadi.',
        ],
        'flood_wait_jitter' => [
            'label' => 'FLOOD_WAIT ga zaxira',
            'hint' => 'Telegram so‘raganidan ortiq tasodifiy soniyalar — cheklov olinadigan aynan o‘sha paytda qaytmaslik uchun.',
        ],
        'peer_flood_rest_hours' => [
            'label' => 'PEER_FLOOD dan keyin dam olish',
            'hint' => 'Telegram akkauntni spam uchun cheklaganda u qancha vaqtga to‘xtatiladi.',
        ],
        'alerts_enabled' => [
            'label' => 'Ogohlantirishlarni yuborish',
            'hint' => 'Faqat bot .env da sozlangan bo‘lsa ishlaydi (quyidagi yo‘riqnomaga qarang).',
        ],
        'alert_min_level' => [
            'label' => 'Eng past daraja',
            'hint' => 'Bu darajadan past hodisalar botga yuborilmaydi.',
        ],
        'alert_repeat_minutes' => [
            'label' => 'Takrorlash oralig‘i',
            'hint' => 'Hal qilinmagan bir xil muammo botga faqat shu vaqtdan keyin yana keladi.',
        ],
    ],

    'units' => [
        'seconds' => 'son',
        'minutes' => 'daq',
        'hours' => 'soat',
        'messages' => 'xabar',
    ],

    'parallel_hint' => 'Bir vaqtda :slots tagacha akkaunt yuboradi (.env dagi TELEGRAM_SEND_SLOTS). Serverdagi navbat workerida kamida :processes ta jarayon bo‘lishi kerak (deploy/supervisor.conf dagi numprocs), shunda akkauntga kirish yuborishlarni kutib qolmaydi.',

    'bot_configured' => 'Bot sozlangan',
    'bot_missing' => 'Bot sozlanmagan',
    'test_alert' => 'Sinov ogohlantirishini yuborish',
    'test_alert_text' => 'Sinov ogohlantirishi. Yuborgan: :name. Buni ko‘rib turgan bo‘lsangiz — ogohlantirishlar ishlayapti.',
    'test_alert_sent' => 'Sinov ogohlantirishi yuborildi, chatni tekshiring',
    'test_alert_failed' => 'Yuborib bo‘lmadi: bot tokeni va chat id ni, bot chatga qo‘shilganini tekshiring',

    'guide' => [
        'title' => 'Ogohlantirish botini qanday ulash',
        'not_configured' => 'Bot hali sozlanmagan: ogohlantirishlar yuborilmayapti. Bu bir-ikki daqiqa oladi.',
        'bot' => [
            'title' => 'Bot yarating',
            'text' => 'Telegramda @BotFather ni oching, /newbot yuboring, nom bering. BotFather 123456789:AAH… ko‘rinishidagi token yuboradi — bu TELEGRAM_ALERT_BOT_TOKEN.',
        ],
        'start' => [
            'title' => 'Botga yozing',
            'text' => 'Botingizni oching va «Start» ni bosing (yoki istalgan xabar yuboring). Jamoa chati uchun — botni guruhga qo‘shing va u yerda biror narsa yozing.',
        ],
        'chat_id' => [
            'title' => 'Chat id ni bilib oling',
            'text' => 'Brauzerda o‘z tokeningizni qo‘yib, quyidagi manzilni oching:',
            'where' => 'Javobda :path ni toping — bu TELEGRAM_ALERT_CHAT_ID (guruhlarda u manfiy, -100 bilan boshlanadi).',
        ],
        'env' => [
            'title' => 'Serverdagi .env ga yozing',
            'text' => 'Ikkala qatorni loyihaning .env fayliga:',
        ],
        'apply' => [
            'title' => 'Qo‘llang va tekshiring',
            'text' => 'Konfiguratsiya keshini tozalang (agar keshlangan bo‘lsa — config:cache ni qayta bajaring), navbat workerlarini qayta ishga tushiring va «Sinov ogohlantirishini yuborish» ni bosing.',
        ],
    ],

    'maintenance' => [
        'title' => 'Texnik xizmat',
        'text' => 'Minutlik tekshiruvni navbat workeri har daqiqada ishga tushiradi; tugma uni darhol bajaradi. Istalgan paytda ishga tushirish xavfsiz.',
        'items' => [
            'yuborishda qotib qolgan xabarlar navbatga qaytariladi (yoki urinishlar limitidan keyin xato deb belgilanadi);',
            'endi yubora olmaydigan akkauntlarning xabarlari xato deb belgilanadi;',
            'xabari qolmagan yuborishlar yakuniy holatini oladi;',
            'vaqti kelgan takroriy yuborish raundlari boshlanadi;',
            'yuboradigan xabari bor akkauntlar uchun yuborish vazifasi navbatga qo‘yiladi;',
            'endi hech kim yubormaydigan fayllar serverdan o‘chiriladi.',
        ],
        'run' => 'Minutlik tekshiruvni hozir bajarish',
        'running' => 'Bajarilmoqda…',
    ],
    'sweep_done' => 'Minutlik tekshiruv bajarildi',

    'js' => [
        'delay_order' => '«Gacha» pauza «dan» pauzadan kam bo‘lmasligi kerak.',
        'no_delay' => 'Pauzasiz akkaunt Telegram ruxsat bergancha tez yuboradi — cheklovlar xavfi yuqori.',
        'per_hour' => 'Bunday tezlikda bitta akkaunt soatiga ~:n xabar yuboradi.',
        'limit_hours' => 'Akkaunt kunlik limitni taxminan :h soatda tugatadi.',
    ],
];
