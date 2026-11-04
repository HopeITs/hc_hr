<?php

namespace App\Enums\News;

/**
 * The stored editorial status. "Scheduled" and "Expired" are not stored:
 * they are a published item read against the clock (NewsState::of()).
 */
enum NewsStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
