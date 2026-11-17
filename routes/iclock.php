<?php

use App\Http\Controllers\IclockController;
use Illuminate\Support\Facades\Route;

/*
 * Where a ZKTeco device's "Cloud Server" (ADMS) calls. Outside the language prefix, the session and
 * the login: a device has none of those. It is believed by its registered serial number only
 * (App\Services\Attendance\DevicePush). Loaded by bootstrap/app.php with the "api" group and the
 * "iclock" rate limit.
 */
Route::match(['get', 'post'], 'iclock/cdata', [IclockController::class, 'cdata'])->name('iclock.cdata');
Route::match(['get', 'post'], 'iclock/getrequest', [IclockController::class, 'getrequest'])->name('iclock.getrequest');
Route::match(['get', 'post'], 'iclock/devicecmd', [IclockController::class, 'devicecmd'])->name('iclock.devicecmd');
