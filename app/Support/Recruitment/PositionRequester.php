<?php

namespace App\Support\Recruitment;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The manager who asked for a position follows its interviews and offers
 * read-only. One rule, applied to the recruitment request a vacancy was
 * created from: he raised it (requester_user_id), or HR opened the position
 * directly on his behalf (actual_requester_user_id). Not his line manager.
 */
final class PositionRequester
{
    /** Narrows a recruitment_requests query to the requests this user asked for. */
    public static function constrain(Builder $requests, User $user): Builder
    {
        return $requests->where(fn (Builder $requester): Builder => $requester
            ->where($requester->qualifyColumn('requester_user_id'), $user->getKey())
            ->orWhere($requester->qualifyColumn('actual_requester_user_id'), $user->getKey()));
    }
}
