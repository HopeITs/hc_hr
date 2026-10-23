<?php

/*
 * X2 usability baseline: serve the session copy of the app.
 *
 *   php -d extension=pdo_sqlite -S 127.0.0.1:8391 -t public tests/Usability/Baseline/router.php
 *
 * Sets X2's home, then hands over to X1's router, which forces the SQLite
 * session database, mail to "array" and production mode before Laravel boots.
 */

require __DIR__.'/x2-home.php';

return require dirname(__DIR__, 2).'/Performance/Baseline/router.php';
