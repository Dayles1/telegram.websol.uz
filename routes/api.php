<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ChatLogController;
use App\Http\Controllers\Api\V1\GroupController;
use App\Http\Controllers\Api\V1\LoopController;
use App\Http\Controllers\Api\V1\MessageTaskController;
use App\Http\Controllers\Api\V1\PhoneController;
use App\Http\Controllers\Api\V1\SendController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
| The API keeps the URLs and response shapes of the old version, so existing
| clients keep working. Every call is counted (api_usage, see Monitoring -> API):
| endpoints nobody calls for months are the ones to delete.
*/

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);

    // Accounts
    Route::get('phones', [PhoneController::class, 'index']);
    Route::post('tg/auth', [PhoneController::class, 'auth'])->middleware('throttle:10,1');
    Route::post('tg/code', [PhoneController::class, 'code'])->middleware('throttle:10,1');
    Route::post('tg/password', [PhoneController::class, 'password'])->middleware('throttle:10,1');
    Route::get('tg/status', [PhoneController::class, 'status']);
    Route::post('tg/logout/{phone}', [PhoneController::class, 'logout']);

    // Sending
    Route::post('tg/send', [SendController::class, 'store'])->middleware('throttle:60,1');
    Route::post('tg/sendInf', [SendController::class, 'infinity']);
    Route::post('infinity-messages/{group}/cancel', [GroupController::class, 'cancel']);

    Route::get('history', [GroupController::class, 'history']);
    Route::get('index/mass', [GroupController::class, 'history']);
    Route::get('index/infinity', [GroupController::class, 'infinity']);
    Route::get('groups/{group}', [GroupController::class, 'show']);
    Route::post('groups/{group}/cancel', [GroupController::class, 'cancel']);

    // Repeating sends (POST tg/send with repeat_every_minutes)
    Route::get('loops', [LoopController::class, 'index']);
    Route::get('loops/{loop}', [LoopController::class, 'show']);
    Route::post('loops/{loop}/stop', [LoopController::class, 'stop']);

    Route::prefix('telegram/single-messages')->group(function () {
        Route::get('/', [MessageTaskController::class, 'index']);
        Route::post('/', [MessageTaskController::class, 'store'])->middleware('throttle:60,1');
        Route::get('{task}', [MessageTaskController::class, 'show']);
    });

    // Chat history
    Route::post('fetch-messages', [ChatLogController::class, 'fetch']);
    Route::get('chat-logs', [ChatLogController::class, 'index']);

    // Catalogs
    Route::post('catalogs/duplicate', [CatalogController::class, 'duplicate']);
    Route::apiResource('catalogs', CatalogController::class)->names('api.catalogs');

    // Users (superadmin) and profile
    Route::get('profile', [UserController::class, 'profile']);
    Route::get('users/roles', [UserController::class, 'roles']);
    Route::apiResource('users', UserController::class)->names('api.users');
});

// External dashboard: X-Stats-Token header or a superadmin token.
Route::middleware('stats')->prefix('stats')->group(function () {
    Route::get('top-users', [StatisticsController::class, 'topUsers']);
    Route::get('top-users-by-status', [StatisticsController::class, 'topUsersByStatus']);
    Route::get('dashboard-realtime', [StatisticsController::class, 'realtime']);
});
