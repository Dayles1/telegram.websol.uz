<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PeerController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PhoneController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\MessageGroupController;


require __DIR__ . '/Login/web.php';

Route::middleware('auth')->group(function () {

    require __DIR__ . '/Telegram/web.php';

    Route::get('/dashboard', [PhoneController::class, 'index'])->name('dashboard');
    Route::resource('catalogs', CatalogController::class);

    Route::get('message-groups/dashboard', [MessageGroupController::class, 'dashboard'])
        ->name('message-groups.dashboard');

    Route::middleware(['role:superadmin'])->group(function () {
        Route::resource('users', UserController::class);
    });

    Route::post('catalogs/duplicate', [CatalogController::class, 'duplicate'])->name('catalogs.duplicate');

    Route::get('profile', [UserController::class, 'profile'])->name('profile');
    Route::put('/profile/update', [UserController::class, 'profileUpdate'])->name('profile.update');
 Route::post('profile/avatar/from-telegram', [UserController::class, 'fetchAvatarFromTelegram'])->name('profile.avatar.from_telegram');
    Route::post('/messages/remove-peer', [CatalogController::class, 'removePeerFromCatalog'])
        ->name('catalogs.removePeer');

    Route::post('profile/avatar', [UserController::class, 'avatarUpdate'])->name('profile.avatar.update');
    Route::delete('profile/avatar', [UserController::class, 'avatarDelete'])->name('profile.avatar.delete');
});
