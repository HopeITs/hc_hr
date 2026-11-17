<?php

namespace App\Services\Attendance;

use RuntimeException;

/** The uploaded file is not an attendance report this system can read. */
class UnreadableReport extends RuntimeException {}
