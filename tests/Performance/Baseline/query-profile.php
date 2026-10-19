<?php

/*
 * X1 performance baseline: where a page's SQL comes from.
 *
 *   PERF_HOME=<dir> php tests/Performance/Baseline/query-profile.php <persona-email> <path> [<path> ...]
 *
 * Renders each path once (warm: a first unrecorded render compiles views
 * and fills caches) as the given synthetic persona, against the measurement
 * database, and groups the SQL it runs by statement shape and by the first
 * application frame that issued it (app/ or a Blade view). Read-only: GET
 * requests only, nothing is written except the session and cache rows any
 * request writes. Output is JSON on stdout.
 */

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;

require __DIR__.'/env.php';

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
perf_configure($app);
$kernel = $app->make(HttpKernel::class);
// Providers (and the locale-prefixed route group) read the request while
// booting; give them an English one, as a browser hitting / would.
$serverVars = ['HTTP_HOST' => '127.0.0.1:'.(getenv('PERF_PORT') ?: '8390'), 'HTTP_ACCEPT_LANGUAGE' => 'en'];
$app->instance('request', Request::create('/', 'GET', server: $serverVars));
$kernel->bootstrap();
perf_assert_isolated();

$email = $argv[1] ?? exit('usage: query-profile.php <persona-email> <path> [<path> ...]'.PHP_EOL);
$paths = array_slice($argv, 2);
$user = User::query()->where('email', $email)->firstOrFail();

$viewSource = function (string $compiled): string {
    static $map = [];
    if (! isset($map[$compiled])) {
        $tail = @file_get_contents($compiled, false, null, max(0, filesize($compiled) - 400)) ?: '';
        $map[$compiled] = preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $tail, $m) ? $m[1] : $compiled;
    }

    return $map[$compiled];
};

$origin = function () use ($root, $viewSource): string {
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
        $file = str_replace('\\', '/', $frame['file'] ?? '');
        if ($file === '' || str_contains($file, '/vendor/') || str_contains($file, '/tests/Performance/')) {
            continue;
        }
        if (str_contains($file, '/storage/framework/views/')) {
            $file = str_replace('\\', '/', $viewSource($frame['file']));
        }

        return ltrim(str_replace(str_replace('\\', '/', $root), '', $file), '/').':'.($frame['line'] ?? '?');
    }

    return 'vendor';
};

$report = [];
foreach ($paths as $path) {
    foreach (['prime', 'measure'] as $pass) {
        $queries = [];
        $listener = function (QueryExecuted $e) use (&$queries, $origin): void {
            $shape = preg_replace(['/\'[^\']*\'/', '/\b\d+\b/', '/\(\s*\?(\s*,\s*\?)+\s*\)/'], ['?', '?', '(?…)'], $e->sql);
            $queries[] = ['shape' => $shape, 'origin' => $origin(), 'ms' => $e->time];
        };
        $app['events']->listen(QueryExecuted::class, $listener);

        $app['auth']->guard('web')->setUser($user);
        $request = Request::create($path, 'GET', server: $serverVars);
        $started = microtime(true);
        $response = $kernel->handle($request);
        $elapsed = (microtime(true) - $started) * 1000;
        $kernel->terminate($request, $response);

        $app['events']->forget(QueryExecuted::class);
        $app->forgetScopedInstances();
    }

    $byOrigin = collect($queries)->groupBy('origin')
        ->map(fn ($g, $o) => ['origin' => $o, 'count' => $g->count(), 'ms' => round($g->sum('ms'), 1),
            'distinct_shapes' => $g->pluck('shape')->unique()->count(), 'example' => mb_substr($g->first()['shape'], 0, 160)])
        ->sortByDesc('count')->values();

    $report[$path] = [
        'status' => $response->getStatusCode(),
        'render_ms' => round($elapsed, 1),
        'queries' => count($queries),
        'sql_ms' => round(collect($queries)->sum('ms'), 1),
        'top_origins' => $byOrigin->take(15)->all(),
        'repeated_shapes' => collect($queries)->groupBy('shape')->filter(fn ($g) => $g->count() >= 10)
            ->map(fn ($g, $s) => ['count' => $g->count(), 'shape' => mb_substr($s, 0, 200), 'origins' => $g->pluck('origin')->unique()->take(3)->values()])
            ->sortByDesc('count')->values()->take(10)->all(),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
