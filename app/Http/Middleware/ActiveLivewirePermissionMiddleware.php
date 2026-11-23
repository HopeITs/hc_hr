<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Livewire\Features\SupportEvents\SupportEvents;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ActiveLivewirePermissionMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Login, password reset, and registration also use the shared Livewire
        // update endpoint. Only authenticated requests can match protected
        // application mutation rules.
        if (! $user) {
            return $next($request);
        }

        foreach ($request->input('components', []) as $component) {
            $snapshot = json_decode($component['snapshot'] ?? '', true);
            $componentName = data_get($snapshot, 'memo.name');

            if (! is_string($componentName)) {
                continue;
            }

            foreach ($component['calls'] ?? [] as $call) {
                $method = $call['method'] ?? null;

                // A method reached through an event (a confirm dialog's callback, a
                // $dispatchTo) arrives as "__dispatch" with the event's name. Resolve it to
                // the listener method, so it is held to the same permission as a direct call:
                // without this every listener (delete, export, toggle…) skipped the map.
                if ($method === '__dispatch') {
                    $method = $this->listenerMethod($componentName, $call['params'][0] ?? null) ?? $method;
                }

                $permission = config('access-control.livewire', [])[$componentName][$method] ?? null;

                if (is_string($permission)) {
                    abort_unless($user->hasActivePermission($permission), 403);
                }
            }
        }

        return $next($request);
    }

    /** The method an event runs on the named component, or null when it has no such listener. */
    private function listenerMethod(string $componentName, mixed $event): ?string
    {
        if (! is_string($event) || ! is_array(config('access-control.livewire', [])[$componentName] ?? null)) {
            return null;
        }

        try {
            $instance = Livewire::new($componentName);

            return in_array($event, SupportEvents::getListenerEventNames($instance), true)
                ? SupportEvents::getListenerMethodName($instance, $event)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
