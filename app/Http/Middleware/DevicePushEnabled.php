<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Punches sent by the devices themselves (/iclock, ADMS) and by the LAN agent are not received for
 * now (config/attendance.php device_push): HR uploads the report file instead. While it is off
 * those addresses answer as if they did not exist, and nothing is stored or listed.
 */
class DevicePushEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('attendance.device_push'), 404);

        return $next($request);
    }
}
