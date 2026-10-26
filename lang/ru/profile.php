<?php

return [
    // backend messages
    'saved' => 'Профиль сохранён.',
    'token_deleted' => 'Токен отозван.',

    'title' => 'Профиль',
    'description' => 'Ваше имя, почта, пароль и токены для внешних систем.',
    'avatar_upload' => 'Загрузить фото',
    'avatar_delete' => 'Удалить',
    'avatar_hint' => 'JPG, PNG или WebP, до 2 МБ.',
    'account' => 'Учётная запись',
    'name' => 'Имя',
    'email' => 'Email',
    'password_title' => 'Смена пароля',
    'current_password' => 'Текущий пароль',
    'new_password' => 'Новый пароль',
    'confirm_password' => 'Повторите пароль',
    'password_hint' => 'Не короче 8 символов.',
    'change_password' => 'Сменить пароль',

    'tokens_title' => 'API-токены',
    'tokens_text' => 'Токен нужен внешним системам (CRM, сайт, скрипт), чтобы отправлять рассылки от вашего имени через API. Передавайте его в заголовке запроса. Каждой системе — свой токен: его можно отозвать, не трогая остальные.',
    'token_name' => 'Название',
    'token_name_placeholder' => 'Например: CRM или сайт',
    'token_create' => 'Создать токен',
    'token_created' => 'Токен создан',
    'token_once' => 'Скопируйте его сейчас: после перезагрузки страницы он больше не будет показан. Храните токен как пароль.',
    'token_last_used' => 'Последнее использование',
    'tokens_empty' => 'Токенов пока нет',
    'copy' => 'Скопировать',

    'js' => [
        'avatar_saved' => 'Фото обновлено',
        'avatar_deleted' => 'Фото удалено',
        'avatar_delete_title' => 'Удалить фото профиля?',
        'delete' => 'Удалить',
        'token_delete_title' => 'Отозвать токен?',
        'token_delete_body' => 'Системы, которые используют токен «:name», сразу потеряют доступ к API.',
        'token_delete' => 'Отозвать',
    ],
];
