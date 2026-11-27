<?php

namespace App\Services\Board;

use App\Mail\BoardMinutesMail;
use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;
use App\Models\BoardMeetingFollowUp;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BoardMeetingNotice;
use App\Services\ContractPdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The life of a board meeting: opened (by the chair or by the scheduler), moved, cancelled with a reason,
 * attendance and absence requests, and the minutes published to the members.
 */
class BoardMeetingService
{
    public function __construct(private readonly BoardAccess $access) {}

    /** @param array{starts_at: mixed, ends_at?: mixed, location?: ?string, room?: ?string, purpose?: ?string, led_by?: ?string} $data */
    public function schedule(array $data, ?User $by = null, bool $notify = true): BoardMeeting
    {
        $meeting = Cache::lock(BoardMeeting::NUMBER_LOCK, 60)->block(10, function () use ($data, $by): BoardMeeting {
            return DB::transaction(function () use ($data, $by): BoardMeeting {
                $meeting = BoardMeeting::create([
                    'number' => BoardMeeting::nextNumber(),
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'] ?? null,
                    'location' => $data['location'] ?? config('board.default_location'),
                    'room' => $data['room'] ?? null,
                    'purpose' => $data['purpose'] ?? null,
                    // The chair leads it unless told otherwise.
                    'led_by' => $data['led_by'] ?? $this->chairName(),
                    'status' => BoardMeeting::SCHEDULED,
                    'created_by' => $by?->id,
                ]);

                $this->syncAttendees($meeting);

                return $meeting;
            });
        });

        if ($notify) {
            $this->tell($meeting, BoardMeetingNotice::SCHEDULED);
        }

        return $meeting;
    }

    /** The default slot after $from: the configured weekday and time. */
    public function defaultSlot(CarbonImmutable $from): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', (string) config('board.time', '12:00')) + [0, 0]);
        $slot = $from->startOfDay()->setTime($hour, $minute);
        $weekday = (int) config('board.weekday', 4);

        while ($slot->dayOfWeekIso !== $weekday || $slot->lessThanOrEqualTo($from)) {
            $slot = $slot->addDay();
        }

