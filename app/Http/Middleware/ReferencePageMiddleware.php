<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A reference page's read check (G-B-13): its `.view` permission, refused for
 * the roles config('access-control.reference_pages_hidden_from') names.
 */
class ReferencePageMiddleware
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        abort_unless($request->user()?->canOpenReferencePage($page), 403);

        return $next($request);
    }
}
