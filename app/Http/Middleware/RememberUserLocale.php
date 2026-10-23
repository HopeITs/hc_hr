<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers the interface language an authenticated user is working in, so
 * mail sent later (outside any request) can use it — see
 * User::preferredLocale().
 *
 * Writes only when the language changes, and through the query builder: no
 * model events, no activity-log row, no updated_at bump for a preference.
 */
class RememberUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $locale = app()->getLocale();

        if ($user
            && $user->locale !== $locale
            && array_key_exists($locale, LaravelLocalization::getSupportedLocales())
        ) {
            $user->newQuery()->whereKey($user->getKey())->toBase()->update(['locale' => $locale]);
            $user->setRawAttributes(array_merge($user->getAttributes(), ['locale' => $locale]), true);
        }

        return $next($request);
    }
}