        return $slot;
    }

    /**
     * Opens the meeting of the coming slot when none stands for that week (cancelled ones count: the chair
     * decided that week) and the cadence allows it. Returns it, or null when nothing was needed.
     */
    public function ensureNext(?CarbonImmutable $now = null): ?BoardMeeting
    {
        $now ??= CarbonImmutable::now();
        $slot = $this->defaultSlot($now);

        if ($now->diffInDays($slot, false) > (int) config('board.look_ahead_days', 7)) {
            return null;
        }

        $weekStart = $slot->startOfWeek();

        if ($this->meetingInWeek($slot)) {
            return null;
        }

        $every = max(1, (int) config('board.every_weeks', 1));
        $last = BoardMeeting::query()->where('starts_at', '<', $weekStart)->orderByDesc('starts_at')->first();

        if ($last && $every > 1 && (int) $last->starts_at->startOfWeek()->diffInWeeks($weekStart) < $every) {
            return null;
        }

        return $this->schedule([
            'starts_at' => $slot,
            'ends_at' => $slot->addMinutes((int) config('board.duration_minutes', 90)),
        ]);
    }

    /** @param array{starts_at: mixed, ends_at?: mixed, location?: ?string, room?: ?string, purpose?: ?string, led_by?: ?string} $data */
    public function update(BoardMeeting $meeting, array $data): BoardMeeting
    {
        $this->assertOpen($meeting);

        // To the minute: the form's date-and-time field has no seconds.
        $moved = $meeting->starts_at->format('Y-m-d H:i') !== CarbonImmutable::parse($data['starts_at'])->format('Y-m-d H:i');

        // A meeting that has begun took place when it did: its date is history, not a plan.
        if ($moved && $meeting->hasStarted()) {
            throw ValidationException::withMessages(['startsAt' => __('board.locks.started-date')]);
        }

        $meeting->fill(array_intersect_key($data, array_flip(['starts_at', 'ends_at', 'location', 'room', 'purpose', 'led_by'])));

        if ($moved) {
            // A new day is reminded about once more, the day before it.
            $meeting->reminded_at = null;
        }

        $meeting->save();

        if ($moved) {
            $this->tell($meeting, BoardMeetingNotice::RESCHEDULED);
        }

        return $meeting;
    }

    public function cancel(BoardMeeting $meeting, string $reason): void
    {
        $this->assertOpen($meeting);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('board.cancel.reason-required')]);
        }

        if ($meeting->hasStarted()) {
            throw ValidationException::withMessages(['cancelReason' => __('board.locks.started-cancel')]);
        }

        DB::transaction(function () use ($meeting, $reason) {
            $meeting->update(['status' => BoardMeeting::CANCELLED, 'cancel_reason' => $reason]);
            $this->returnCarriedIn($meeting);
        });

        $this->tell($meeting, BoardMeetingNotice::CANCELLED, $reason);
    }

    public function restore(BoardMeeting $meeting): void
    {
        // Only a meeting still ahead can be held after all.
        abort_if($meeting->hasStarted(), 403);

        $meeting->update(['status' => BoardMeeting::SCHEDULED, 'cancel_reason' => null, 'reminded_at' => null]);
        $this->tell($meeting, BoardMeetingNotice::RESCHEDULED);
    }

    /** Every current member has a row; a member who joined after the meeting was opened gets one on the next call. */
    public function syncAttendees(BoardMeeting $meeting): void
    {
        // Old minutes say who was there in their own file; the system does not guess.
        if ($meeting->isArchive()) {
            return;
        }

        $have = $meeting->attendees()->pluck('employee_id');

        foreach ($this->access->currentMembers()->whereNotIn('employees.id', $have)->pluck('employees.id') as $employeeId) {
            $meeting->attendees()->create(['employee_id' => $employeeId, 'status' => BoardMeetingAttendee::EXPECTED]);
        }
    }

    /** The secretary's word on where a member is: present, online, absent or still expected. */
    public function setAttendance(BoardMeetingAttendee $attendee, string $status, ?string $reason = null): void
    {
        $this->assertOpen($attendee->meeting);

        if (! in_array($status, BoardMeetingAttendee::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => __('board.attendance.invalid')]);
        }

        // Nobody is present at a meeting that has not begun: attendance is taken from its time on.
        if (! $attendee->meeting->hasStarted()) {
            throw ValidationException::withMessages(['status' => __('board.locks.attendance-before-start')]);
        }

        $attendee->update([
            'status' => $status,
            'absence_reason' => $status === BoardMeetingAttendee::ABSENT ? ($reason ?: $attendee->absence_reason) : null,
        ]);
    }

    /** A member tells the chair they cannot come, and why. */
    public function requestAbsence(BoardMeeting $meeting, Employee $employee, string $reason): BoardMeetingAttendee
    {
        $this->assertOpen($meeting);
        abort_unless($meeting->isUpcoming(), 403);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('board.absence.reason-required')]);
        }

        $this->syncAttendees($meeting);
        $attendee = $meeting->attendees()->where('employee_id', $employee->id)->firstOrFail();

        $attendee->update([
            'absence_reason' => $reason,
            'absence_request' => BoardMeetingAttendee::REQUEST_PENDING,
            'absence_decided_at' => null,
        ]);

        foreach ($this->access->holders(BoardAccess::MANAGE) as $chair) {
            $chair->notify(BoardMeetingNotice::for(BoardMeetingNotice::ABSENCE_REQUESTED, $meeting, $employee->name));
        }

        return $attendee;
    }

    /**
     * A member takes back their absence request: one the chair has not answered yet, or an approved one while the
     * meeting is still ahead (they are expected again). Either way the chair is told.
     *
     * @return string|null the request that was taken back (pending / approved), or null when there was none
     */
    public function withdrawAbsence(BoardMeeting $meeting, Employee $employee): ?string
    {
        $this->assertOpen($meeting);

        $attendee = $meeting->attendees()
            ->where('employee_id', $employee->id)
            ->whereIn('absence_request', [BoardMeetingAttendee::REQUEST_PENDING, BoardMeetingAttendee::REQUEST_APPROVED])
            ->first();

        if (! $attendee) {
            return null;
        }

        $was = $attendee->absence_request;

        if ($was === BoardMeetingAttendee::REQUEST_APPROVED) {
            // Once the meeting has begun the secretary's record stands: an approved absence is taken back only before.
            if (! $meeting->isUpcoming()) {
                throw ValidationException::withMessages(['absence' => __('board.absence.too-late')]);
            }

            $attendee->status = BoardMeetingAttendee::EXPECTED;
        }

        $attendee->fill(['absence_request' => null, 'absence_reason' => null, 'absence_decided_at' => null])->save();

        foreach ($this->access->holders(BoardAccess::MANAGE) as $chair) {
            $chair->notify(BoardMeetingNotice::for(BoardMeetingNotice::ABSENCE_WITHDRAWN, $meeting, $employee->name));
        }

        return $was;
    }

    /**
     * The chair approves (the member is listed as absent) or turns the request down. Only a request still waiting
     * can be decided: none at all, one already answered or one the member took back is refused, so a stale page or a
     * hand-made call cannot mark someone absent who never asked, or flip an earlier answer.
     */
    public function decideAbsence(BoardMeetingAttendee $attendee, bool $approve): void
    {
        $this->assertOpen($attendee->meeting);

        $decided = $approve ? BoardMeetingAttendee::REQUEST_APPROVED : BoardMeetingAttendee::REQUEST_REJECTED;
        $changes = ['absence_request' => $decided, 'absence_decided_at' => now()]
            + ($approve ? ['status' => BoardMeetingAttendee::ABSENT] : []);

        // Conditional on the row still being pending, so two decisions racing each other cannot both land.
        $updated = BoardMeetingAttendee::query()
            ->whereKey($attendee->getKey())
            ->where('absence_request', BoardMeetingAttendee::REQUEST_PENDING)
            ->update($changes);

        if ($updated === 0) {
            throw ValidationException::withMessages(['absence' => __('board.absence.not-pending')]);
        }

        $attendee->refresh();

        $attendee->loadMissing('employee.account', 'meeting');

        $attendee->employee?->account?->notify(BoardMeetingNotice::for(
            BoardMeetingNotice::ABSENCE_DECIDED,
            $attendee->meeting,
            $approve ? BoardMeetingAttendee::REQUEST_APPROVED : BoardMeetingAttendee::REQUEST_REJECTED,
        ));
    }

    /** Publishes the minutes: the members are told, and (when asked) get them by email with the PDF. */
    public function publish(BoardMeeting $meeting, User $by, bool $email = false): void
    {
        $this->assertOpen($meeting);

        // Minutes record a meeting that took place: before its time there is nothing to minute, and publishing would
        // lock a meeting the chair may still need to move or cancel (same rule as attendance).
        if (! $meeting->hasStarted()) {
            throw ValidationException::withMessages(['publish' => __('board.locks.publish-before-start')]);
        }

        $meeting->update([
            'published_at' => now(),
            'prepared_by' => $meeting->prepared_by ?: $by->profile?->name,
        ]);

        $this->tell($meeting, BoardMeetingNotice::MINUTES);
        $this->tellFollowUpOwners($meeting);

        if ($email) {
            $this->emailMinutes($meeting);
        }
    }

    /**
     * The chair takes published minutes back to a draft to correct them: members no longer read them until they are
     * published again, and the reason is kept on the meeting. Nobody else can.
     */
    public function reopen(BoardMeeting $meeting, User $by, string $reason): void
    {
        abort_unless($meeting->isPublished(), 403);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reopenReason' => __('board.reopen.reason-required')]);
        }

        $meeting->update([
            'published_at' => null,
            'reopened_at' => now(),
            'reopened_by' => $by->id,
            'reopen_reason' => $reason,
        ]);

        $this->tell($meeting, BoardMeetingNotice::REOPENED, $reason);
    }

    /** Marks a follow-up done, or open again. */
    public function setFollowUpDone(BoardMeetingFollowUp $row, User $by, bool $done): void
    {
        abort_if($row->status === BoardMeetingFollowUp::CARRIED, 403);

        $row->update($done
            ? ['status' => BoardMeetingFollowUp::DONE, 'done_at' => now(), 'done_by' => $by->id]
            : ['status' => BoardMeetingFollowUp::OPEN, 'done_at' => null, 'done_by' => null]);
    }

    /** Carries an open row from an earlier meeting into this one's table; the old row points to its follow-on. */
    public function carryFollowUp(BoardMeetingFollowUp $row, BoardMeeting $into): BoardMeetingFollowUp
    {
        $this->assertOpen($into);
        abort_unless($row->isOpen() && $row->board_meeting_id !== $into->id, 403);

        return DB::transaction(function () use ($row, $into) {
            $row->update(['status' => BoardMeetingFollowUp::CARRIED]);

            return $into->followUps()->create([
                'topic' => $row->topic,
                'required' => $row->required,
                'party' => $row->party,
                'employee_id' => $row->employee_id,
                'carried_from_id' => $row->id,
                'status' => BoardMeetingFollowUp::OPEN,
                'sort' => ((int) $into->followUps()->max('sort')) + 1,
            ]);
        });
    }

    /**
     * Rows still open from earlier, published meetings: what the secretary closes or carries forward.
     *
     * @return Collection<int, BoardMeetingFollowUp>
     */
    public function openFromEarlier(BoardMeeting $meeting): Collection
    {
        return BoardMeetingFollowUp::query()
            ->where('status', BoardMeetingFollowUp::OPEN)
            ->where('board_meeting_id', '!=', $meeting->id)
            ->whereHas('meeting', fn ($q) => $q->whereNotNull('published_at')->where('starts_at', '<', $meeting->starts_at))
            ->with('meeting')
            ->orderBy('board_meeting_id')->orderBy('sort')
            ->get();
    }

    public function emailMinutes(BoardMeeting $meeting): int
    {
        $pdf = app(ContractPdfRenderer::class)->renderHtml(
            view('pdf.board-minutes', BoardMinutesView::data($meeting, inlineAssets: true))->render(),
        );

        $to = $this->access->currentMembers()->with('account')->get()
            ->map(fn (Employee $e) => $e->account?->email)->filter()->unique()->values();

        foreach ($to as $address) {
            Mail::to($address)->send(new BoardMinutesMail($meeting, $pdf));
        }

        return $to->count();
    }

    /** The chair's name, as the minutes print who is responsible for the meeting. */
    public function chairName(): ?string
    {
        return $this->access->holders(BoardAccess::MANAGE)
            ->filter(fn (User $u) => $u->hasExplicitPermission(BoardAccess::MANAGE))
            ->first()?->profile?->name;
    }

    /**
     * What the chair is offered when adding a meeting: the next usual slot, and the place of the last one.
     *
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, location: ?string, room: ?string}
     */
    public function suggestion(?CarbonImmutable $now = null): array
    {
        $slot = $this->defaultSlot($now ?? CarbonImmutable::now());

        // A week that already has its meeting (held, coming or cancelled: the chair decided that week) is not
        // offered again; the next free week is. A year ahead is far enough to stop looking.
        for ($i = 0; $i < 52 && $this->meetingInWeek($slot); $i++) {
            $slot = $slot->addWeek();
        }
        $last = BoardMeeting::query()->whereNotNull('location')->orderByDesc('starts_at')->first();

        return [
            'starts_at' => $slot,
            'ends_at' => $slot->addMinutes((int) config('board.duration_minutes', 90)),
            'location' => $last?->location ?? config('board.default_location'),
            'room' => $last?->room,
        ];
    }

    /** The meeting already set for the week of $at, cancelled ones included, if there is one. */
    public function meetingInWeek(CarbonImmutable $at): ?BoardMeeting
    {
        return BoardMeeting::query()
            ->whereBetween('starts_at', [$at->startOfWeek(), $at->endOfWeek()])
            ->orderBy('starts_at')
            ->first();
    }

    /** A meeting still standing (not cancelled) that starts in the same minute as $at. */
    public function meetingStartingAt(CarbonImmutable $at): ?BoardMeeting
    {
        return BoardMeeting::query()
            ->whereBetween('starts_at', [$at->startOfMinute(), $at->endOfMinute()])
            ->where('status', '!=', BoardMeeting::CANCELLED)
            ->first();
    }

    /**
     * A cancelled meeting's minutes are never published, so rows carried into it would drop out of
     * openFromEarlier(): each open copy is removed and its source is open again, offered to the next meeting.
     */
    private function returnCarriedIn(BoardMeeting $meeting): void
    {
        $meeting->followUps()
            ->whereNotNull('carried_from_id')
            ->where('status', BoardMeetingFollowUp::OPEN)
            ->with('carriedFrom')
            ->get()
            ->each(function (BoardMeetingFollowUp $copy) {
                $copy->carriedFrom?->update(['status' => BoardMeetingFollowUp::OPEN]);
                $copy->delete();
            });
    }

    /** Each board member named on a follow-up row hears once, when the minutes come out, what waits on them. */
    private function tellFollowUpOwners(BoardMeeting $meeting): void
    {
        $rows = $meeting->followUps()->whereNotNull('employee_id')->whereNull('notified_at')->with('employee.account')->get();
        $members = $this->access->currentMembers()->pluck('employees.id')->map(fn ($id) => (int) $id)->all();

        foreach ($rows as $row) {
            // Linked while on the board but no longer on it: the notice would point at minutes they cannot open.
            if (! in_array((int) $row->employee_id, $members, true)) {
                continue;
            }

            $row->employee?->account?->notify(BoardMeetingNotice::for(BoardMeetingNotice::FOLLOW_UP, $meeting, $row->topic));
            $row->update(['notified_at' => now()]);
        }
    }

    /** Once the minutes are published the meeting is closed: nothing about it changes any more. */
    private function assertOpen(BoardMeeting $meeting): void
    {
        abort_if($meeting->isPublished(), 403, __('board.minutes.final'));
    }

    /** Tells everyone the meeting concerns, except whoever just did it: they know. */
    private function tell(BoardMeeting $meeting, string $kind, ?string $detail = null): void
    {
        foreach ($this->access->audience()->reject(fn (User $u) => $u->id === auth()->id()) as $user) {
            $user->notify(BoardMeetingNotice::for($kind, $meeting, $detail));
        }
    }
}
