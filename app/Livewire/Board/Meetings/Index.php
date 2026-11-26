<?php

namespace App\Livewire\Board\Meetings;

use App\Livewire\Concerns\WithFeedback;
use App\Models\BoardMeeting;
use App\Services\Board\BoardAccess;
use App\Services\Board\BoardMeetingService;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * The board's meetings: the one coming up first, the earlier ones below. The chair adds an extra meeting
 * (when decisions cannot wait) from here; the usual weekly one is opened by the scheduler.
 */
class Index extends Component
{
    use WithFeedback;

    public bool $adding = false;

    public string $startsAt = '';

    public string $endsAt = '';

    public string $location = '';

    public string $room = '';

    public string $purpose = '';

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
        $this->adding = true;
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

        $meeting = app(BoardMeetingService::class)->schedule([
            'starts_at' => CarbonImmutable::parse($this->startsAt),
            'ends_at' => $this->endsAt !== '' ? CarbonImmutable::parse($this->endsAt) : null,
            'location' => $this->location ?: null,
            'room' => $this->room ?: null,
            'purpose' => $this->purpose ?: null,
        ], auth()->user());

        $this->reset(['adding', 'startsAt', 'endsAt', 'location', 'room', 'purpose']);
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
        $upcoming = BoardMeeting::query()->where('starts_at', '>=', now())->whereNull('published_at')->orderBy('starts_at')->get();
        $past = BoardMeeting::query()
            ->where(fn ($q) => $q->where('starts_at', '<', now())->orWhereNotNull('published_at'))
            ->orderByDesc('starts_at')->limit(50)->get();

        return view('livewire.board.meetings.index', [
            'upcoming' => $upcoming,
            'past' => $past,
            'canManage' => $access->canManage($user),
            'seeDraft' => $seeDraft,
        ]);
    }
}
