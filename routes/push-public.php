<?php

use App\Http\Controllers\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

// The service worker's pushsubscriptionchange: no session, no CSRF token (it is
// exempted in bootstrap/app.php); the old endpoint + its auth secret are the proof.
Route::post('push/renew', [PushSubscriptionController::class, 'renew'])
    ->middleware('throttle:10,1')
    ->name('push.renew');
