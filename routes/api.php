<?php

use App\Http\Controllers\Api\External\StatisticsController;
use App\Http\Controllers\Api\TelegramMessageTaskController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ChatLogController;
use App\Http\Controllers\Api\V1\MessageGroupController;
use App\Http\Controllers\Api\V1\TelegramController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/tg/auth', [TelegramController::class, 'authRequest']);
    Route::post('/tg/code', [TelegramController::class, 'verifyCode']);
    Route::post('/tg/sendInf', [TelegramController::class, 'sendInfinityMessages']);
    Route::post('/infinity-messages/{id}/cancel', [TelegramController::class, 'cancelInfinityMessages']);
    Route::get('/phones', [TelegramController::class, 'index']);
    Route::post('/tg/send', [TelegramController::class, 'sendTelegramMessage']);
    Route::post('/tg/logout/{phone}', [TelegramController::class, 'logout']);

    Route::post('/fetch-messages', [ChatLogController::class, 'fetchMessages']);
    Route::get('/chat-logs', [ChatLogController::class, 'index']);

    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/roles', [UserController::class, 'roles']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);
    Route::get('/profile', [UserController::class, 'profile']);


    Route::get('/index/mass', [MessageGroupController::class, 'normal']);
    Route::get('/index/infinity', [MessageGroupController::class, 'infinity']);
    Route::get('/groups/{group}', [MessageGroupController::class, 'show']);
    Route::post('/groups/{group}/cancel', [MessageGroupController::class, 'cancelMassMessage']);
    Route::get('/history', [MessageGroupController::class, 'history']);



Route::prefix('telegram')->group(function () {
    Route::get('/single-messages', [TelegramMessageTaskController::class, 'index']);
    Route::post('/single-messages', [TelegramMessageTaskController::class, 'store']);
    Route::get('/single-messages/{id}', [TelegramMessageTaskController::class, 'show']);
});



    Route::get('/catalogs', [CatalogController::class, 'index']);
    Route::post('/catalogs', [CatalogController::class, 'store']);
    Route::get('/catalogs/{catalog}', [CatalogController::class, 'show']);
    Route::put('/catalogs/{catalog}', [CatalogController::class, 'update']);
    Route::patch('/catalogs/{catalog}', [CatalogController::class, 'update']);
    Route::delete('/catalogs/{catalog}', [CatalogController::class, 'destroy']);

    // Superadmin uchun
    Route::post('/catalogs/duplicate', [CatalogController::class, 'duplicate']);
});
Route::get('stats/top-users', [StatisticsController::class, 'usersStats']);
Route::get('stats/dashboard-realtime', [StatisticsController::class, 'dashboardRealtime']);
Route::get('stats/top-users-by-status', [StatisticsController::class, 'topUsersByTelegramStatus']);


































Route::post('/test', [TelegramController::class, 'test']);




// Route::post('/test', function (    
// ) {
//     $p=DB::table('user_phones')->where('id',32)->first();
//     // $p->user_id =;
//     dd($p);
// });