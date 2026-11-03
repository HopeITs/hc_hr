<?php

use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

// Inside the auth + locale group (routes/web.php). This browser's own push
// subscription only (docs/notifications/architecture.md, HTTP); other devices
// are managed from the settings page.
Route::middleware('throttle:30,1')
    ->prefix('push/subscriptions')
    ->name('push.subscriptions.')
    ->controller(PushSubscriptionController::class)
    ->group(function () {
        Route::post('/', 'store')->name('store');
        Route::post('sync', 'sync')->name('sync');
        Route::delete('current', 'destroyCurrent')->name('destroy-current');
    });
