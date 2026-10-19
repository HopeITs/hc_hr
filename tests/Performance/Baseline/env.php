<?php

/*
 * X1 performance baseline: environment for the measurement copy of the app.
 *
 * Included before Laravel boots, by both build-fixtures.php and router.php.
 * Every value is written into $_SERVER and $_ENV and through putenv(), so
 * phpdotenv (which never overwrites an existing variable) leaves them alone
 * and the developer's .env cannot point this copy at the operational MySQL.
 *
 * PERF_HOME holds everything this harness writes: the SQLite database, a
 * separate storage directory (compiled views, sessions, synthetic avatars,
 * logs) and the results. It defaults to a folder under the system temp
 * directory, never inside the repository.
 */

$perfHome = getenv('PERF_HOME') ?: sys_get_temp_dir().DIRECTORY_SEPARATOR.'hc-hr-perf';
$perfHome = rtrim(str_replace('\\', '/', $perfHome), '/');
$perfPort = getenv('PERF_PORT') ?: '8390';

// A cached config ignores every override below and would boot on the .env
// database. Refuse before anything connects.
if (is_file(dirname(__DIR__, 3).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('X1 harness refused to run: bootstrap/cache/config.php exists. Run php artisan config:clear first.');
}

foreach (['', '/storage', '/storage/app/private', '/storage/app/public', '/storage/framework/cache/data',
    '/storage/framework/sessions', '/storage/framework/views', '/storage/logs'] as $dir) {
    if (! is_dir($perfHome.$dir)) {
        mkdir($perfHome.$dir, 0777, true);
    }
}

$overrides = [
    'PERF_HOME' => $perfHome,
    'LARAVEL_STORAGE_PATH' => $perfHome.'/storage',
    // As on the live server: no debug mode, and none of the local-only dev
    // tooling (Laravel Boost injects a browser-logger script under 'local').
    'APP_ENV' => 'production',
    'APP_DEBUG' => 'false',
    'BOOST_ENABLED' => 'false',
    'APP_URL' => 'http://127.0.0.1:'.$perfPort,
    'APP_LOCALE' => 'en',
    'APP_FALLBACK_LOCALE' => 'en',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $perfHome.'/perf.sqlite',
    'DB_FOREIGN_KEYS' => 'true',
    // Same drivers as the dev/prod .env, so sessions and cache cost what
    // they cost in the real app — but on the measurement database.
    'SESSION_DRIVER' => 'database',
    'SESSION_DOMAIN' => '',
    'SESSION_SECURE_COOKIE' => 'false',
    'CACHE_STORE' => 'database',
    'QUEUE_CONNECTION' => 'database',
    // Nothing leaves this machine: no mail, no broadcasts.
    'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'log',
    'LOG_CHANNEL' => 'single',
    'LOG_LEVEL' => 'warning',
];

foreach ($overrides as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

/**
 * Abort unless the booted app really is on the measurement database.
 * Called after bootstrap by every entry point of this harness.
 */
function perf_assert_isolated(): void
{
    $default = config('database.default');
    $database = str_replace('\\', '/', (string) config("database.connections.{$default}.database"));
    $expected = getenv('PERF_HOME').'/perf.sqlite';

    if ($default !== 'sqlite' || $database !== $expected || ! str_starts_with(str_replace('\\', '/', storage_path()), getenv('PERF_HOME'))) {
        throw new RuntimeException("X1 harness refused to run: database is {$default}:{$database}, storage is ".storage_path().'. Is the config cached? Run php artisan config:clear.');
    }
}

/**
 * Runtime config the .env cannot express: several php -S workers share one
 * SQLite file, so wait for locks instead of failing, and use WAL so readers
 * do not block the session writer. MySQL, which production uses, needs
 * neither.
 */
function perf_configure(Illuminate\Foundation\Application $app): void
{
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
        $app['config']->set('database.connections.sqlite.busy_timeout', 10000);
        $app['config']->set('database.connections.sqlite.journal_mode', 'wal');
    });
}
