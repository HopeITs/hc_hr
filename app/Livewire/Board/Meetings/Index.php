<?php

namespace App\Livewire\Board\Meetings;

use App\Livewire\Concerns\WithFeedback;
use App\Models\BoardMeeting;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardMeetingService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The board's meetings: the one coming up first, the earlier ones below. The chair adds an extra meeting
 * (when decisions cannot wait) from here; the usual weekly one is opened by the scheduler.
 */
class Index extends Component
{
    use WithFeedback;

    public bool $adding = false;

    /** Words looked for in the minutes (sections, follow-up rows, purpose). */
    #[\Livewire\Attributes\Url(as: 'q', except: '')]
    public string $search = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $location = '';

    public string $room = '';

    public string $purpose = '';

    /**
     * The week of the chosen date already has a meeting: the chair is asked once whether this one is an extra
     * (urgent) meeting before it is added. Holds that meeting's number and date, or null.
     *
     * @var array{number: int, date: string, cancelled: bool}|null
     */
    public ?array $sameWeek = null;

    /** The chair said yes to an extra meeting in that week. */
    public bool $sameWeekConfirmed = false;

    /** An old meeting was brought in: redraw the lists. */
    protected $listeners = ['board-archive-imported' => '$refresh'];

    public function mount(): void
    {
        abort_unless(app(BoardAccess::class)->canView(auth()->user()), 403);
    }

    /** Opens the form with what the chair would type anyway: the next usual slot and the last meeting's place. */
    public function openAdd(): void
    {
        abort_unless(app(BoardAccess::class)->canManage(auth()->user()), 403);

        $s = app(BoardMeetingService::class)->suggestion();
        $this->startsAt = $s['starts_at']->format('Y-m-d\TH:i');
        $this->endsAt = $s['ends_at']->format('Y-m-d\TH:i');
        $this->location = (string) $s['location'];
        $this->room = (string) $s['room'];
        $this->purpose = '';
        $this->resetSameWeek();
        $this->adding = true;
    }

    /** Another date is another question: the earlier yes was for that week only. */
    public function updatedStartsAt(): void
    {
        $this->resetSameWeek();
    }

    /** "Yes, add it as an extra meeting." */
    public function confirmSameWeek(): void
    {
        $this->sameWeekConfirmed = true;
        $this->saveMeeting();
    }

    public function saveMeeting(): void
    {
        abort_unless(app(BoardAccess::class)->canManage(auth()->user()), 403);

        $this->validate([
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'location' => ['nullable', 'string', 'max:255'],
            'room' => ['nullable', 'string', 'max:60'],
            'purpose' => ['nullable', 'string', 'max:2000'],
        ]);

        $service = app(BoardMeetingService::class);
        $startsAt = CarbonImmutable::parse($this->startsAt);

        // Two meetings at the same minute are one meeting typed twice.
        if ($taken = $service->meetingStartingAt($startsAt)) {
            $this->resetSameWeek();

            throw ValidationException::withMessages([
                'startsAt' => __('board.same-start', ['number' => $taken->number]),
            ]);
        }

        // An extra meeting in a week that has one is possible, but only once the chair says so.
        if (! $this->sameWeekConfirmed && ($other = $service->meetingInWeek($startsAt))) {
            $this->sameWeek = [
                'number' => $other->number,
                'date' => $other->starts_at->translatedFormat('l j F Y H:i'),
                'cancelled' => $other->isCancelled(),
            ];

            return;
        }

        $meeting = $service->schedule([
            'starts_at' => $startsAt,
            'ends_at' => $this->endsAt !== '' ? CarbonImmutable::parse($this->endsAt) : null,
            'location' => $this->location ?: null,
            'room' => $this->room ?: null,
            'purpose' => $this->purpose ?: null,
        ], auth()->user());

        $this->reset(['adding', 'startsAt', 'endsAt', 'location', 'room', 'purpose', 'sameWeek', 'sameWeekConfirmed']);
        $this->toast(__('board.saved'));

        $this->redirectRoute('board.meetings.show', ['meeting' => $meeting->id], navigate: true);
    }

    public function render()
    {
        $access = app(BoardAccess::class);
        $user = auth()->user();
        $seeDraft = $access->canSeeDraft($user);

        // A meeting is "coming up" until it starts; one that has started, or whose minutes are published, is "earlier"
        // (today's meeting must not hide among the coming ones once its minutes are out).
        // The meetings still on lead; a cancelled one stays listed (with its reason) but after them.
        $upcoming = BoardMeeting::query()->where('starts_at', '>=', now())->whereNull('published_at')
            ->orderByRaw('case when status = ? then 1 else 0 end', [BoardMeeting::CANCELLED])
            ->orderBy('starts_at')->get();
        $past = BoardMeeting::query()
            ->where(fn ($q) => $q->where('starts_at', '<', now())->orWhereNotNull('published_at'))
            ->orderByDesc('starts_at')->limit(50)->get();

        $found = null;
        $term = trim($this->search);

        if (mb_strlen($term) >= 2) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $found = BoardMeeting::query()
                // Members search what they may read: published minutes. The chair and the secretary search drafts too.
                ->when(! $seeDraft, fn ($q) => $q->whereNotNull('published_at'))
                ->where(fn ($q) => $q->where('purpose', 'like', $like)
                    ->orWhere('archive_text', 'like', $like)
                    ->orWhereHas('sections', fn ($s) => $s->where('title', 'like', $like)->orWhere('body', 'like', $like))
                    ->orWhereHas('followUps', fn ($f) => $f->where('topic', 'like', $like)->orWhere('required', 'like', $like)->orWhere('party', 'like', $like)))
                ->orderByDesc('starts_at')->limit(30)->get();
        }

        return view('livewire.board.meetings.index', [
            'found' => $found,
            'upcoming' => $upcoming,
            'past' => $past,
            'canManage' => $access->canManage($user),
            'seeDraft' => $seeDraft,
            'canImport' => $access->canWrite($user),
        ]);
    }

    private function resetSameWeek(): void
    {
        $this->sameWeek = null;
        $this->sameWeekConfirmed = false;
    }
}
