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
    /** A manager's item for more than their team, waiting for HR to approve it (HR-040). */
    case Pending = 'pending';
    /** HR turned a pending item down; review_note holds the reason. */
    case Rejected = 'rejected';
}
