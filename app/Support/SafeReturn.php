<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Where a create screen opened from New (C2) sends the user back to on
 * Cancel: the page they started from, but only when it is an internal path
 * of an allowlisted route (Navigation::returnRoutes()). Anything else (an
 * external or protocol-relative URL, a route outside the list, a path that
 * matches no route) gives null, and the screen keeps its usual destination.
 * The page returned to still authorizes the visit itself.
 */
final class SafeReturn
{
    public static function resolve(mixed $from, User $user): ?string
    {
        if (! is_string($from) || $from === '' || strlen($from) > 2000) {
            return null;
        }

        // Internal paths only: "/x", never "//host", "\\host", "http:…" or control characters.
        if ($from[0] !== '/' || str_starts_with($from, '//') || str_contains($from, '\\') || preg_match('/[\x00-\x1F\x7F]/', $from)) {
            return null;
        }

        $path = strtok($from, '#');

        try {
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpException|Throwable) {
            return null;
        }

        $name = $route->getName();

        if ($name === null || ! in_array($name, Navigation::returnRoutes($user, Navigation::isDeliverResponsible($user)), true)) {
            return null;
        }

        return url($path);
    }
}
