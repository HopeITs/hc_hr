<?php

use App\Http\Controllers\IclockController;
use Illuminate\Support\Facades\Route;

/*
 * Where a ZKTeco device's "Cloud Server" (ADMS) calls. Outside the language prefix, the session and
 * the login: a device has none of those. It is believed by its registered serial number only
 * (App\Services\Attendance\DevicePush). Loaded by bootstrap/app.php with the "api" group and the
 * "iclock" rate limit.
 */
// Off for now (config/attendance.php device_push): every address below answers 404 until it is turned on.
Route::middleware(App\Http\Middleware\DevicePushEnabled::class)->group(function () {
    Route::match(['get', 'post'], 'iclock/cdata', [IclockController::class, 'cdata'])->name('iclock.cdata');
    Route::match(['get', 'post'], 'iclock/getrequest', [IclockController::class, 'getrequest'])->name('iclock.getrequest');
    Route::match(['get', 'post'], 'iclock/devicecmd', [IclockController::class, 'devicecmd'])->name('iclock.devicecmd');

    // The attendance agent (tools/attendance-agent) sends the punches it read from a device on its own network.
    // Authenticated by the device's sync key (Authorization: Bearer zk_…), not by a login.
    Route::prefix('api/attendance/agent')->middleware('throttle:attendance-agent')->group(function () {
        Route::get('state', [App\Http\Controllers\AttendanceAgentController::class, 'state'])->name('attendance.agent.state');
        Route::post('punches', [App\Http\Controllers\AttendanceAgentController::class, 'punches'])->name('attendance.agent.punches');
    });
});
