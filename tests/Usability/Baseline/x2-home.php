<?php

/*
 * X2 usability baseline: point the X1 harness at X2's own folder.
 *
 * X2 reuses X1's synthetic builder (tests/Performance/Baseline/build-fixtures.php)
 * and router unchanged. Both read PERF_HOME, so every X2 entry point sets it to
 * X2_HOME first. That keeps X1's own measurement copy untouched, and keeps the
 * session database outside the repository.
 */

$x2Home = getenv('X2_HOME') ?: sys_get_temp_dir().DIRECTORY_SEPARATOR.'hc-hr-x2';
$x2Home = rtrim(str_replace('\\', '/', $x2Home), '/');

if (str_starts_with($x2Home, str_replace('\\', '/', dirname(__DIR__, 3)))) {
    throw new RuntimeException("X2 harness refused to run: X2_HOME ({$x2Home}) is inside the repository.");
}

putenv("PERF_HOME={$x2Home}");
$_ENV['PERF_HOME'] = $_SERVER['PERF_HOME'] = $x2Home;

$x2Port = getenv('X2_PORT') ?: '8391';
putenv("PERF_PORT={$x2Port}");
$_ENV['PERF_PORT'] = $_SERVER['PERF_PORT'] = $x2Port;
