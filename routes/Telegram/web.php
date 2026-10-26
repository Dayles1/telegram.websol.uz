<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\MessageGroupController;



// Route::get('/telegram/send', [TelegramController::class, 'showSendForm'])->name('telegram.send-form');
Route::get('/telegram', [TelegramController::class, 'showPhoneForm'])->name('telegram.form');


Route::get('/telegram/send/messages/form', [TelegramController::class, 'showSendMessagesForm'])->name('telegram.send-messages-form');
Route::post('/telegram/send/messages', [TelegramController::class, 'sendMassMessage'])->name('telegram.send-messages');



Route::delete('/telegram/terminate', [TelegramController::class, 'terminateSession'])->name('telegram.terminate-session');
Route::delete('/telegram/delete-phone', [TelegramController::class, 'deleteUserPhone'])->name('telegram.delete-user-phone');



Route::post('/tg/send', [TelegramController::class, 'sendMessage']);


Route::post('/tg/auth', [TelegramController::class, 'authRequest'])->name('telegram.phone');
Route::post('/tg/code', [TelegramController::class, 'verifyCode'])->name('telegram.code');
Route::post('tg/password',[TelegramController::class, 'completeLogin'])->name('telegram.password');
Route::post('tg/status',[TelegramController::class, 'status'])->name('telegram.status');



    
Route::get('messages/data', [MessageGroupController::class, 'dashboardData'])->name('messages.data');
    Route::get('messages/stats', [MessageGroupController::class, 'dashboardStats'])->name('messages.stats');

Route::post('/message-groups/{group}/cancel', [MessageGroupController::class, 'cancelMassMessage'])
    ->name('message-groups.cancel');
    

Route::get('/messages/{messages}', [MessageGroupController::class, 'show'])
    ->name('messages.show');
Route::get('/message/history', [MessageGroupController::class, 'history'])
    ->name('messages.history');

Route::get('/message/history/{id}', [MessageGroupController::class, 'historyShow'])
    ->name('messages.history.show');

