<?php

use App\Http\Middleware\ActivePermissionMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RecordPresence;
use App\Http\Middleware\ReferencePageMiddleware;
use App\Http\Middleware\RememberUserLocale;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(SecurityHeaders::class);

        // Re-checks the account's active flag on every authenticated request,
        // including the Livewire update endpoint. Deactivation must end
        // existing sessions, not merely block the next login.
        $middleware->web(append: [
            EnsureAccountIsActive::class,
            // Language for mail sent outside a request (A4).
            RememberUserLocale::class,
            // users.last_seen_at, at most once a minute (who is online, on the big clock).
            RecordPresence::class,
        ]);

        $middleware->alias([
            /**** OTHER MIDDLEWARE ALIASES ****/
            'localize' => LaravelLocalizationRoutes::class,
            'localizationRedirect' => LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => LocaleSessionRedirect::class,
            'localeCookieRedirect' => LocaleCookieRedirect::class,
            'localeViewPath' => LaravelLocalizationViewPath::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active_permission' => ActivePermissionMiddleware::class,
            'reference_page' => ReferencePageMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
