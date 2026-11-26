<?php

namespace App\Console\Commands;

use App\Services\Board\BoardMeetingService;
use Illuminate\Console\Command;

/**
 * Opens the board's next meeting at its usual slot (config/board.php) when its week has none yet, so the
 * members are told to hold it. The chair moves it, cancels it with a reason, or adds another from the page.
 */
class EnsureNextBoardMeeting extends Command
{
    protected $signature = 'board:ensure-next-meeting';

    protected $description = 'Open the next board meeting at its usual day and time when this week has none';

    public function handle(BoardMeetingService $service): int
    {
        $meeting = $service->ensureNext();

        $this->info($meeting
            ? "Opened board meeting {$meeting->number} for {$meeting->starts_at->format('Y-m-d H:i')}."
            : 'Nothing to open.');

        return self::SUCCESS;
    }
}
