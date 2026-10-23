<?php

use App\Http\Middleware\ActivePermissionMiddleware;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RememberUserLocale;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Monitoring\SecurityMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

// Threaded Apache (mod_php on Windows) shares putenv()/getenv() across every
// request in the process, while $_ENV/$_SERVER are per request. Concurrent
// requests then see each other's half-loaded environment and boot without
// .env at all (no APP_KEY, env('APP_URL') null). Keep .env in the superglobals.
if (PHP_SAPI === 'apache2handler' && PHP_ZTS) {
    Illuminate\Support\Env::disablePutenv();
}

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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Monitoring taps. Both only observe: a render callback that returns
        // null hands the exception straight back to the framework's own
        // rendering, and neither can throw (SecurityMonitor swallows its own
        // failures). Throttling and 403s are on the framework's do-not-report
        // list, which is why report() callbacks would never see them.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            app(SecurityMonitor::class)->rateLimitHit($request);

            return null;
        });

        // By now a denied gate or policy has already been mapped to an
        // AccessDeniedHttpException, so a 403 from anywhere — a permission
        // middleware, a policy, an abort() — arrives here as one type.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() === 403) {
                app(SecurityMonitor::class)->accessDenied($request, $e);
            }

            return null;
        });
    })->create();
