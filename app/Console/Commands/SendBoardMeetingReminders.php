<?php

namespace App\Console\Commands;

use App\Models\BoardMeeting;
use App\Notifications\BoardMeetingNotice;
use App\Services\Board\BoardAccess;
use Illuminate\Console\Command;

/**
 * One reminder, the day before a board meeting (config board.remind_days_before), to the members and
 * the two who run it. `reminded_at` makes it once: a meeting that is moved is reminded about again.
 */
class SendBoardMeetingReminders extends Command
{
    protected $signature = 'board:send-reminders';

    protected $description = 'Remind the board the day before its meeting, once';

    public function handle(BoardAccess $access): int
    {
        $day = now()->addDays((int) config('board.remind_days_before', 1))->startOfDay();

        $meetings = BoardMeeting::query()
            ->where('status', BoardMeeting::SCHEDULED)
            ->whereNull('reminded_at')
            ->whereBetween('starts_at', [$day, $day->copy()->endOfDay()])
            ->get();

        foreach ($meetings as $meeting) {
            // Claim it first: a second run of the command must find nothing left to send.
            $claimed = BoardMeeting::query()->whereKey($meeting->id)->whereNull('reminded_at')->update(['reminded_at' => now()]);

            if (! $claimed) {
                continue;
            }

            foreach ($access->audience() as $user) {
                $user->notify(BoardMeetingNotice::for(BoardMeetingNotice::REMINDER, $meeting));
            }
        }

        $this->info("Reminded about {$meetings->count()} board meeting(s).");

        return self::SUCCESS;
    }
}
