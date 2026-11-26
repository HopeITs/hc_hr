<?php

namespace App\Livewire\Board\Meetings;

use App\Livewire\Concerns\WithFeedback;
use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;
use App\Models\BoardMeetingFollowUp;
use App\Models\Employee;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardMeetingService;
use App\Services\Board\BoardMinutesView;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One board meeting. Everyone on the board sees the date, who is expected and (once published) the minutes;
 * a member may ask to miss it. The chair moves or cancels it and decides those requests; the secretary
 * keeps attendance, writes the minutes (free narrative sections and the follow-up table) and publishes them.
 */
class Show extends Component
{
    use WithFeedback;

    #[Locked]
    public int $meetingId;

    // Details (the chair).
    public string $startsAt = '';

    public string $endsAt = '';

    public string $location = '';

    public string $room = '';

    public string $purpose = '';

    public string $ledBy = '';

    public string $cancelReason = '';

    // A member's own request.
    public string $absenceReason = '';

    // Minutes (the secretary).
    public ?int $sectionId = null;

    public string $sectionTitle = '';

    public string $sectionBody = '';

    public ?int $followUpId = null;

    public string $followUpTopic = '';

    public string $followUpRequired = '';

    public string $followUpParty = '';

    public string $preparedBy = '';

    public bool $sendEmail = false;

    /** Why the chair takes published minutes back (kept on the meeting). */
    public string $reopenReason = '';

    /** 'view' reads the minutes as the printed sheet; 'edit' is the working page (the chair and the secretary only). */
    public string $mode = 'view';

    public function mount(BoardMeeting $meeting): void
    {
        abort_unless($this->access()->canView(auth()->user()), 403);

        $this->meetingId = $meeting->id;
        $this->service()->syncAttendees($meeting);
        $this->fillDetails($meeting);

        $user = auth()->user();
        $worksOnIt = $this->access()->canWrite($user) || $this->access()->canManage($user);

        // The chair and the secretary start on the working page (absence requests, attendance, the minutes) until
        // the minutes are published; then everyone reads the sheet.
        $this->mode = $worksOnIt && ! $meeting->isPublished() ? 'edit' : 'view';

        // The secretary writing from his own account is the one who prepares the minutes: his name, not typed.
        if ($this->preparedBy === '' && $this->access()->canWrite($user)) {
            $this->preparedBy = (string) $user->profile?->name;
        }
    }

