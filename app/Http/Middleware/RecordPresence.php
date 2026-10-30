<?php

namespace App\Http\Middleware;

use App\Support\Dashboard\Presence;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps the signed-in account as seen (users.last_seen_at), at most once a
 * minute, for "who is online" on the big clock. A failed write never fails
 * the request it rode on.
 */
class RecordPresence
{
    public function __construct(private Presence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            rescue(fn () => $this->presence->touch($user), report: false);
        }

        return $next($request);
    }
}
