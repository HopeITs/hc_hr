<?php

namespace App\Services\Board;

use App\Mail\BoardMinutesMail;
use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\BoardMeetingNotice;
use App\Services\ContractPdfRenderer;
use Carbon\CarbonImmutable;
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
        $meeting = DB::transaction(function () use ($data, $by) {
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
        $thisWeek = BoardMeeting::query()
            ->whereBetween('starts_at', [$weekStart, $weekStart->endOfWeek()])
            ->exists();

        if ($thisWeek) {
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

        $moved = ! $meeting->starts_at->equalTo(CarbonImmutable::parse($data['starts_at']));

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

        $meeting->update(['status' => BoardMeeting::CANCELLED, 'cancel_reason' => $reason]);
        $this->tell($meeting, BoardMeetingNotice::CANCELLED, $reason);
    }

    public function restore(BoardMeeting $meeting): void
    {
        $meeting->update(['status' => BoardMeeting::SCHEDULED, 'cancel_reason' => null, 'reminded_at' => null]);
        $this->tell($meeting, BoardMeetingNotice::RESCHEDULED);
    }

    /** Every current member has a row; a member who joined after the meeting was opened gets one on the next call. */
    public function syncAttendees(BoardMeeting $meeting): void
    {
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

    /** The chair approves (the member is listed as absent) or turns the request down. */
    public function decideAbsence(BoardMeetingAttendee $attendee, bool $approve): void
    {
        $this->assertOpen($attendee->meeting);

        $attendee->update([
            'absence_request' => $approve ? BoardMeetingAttendee::REQUEST_APPROVED : BoardMeetingAttendee::REQUEST_REJECTED,
            'absence_decided_at' => now(),
            'status' => $approve ? BoardMeetingAttendee::ABSENT : $attendee->status,
        ]);

        $attendee->loadMissing('employee.account', 'meeting');

        $attendee->employee?->account?->notify(BoardMeetingNotice::for(
            BoardMeetingNotice::ABSENCE_DECIDED,
            $attendee->meeting,
            $approve ? 'approved / تمت الموافقة' : 'not approved / لم تتم الموافقة',
        ));
    }

    /** Publishes the minutes: the members are told, and (when asked) get them by email with the PDF. */
    public function publish(BoardMeeting $meeting, User $by, bool $email = false): void
    {
        $this->assertOpen($meeting);

        $meeting->update([
            'published_at' => now(),
            'prepared_by' => $meeting->prepared_by ?: $by->profile?->name,
        ]);

        $this->tell($meeting, BoardMeetingNotice::MINUTES);

        if ($email) {
            $this->emailMinutes($meeting);
        }
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
        $last = BoardMeeting::query()->whereNotNull('location')->orderByDesc('starts_at')->first();

        return [
            'starts_at' => $slot,
            'ends_at' => $slot->addMinutes((int) config('board.duration_minutes', 90)),
            'location' => $last?->location ?? config('board.default_location'),
            'room' => $last?->room,
        ];
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
