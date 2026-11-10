<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Enums\RecruitmentInterviewStatus;
use App\Enums\RecruitmentInterviewType;
use App\Models\RecruitmentInterview;
use App\Models\RecruitmentInterviewEvaluation;
use App\Models\Vacancy;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The interviews board: every interview the user may see, in the lane of
 * where it stands — coming up, awaiting evaluation, held (evaluation closed),
 * and cancelled or no-show.
 *
 * Every lane comes through RecruitmentInterview::visibleTo(), and filters
 * only narrow it. A card's round track shows the candidate's other rounds
 * only when those interviews are visible to the user too. Nobody's
 * evaluation is shown here except the user's own pending/submitted state,
 * and no meeting link or internal note ever reaches a card.
 */
class Board extends Component
{
    public const LANES = ['upcoming', 'awaiting', 'held', 'closed'];

    private const PAGE = 8;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $vacancy = '';

    #[Url(except: false)]
    public bool $mine = false;

    /**
     * How many cards each lane shows. Locked: only showMore() grows it, so a
     * tampered request cannot ask for an unbounded page.
     *
     * @var array<string, int>
     */
    #[Locked]
    public array $limits = [];

    /**
     * The lane shown on phones, where the lanes become tabs. Entangled with
     * Alpine without a request, so switching tabs costs no round trip, and
     * the server renders the right lane after any filter change.
     */
    public string $lane = 'upcoming';

    public function mount(): void
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);
        $this->resetLimits();
    }

    public function showMore(string $lane): void
    {
        if (in_array($lane, self::LANES, true)) {
            $this->limits[$lane] = min(($this->limits[$lane] ?? self::PAGE) + self::PAGE * 2, 500);
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'type', 'vacancy', 'mine');
        $this->resetLimits();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'vacancy', 'mine'], true)) {
            $this->resetLimits();
        }
    }

    public function render()
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);
        $user = auth()->user();

        if (! in_array($this->lane, self::LANES, true)) {
            $this->lane = 'upcoming';
        }

        $pendingIds = $this->filtered()->awaitingEvaluationBy($user)->pluck('recruitment_interviews.id')->all();

        $lanes = [];
        foreach (self::LANES as $lane) {
            $query = $this->laneQuery($lane, $pendingIds);
            $lanes[$lane] = [
                'count' => (clone $query)->count(),
                'items' => $query->with($this->relations())->limit($this->limits[$lane] ?? self::PAGE)->get(),
            ];
        }

        $shown = collect($lanes)->flatMap(fn (array $lane) => $lane['items']);
        $submittedIds = RecruitmentInterviewEvaluation::query()
            ->where('reviewer_user_id', $user->getKey())
            ->whereIn('interview_id', $lanes['awaiting']['items']->modelKeys())
            ->pluck('interview_id')->all();

        return view('livewire.recruitment.interviews.board', [
            'lanes' => $lanes,
            'pendingIds' => $pendingIds,
            'submittedIds' => $submittedIds,
            // Candidates with more than one card on the board get a thread.
            'threaded' => $shown->countBy('application_id')->filter(fn (int $count) => $count > 1)->keys()->all(),
            'vacancies' => $this->vacancyOptions(),
            'canFilterMine' => $user->hasActivePermission('recruitment-applicants.view'),
            'filtering' => $this->search !== '' || $this->type !== '' || $this->vacancy !== '' || $this->mine,
            'timezone' => config('app.timezone'),
        ]);
    }

    /** @param  array<int, int>  $pendingIds */
    private function laneQuery(string $lane, array $pendingIds): Builder
    {
        $query = $this->filtered();

        return match ($lane) {
            'upcoming' => $query->upcoming()->orderBy('scheduled_at'),
            'awaiting' => $query->heldOrPast()->evaluationsClosed(false)
                // The user's own pending evaluations first, then the most recent.
                ->when($pendingIds !== [], fn (Builder $q) => $q->orderByRaw(
                    'case when '.$q->getQuery()->getGrammar()->wrap($q->qualifyColumn('id')).' in ('.implode(',', array_map('intval', $pendingIds)).') then 0 else 1 end'))
                ->orderByDesc('scheduled_at'),
            'held' => $query->heldOrPast()->evaluationsClosed()->orderByDesc('scheduled_at'),
            'closed' => $query->whereIn('status', [RecruitmentInterviewStatus::Cancelled, RecruitmentInterviewStatus::NoShow])->orderByDesc('scheduled_at'),
        };
    }

    private function filtered(): Builder
    {
        $user = auth()->user();
        $search = trim($this->search);

        return RecruitmentInterview::query()
            ->visibleTo($user)
            ->when(in_array($this->type, RecruitmentInterviewType::values(), true), fn (Builder $q) => $q->where('interview_type', $this->type))
            ->when(ctype_digit($this->vacancy), fn (Builder $q) => $q->whereHas('application', fn (Builder $a) => $a->where('vacancy_id', (int) $this->vacancy)))
            ->when($this->mine, fn (Builder $q) => $q->whereHas('currentParticipants', fn (Builder $p) => $p->where('user_id', $user->getKey())))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';

                // Grouped, so the OR stays inside the application's own constraint.
                $q->whereHas('application', fn (Builder $a) => $a->where(fn (Builder $either) => $either
                    ->whereHas('applicant', fn (Builder $applicant) => $applicant->where('full_name', 'like', $like))
                    ->orWhereHas('vacancy.position', fn (Builder $position) => $position->where(transColumn('title'), 'like', $like))));
            });
    }

    /** @return array<int, string|Closure> */
    private function relations(): array
    {
        $user = auth()->user();

        return [
            'application.applicant',
            'application.vacancy.position',
            'currentParticipants.user.profile',
            // The candidate's other rounds — only the ones this user may see.
            'application.interviews' => fn ($q) => $q->visibleTo($user)
                ->select('id', 'application_id', 'interview_type', 'status', 'scheduled_at')
                ->orderBy('scheduled_at'),
        ];
    }

    /** Vacancies that have at least one interview this user can see. */
    private function vacancyOptions(): Collection
    {
        $user = auth()->user();

        return Vacancy::query()
            ->whereHas('applications.interviews', fn (Builder $q) => $q->visibleTo($user))
            ->with('position')
            ->get()
            ->mapWithKeys(fn (Vacancy $vacancy) => [$vacancy->getKey() => $vacancy->position?->{transColumn('title')} ?? '#'.$vacancy->getKey()])
            ->sort();
    }

    private function resetLimits(): void
    {
        $this->limits = array_fill_keys(self::LANES, self::PAGE);
    }
}
