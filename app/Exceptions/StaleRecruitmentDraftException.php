<?php

namespace App\Exceptions;

use RuntimeException;

class StaleRecruitmentDraftException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This draft was changed by another user. Reload the latest version before saving.');
    }
}
