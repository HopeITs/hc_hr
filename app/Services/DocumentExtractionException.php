<?php

namespace App\Services;

use RuntimeException;

/** Post Gen could not read a document; the message is safe to show the user. */
class DocumentExtractionException extends RuntimeException {}
