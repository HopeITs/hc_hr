<?php

namespace App\Livewire\Board\Meetings;

use App\Livewire\Concerns\WithFeedback;
use App\Models\BoardMeeting;
use App\Models\BoardMeetingAttendee;
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

    /** 'view' reads the minutes as the printed sheet; 'edit' is the working page (the chair and the secretary only). */
    public string $mode = 'view';

    public function mount(BoardMeeting $meeting): void
    {
        abort_unless($this->access()->canView(auth()->user()), 403);

        $this->meetingId = $meeting->id;
        $this->service()->syncAttendees($meeting);
        $this->fillDetails($meeting);

        // Whoever writes the minutes starts on the working page until they are published, then on the sheet.
        $this->mode = ($this->access()->canWrite(auth()->user()) && ! $meeting->isPublished()) ? 'edit' : 'view';
    }

    /** Switch between the printed sheet and the working page. Only those who work on the meeting may edit. */
    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'edit' && ($this->access()->canWrite(auth()->user()) || $this->access()->canManage(auth()->user()))
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

        abort_unless($this->access()->isMember($user) && $meeting->isUpcoming(), 403);

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

        $this->service()->setAttendance($this->attendee($attendeeId), $status);
    }

    public function saveSection(): void
    {
        $this->mustWrite();

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

    public function saveFollowUp(): void
    {
        $this->mustWrite();

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
        $fields = ['topic' => $this->followUpTopic, 'required' => $this->followUpRequired ?: null, 'party' => $this->followUpParty ?: null];

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

    public function publish(): void
    {
        $this->mustWrite();

        $meeting = $this->meeting();
        abort_if($meeting->isCancelled(), 403);

        $this->service()->publish($meeting, auth()->user(), $this->sendEmail);
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
        $canEdit = $access->canWrite($user) || $access->canManage($user);
        // Readers see the minutes as the sheet. Writers see it too (a draft is marked as one) when they switch to it.
        $sheetMode = ! $meeting->isCancelled() && $minutesVisible && $this->mode === 'view';

        return view('livewire.board.meetings.show', [
            'sheetMode' => $sheetMode,
            'sheet' => $sheetMode ? BoardMinutesView::data($meeting) : null,
            'canEdit' => $canEdit,
            'meeting' => $meeting,
            'attendees' => $attendees,
            'mine' => $attendees->firstWhere('employee_id', $employeeId),
            'canManage' => $access->canManage($user),
            'canWrite' => $access->canWrite($user),
            'isMember' => $access->isMember($user),
            'minutesVisible' => $minutesVisible,
            'sections' => $meeting->sections()->get(),
            'followUps' => $meeting->followUps()->get(),
        ]);
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

    private function mustManage(): void
    {
        abort_unless($this->access()->canManage(auth()->user()), 403);
    }

    private function mustWrite(): void
    {
        abort_unless($this->access()->canWrite(auth()->user()), 403);
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