    /** Switch between the printed sheet and the working page. Only those who work on the meeting may edit. */
    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'edit' && ! $this->meeting()->isPublished()
            && ($this->access()->canWrite(auth()->user()) || $this->access()->canManage(auth()->user()))
            ? 'edit'
            : 'view';
    }

    public function saveDetails(): void
    {
        $this->mustManage();

        $this->validate([
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'location' => ['nullable', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:60'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'ledBy' => ['nullable', 'string', 'max:255'],
        ]);

        $this->service()->update($this->meeting(), [
            'starts_at' => CarbonImmutable::parse($this->startsAt),
            'ends_at' => $this->endsAt !== '' ? CarbonImmutable::parse($this->endsAt) : null,
            'location' => $this->location ?: null,
            'room' => $this->room ?: null,
            'purpose' => $this->purpose ?: null,
            'led_by' => $this->ledBy ?: null,
        ]);

        $this->toast(__('board.saved'));
    }

    public function cancelMeeting(): void
    {
        $this->mustManage();

        $this->validate(['cancelReason' => ['required', 'string', 'max:2000']], [], ['cancelReason' => __('board.cancel.reason')]);

        $this->service()->cancel($this->meeting(), $this->cancelReason);
        $this->cancelReason = '';
        $this->toast(__('board.cancel.done'));
    }

    public function restoreMeeting(): void
    {
        $this->mustManage();

        $this->service()->restore($this->meeting());
        $this->toast(__('board.saved'));
    }

    /** A member asks the chair to excuse them (self only; the meeting must still be ahead). */
    public function requestAbsence(): void
    {
        $user = auth()->user();
        $meeting = $this->meeting();

        abort_unless($this->access()->isMember($user) && $meeting->isUpcoming() && ! $meeting->isPublished(), 403);

        $this->validate(['absenceReason' => ['required', 'string', 'max:2000']], [], ['absenceReason' => __('board.absence.reason')]);

        $this->service()->requestAbsence($meeting, $user->profile, $this->absenceReason);
        $this->absenceReason = '';
        $this->toast(__('board.absence.sent'));
    }

    public function decideAbsence(int $attendeeId, bool $approve): void
    {
        $this->mustManage();

        $this->service()->decideAbsence($this->attendee($attendeeId), $approve);
        $this->toast(__('board.saved'));
    }

    public function setAttendance(int $attendeeId, string $status): void
    {
        $this->mustWrite();
        $this->claimPreparer();

        $this->service()->setAttendance($this->attendee($attendeeId), $status);
    }

    public function saveSection(): void
    {
        $this->mustWrite();
        $this->claimPreparer();

        $this->validate([
            'sectionTitle' => ['required', 'string', 'max:255'],
            'sectionBody' => ['nullable', 'string', 'max:60000'],
        ], [], ['sectionTitle' => __('board.minutes.section-title'), 'sectionBody' => __('board.minutes.section-body')]);

        $meeting = $this->meeting();

        if ($this->sectionId) {
            $section = $meeting->sections()->findOrFail($this->sectionId);
            $section->update(['title' => $this->sectionTitle, 'body' => $this->sectionBody]);
        } else {
            $meeting->sections()->create([
                'title' => $this->sectionTitle,
                'body' => $this->sectionBody,
                'sort' => ((int) $meeting->sections()->max('sort')) + 1,
            ]);
        }

        $this->reset(['sectionId', 'sectionTitle', 'sectionBody']);
        $this->dispatch('board-section-saved');
        $this->toast(__('board.saved'));
    }

    public function editSection(int $id): void
    {
        $this->mustWrite();

        $section = $this->meeting()->sections()->findOrFail($id);
        $this->sectionId = $section->id;
        $this->sectionTitle = $section->title;
        $this->sectionBody = (string) $section->body;
    }

    public function removeSection(int $id): void
    {
        $this->mustWrite();

        $this->meeting()->sections()->whereKey($id)->delete();
        $this->reset(['sectionId', 'sectionTitle', 'sectionBody']);
    }

    /** While the secretary types into a section already saved, the text is kept as it goes (no lost paragraphs). */
    public function updatedSectionBody(): void
    {
        if (! $this->sectionId) {
            return;
        }

        $this->mustWrite();

        $this->meeting()->sections()->whereKey($this->sectionId)->update(['body' => $this->sectionBody]);
    }

    /** Moves a section up (-1) or down (1); the sheet numbers them in this order. */
    public function moveSection(int $id, int $step): void
    {
        $this->mustWrite();

        $sections = $this->meeting()->sections()->get()->values();
        $at = $sections->search(fn ($s) => $s->id === $id);
        $to = $at === false ? false : $at + ($step < 0 ? -1 : 1);

        if ($at === false || $to < 0 || $to >= $sections->count()) {
            return;
        }

        $order = $sections->all();
        [$order[$at], $order[$to]] = [$order[$to], $order[$at]];

        foreach (array_values($order) as $i => $section) {
            $section->update(['sort' => $i + 1]);
        }
    }

    /** Fills the section title with one of the usual ones (config board.section_templates). */
    public function useTemplate(int $index): void
    {
        $this->mustWrite();

        $this->sectionTitle = (string) (config('board.section_templates')[$index] ?? $this->sectionTitle);
    }

    public function saveFollowUp(): void
    {
        $this->mustWrite();
        $this->claimPreparer();

        $this->validate([
            'followUpTopic' => ['required', 'string', 'max:255'],
            'followUpRequired' => ['nullable', 'string', 'max:2000'],
            'followUpParty' => ['nullable', 'string', 'max:255'],
        ], [], [
            'followUpTopic' => __('board.minutes.follow-topic'),
            'followUpRequired' => __('board.minutes.follow-required'),
            'followUpParty' => __('board.minutes.follow-party'),
        ]);

        $meeting = $this->meeting();
        $party = trim($this->followUpParty);
        $fields = [
            'topic' => $this->followUpTopic,
            'required' => $this->followUpRequired ?: null,
            'party' => $party ?: null,
            // A name picked from the list is that employee (told when the minutes come out); anything else is a
            // department or a body outside, kept as written.
            'employee_id' => $party !== '' ? $this->employeeNamed($party) : null,
        ];

        if ($this->followUpId) {
            $meeting->followUps()->findOrFail($this->followUpId)->update($fields);
        } else {
            $meeting->followUps()->create($fields + ['sort' => ((int) $meeting->followUps()->max('sort')) + 1]);
        }

        $this->reset(['followUpId', 'followUpTopic', 'followUpRequired', 'followUpParty']);
        $this->toast(__('board.saved'));
    }

    public function editFollowUp(int $id): void
    {
        $this->mustWrite();

        $row = $this->meeting()->followUps()->findOrFail($id);
        $this->followUpId = $row->id;
        $this->followUpTopic = $row->topic;
        $this->followUpRequired = (string) $row->required;
        $this->followUpParty = (string) $row->party;
    }

    public function removeFollowUp(int $id): void
    {
        $this->mustWrite();

        $this->meeting()->followUps()->whereKey($id)->delete();
        $this->reset(['followUpId', 'followUpTopic', 'followUpRequired', 'followUpParty']);
    }

    /** Who prepared the minutes, as it prints at the foot of the sheet. */
    public function saveMinutesHeader(): void
    {
        $this->mustWrite();

        $this->validate(['preparedBy' => ['nullable', 'string', 'max:255']]);

        $this->meeting()->update(['prepared_by' => $this->preparedBy ?: null]);
        $this->toast(__('board.saved'));
    }

    /** A member takes back the request the chair has not answered yet. */
    public function withdrawAbsence(): void
    {
        $user = auth()->user();
        abort_unless($this->access()->isMember($user), 403);

        $this->service()->withdrawAbsence($this->meeting(), $user->profile);
        $this->toast(__('board.absence.withdrawn'));
    }

    /** The chair alone takes published minutes back to a draft, saying why. */
    public function reopenMinutes(): void
    {
        abort_unless($this->access()->canManage(auth()->user()), 403);

        $this->validate(['reopenReason' => ['required', 'string', 'max:2000']], [], ['reopenReason' => __('board.reopen.reason')]);

        $this->service()->reopen($this->meeting(), auth()->user(), $this->reopenReason);
        $this->reopenReason = '';
        $this->mode = 'edit';
        $this->toast(__('board.reopen.done'));
    }

    /** Done / open again: the secretary, the chair, or the person the row waits on. */
    public function markFollowUp(int $id, bool $done): void
    {
        $row = BoardMeetingFollowUp::query()->findOrFail($id);
        $user = auth()->user();

        abort_unless(
            $this->access()->canWrite($user) || $this->access()->canManage($user)
                || ($row->employee_id !== null && $row->employee_id === $user->employee_id),
            403,
        );

        $this->service()->setFollowUpDone($row, $user, $done);
    }

    /** Brings an open row from an earlier meeting into this meeting's table. */
    public function carryFollowUp(int $id): void
    {
        $this->mustWrite();

        $this->service()->carryFollowUp(BoardMeetingFollowUp::query()->findOrFail($id), $this->meeting());
        $this->toast(__('board.follow.carried'));
    }

    public function publish(): void
    {
        $this->mustWrite();

        $meeting = $this->meeting();
        abort_if($meeting->isCancelled(), 403);

        $this->service()->publish($meeting, auth()->user(), $this->sendEmail);
        // Published is final: the page turns into the sheet at once, nothing editable left on it.
        $this->mode = 'view';
        $this->toast($this->sendEmail ? __('board.minutes.published-emailed') : __('board.minutes.published'));
        $this->sendEmail = false;
    }

    public function render()
    {
        $meeting = $this->meeting();
        $user = auth()->user();
        $access = $this->access();
        $seeDraft = $access->canSeeDraft($user);
        $employeeId = $user->employee_id;
        $attendees = $meeting->attendees()->with('employee')->get()
            ->sortBy(fn (BoardMeetingAttendee $a) => mb_strtolower((string) $a->employee?->name))->values();

        $minutesVisible = $meeting->isPublished() || $seeDraft;
        // Published minutes are final: nobody edits them (or the meeting) afterwards.
        $canEdit = ! $meeting->isPublished() && ($access->canWrite($user) || $access->canManage($user));
        // Readers see the minutes as the sheet. Writers see it too (a draft is marked as one) when they switch to it.
        $sheetMode = ! $meeting->isCancelled() && $minutesVisible && $this->mode === 'view';

        return view('livewire.board.meetings.show', [
            'sheetMode' => $sheetMode,
            'editLabel' => $access->canWrite($user) ? __('board.minutes.edit-mode') : __('board.manage-mode'),
            'sheet' => $sheetMode ? BoardMinutesView::data($meeting) : null,
            'canEdit' => $canEdit,
            'meeting' => $meeting,
            'attendees' => $attendees,
            'mine' => $attendees->firstWhere('employee_id', $employeeId),
            'canManage' => ! $meeting->isPublished() && $access->canManage($user),
            'canWrite' => ! $meeting->isPublished() && $access->canWrite($user),
            'isMember' => $access->isMember($user),
            'minutesVisible' => $minutesVisible,
            'sections' => $meeting->sections()->get(),
            'followUps' => $meeting->followUps()->with('employee')->get(),
            // What the secretary closes or carries forward while writing this meeting's minutes.
            'earlier' => $canEdit && $access->canWrite($user) ? $this->service()->openFromEarlier($meeting) : collect(),
            'employeeNames' => $canEdit && $access->canWrite($user) && $this->mode === 'edit' ? $this->employeeNames() : [],
            'canReopen' => $meeting->isPublished() && $access->canManage($user),
            'canTrack' => $access->canWrite($user) || $access->canManage($user),
            'templates' => (array) config('board.section_templates', []),
        ]);
    }

    /** @return array<int, string> active employees' names, for the follow-up's "person or body" list */
    private function employeeNames(): array
    {
        return Employee::query()->where('is_active', true)->get()
            ->mapWithKeys(fn (Employee $e) => [$e->id => (string) $e->name])
            ->filter()->sort()->all();
    }

    /** The one active employee with exactly this name; two people sharing it link nobody (kept as written). */
    private function employeeNamed(string $name): ?int
    {
        $ids = array_keys($this->employeeNames(), $name, true);

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    private function fillDetails(BoardMeeting $meeting): void
    {
        $this->startsAt = $meeting->starts_at->format('Y-m-d\TH:i');
        $this->endsAt = $meeting->ends_at?->format('Y-m-d\TH:i') ?? '';
        $this->location = (string) $meeting->location;
        $this->room = (string) $meeting->room;
        $this->purpose = (string) $meeting->purpose;
        $this->ledBy = (string) $meeting->led_by;
        $this->preparedBy = (string) $meeting->prepared_by;
    }

    private function meeting(): BoardMeeting
    {
        return BoardMeeting::query()->findOrFail($this->meetingId);
    }

    private function attendee(int $id): BoardMeetingAttendee
    {
        return BoardMeetingAttendee::query()->where('board_meeting_id', $this->meetingId)->findOrFail($id);
    }

    /** The chair's actions: his permission, and never once the minutes are published. */
    private function mustManage(): void
    {
        abort_unless($this->access()->canManage(auth()->user()), 403);
        $this->mustBeOpen();
    }

    /** The secretary's actions: his permission, and never once the minutes are published. */
    private function mustWrite(): void
    {
        abort_unless($this->access()->canWrite(auth()->user()), 403);
        $this->mustBeOpen();
    }

    /** The first thing the secretary writes records him as the one who prepares the minutes. */
    private function claimPreparer(): void
    {
        $meeting = $this->meeting();

        if (blank($meeting->prepared_by) && ($name = auth()->user()?->profile?->name)) {
            $meeting->update(['prepared_by' => $name]);
            $this->preparedBy = $name;
        }
    }

    /** Publishing closes the meeting: its details, attendance and minutes are not changed afterwards. */
    private function mustBeOpen(): void
    {
        abort_if($this->meeting()->isPublished(), 403);
    }

    private function access(): BoardAccess
    {
        return app(BoardAccess::class);
    }

    private function service(): BoardMeetingService
    {
        return app(BoardMeetingService::class);
    }
}
