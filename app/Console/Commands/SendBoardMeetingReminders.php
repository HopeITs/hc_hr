<?php

namespace App\Console\Commands;

use App\Mail\BoardMeetingReminderMail;
use App\Models\BoardMeeting;
use App\Models\Employee;
use App\Notifications\BoardMeetingNotice;
use App\Services\Board\BoardAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

            // And by email, with the meeting as a calendar invitation: the board is used to email.
            $emails = $access->currentMembers()->with('account')->get()
                ->map(fn (Employee $e) => $e->account?->email)->filter()->unique();

            foreach ($emails as $email) {
                try {
                    Mail::to($email)->send(new BoardMeetingReminderMail($meeting));
                } catch (Throwable $e) {
                    // One bad address must not stop the others' reminders.
                    report($e);
                }
            }
        }

        $this->info("Reminded about {$meetings->count()} board meeting(s).");

        return self::SUCCESS;
    }
}
