<?php

/*
 * X1 performance baseline: instrumented router for PHP's built-in server.
 *
 *   PERF_HOME=<dir> php -d opcache.enable_cli=1 -S 127.0.0.1:8390 -t public tests/Performance/Baseline/router.php
 *
 * Same as public/index.php, plus response headers the browser harness reads.
 * No application code is changed; the listeners only count.
 *
 *   Server-Timing: app;dur=<ms from LARAVEL_START to response built>, db;dur=<ms in SQL>
 *   X-Perf-Queries: <number of SQL statements>
 *   X-Perf-Memory: <peak bytes>
 *   X-Perf-Raw-Bytes: <body size before compression>
 *
 * php -S neither compresses nor sets cache headers, while production's
 * public/.htaccess does both. This router applies the same rules, so wire
 * sizes and repeat visits look like they would behind that Apache config:
 *   - mod_deflate: gzip for HTML, CSS, JS, JSON and SVG (brotli is not
 *     available to PHP here, so .htaccess's brotli branch is not emulated);
 *   - mod_headers: "public, max-age=31536000, immutable" on static assets.
 */

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

const PERF_COMPRESSIBLE = ['text/html', 'text/plain', 'text/xml', 'text/css', 'text/javascript',
    'application/javascript', 'application/json', 'application/xml', 'image/svg+xml'];

$public = dirname(__DIR__, 3).'/public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$acceptsGzip = str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip');

if ($uri !== '/' && is_file($public.$uri) && ! str_contains($uri, '..')) {
    $types = [
        'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
    ];
    $ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));

    // Anything else (the loader video, which needs range requests) is left
    // to the built-in server.
    if (! isset($types[$ext])) {
        return false;
    }

    $file = $public.$uri;
    $body = file_get_contents($file);
    header('Content-Type: '.$types[$ext]);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');

    if ($acceptsGzip && in_array($types[$ext], PERF_COMPRESSIBLE, true)) {
        $cache = getenv('PERF_HOME') ?: sys_get_temp_dir().'/hc-hr-perf';
        $gz = $cache.'/gz/'.md5($file.filemtime($file)).'.gz';
        if (! is_file($gz)) {
            @mkdir(dirname($gz), 0777, true);
            file_put_contents($gz, gzencode($body, 6));
        }
        $body = file_get_contents($gz);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
    }

    header('Content-Length: '.strlen($body));
    echo $body;

    return true;
}

define('LARAVEL_START', microtime(true));

require __DIR__.'/env.php';
require $public.'/../vendor/autoload.php';

$app = require_once $public.'/../bootstrap/app.php';
perf_configure($app);

$queries = 0;
$queryMs = 0.0;

$app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$queries, &$queryMs): void {
    $queries++;
    $queryMs += $event->time;
});

// Mirrors Application::handleRequest(), with the measurement in between.
// It runs after handle() returns, i.e. after every RequestHandled listener
// (Livewire injects its assets there), so compression sees the final body.
$kernel = $app->make(HttpKernel::class);
$request = Request::capture();
$response = $kernel->handle($request);

perf_assert_isolated();

$response->headers->set('Server-Timing', sprintf('app;dur=%.1f, db;dur=%.1f', (microtime(true) - LARAVEL_START) * 1000, $queryMs));
$response->headers->set('X-Perf-Queries', (string) $queries);
$response->headers->set('X-Perf-Memory', (string) memory_get_peak_usage(true));

if (! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse) {
    $content = (string) $response->getContent();
    $response->headers->set('X-Perf-Raw-Bytes', (string) strlen($content));

    $type = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));
    if ($acceptsGzip && $content !== '' && in_array($type, PERF_COMPRESSIBLE, true)) {
        $response->setContent(gzencode($content, 6));
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Vary', 'Accept-Encoding');
        $response->headers->set('Content-Length', (string) strlen($response->getContent()));
    }
}

$response->send();
$kernel->terminate($request, $response);
