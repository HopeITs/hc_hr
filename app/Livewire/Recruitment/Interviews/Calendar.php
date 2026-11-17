<?php

namespace App\Livewire\Recruitment\Interviews;

use App\Enums\RecruitmentInterviewStatus;
use App\Models\RecruitmentApplication;
use App\Models\RecruitmentInterview;
use App\Services\RecruitmentInterviewSchedulingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Phase 2 Task 7: a month grid of the interviews the user may see, through
 * the same RecruitmentInterview::visibleTo() query as the list.
 *
 * Times are shown in the application timezone, which every interview is
 * entered in. The month comes from the query string as Y-m; anything else
 * falls back to the current month rather than erroring.
 */
class Calendar extends Component
{
    #[Url]
    public string $month = '';

    #[Url]
    public bool $showCancelled = false;

    #[Locked]
    public ?string $selectedDate = null;

    public ?string $campaignId = null;

    public ?string $applicationId = null;

    public function startScheduling(string $date): void
    {
        $this->authorizeScheduling();
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']])->validate();
        $this->selectedDate = $date;
        $this->reset('campaignId', 'applicationId');
        $this->resetErrorBag();
    }

    public function updatedCampaignId(): void
    {
        $this->reset('applicationId');
    }

    public function cancelScheduling(): void
    {
        $this->reset('selectedDate', 'campaignId', 'applicationId');
        $this->resetErrorBag();
    }

    public function continueScheduling(): void
    {
        $this->authorizeScheduling();
        $this->validate([
            'selectedDate' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'campaignId' => ['required', 'integer'],
            'applicationId' => ['required', 'integer'],
        ]);
        $application = RecruitmentApplication::query()->visibleTo(auth()->user())
            ->where('campaign_id', $this->campaignId)
            ->whereIn('status', RecruitmentInterviewSchedulingService::SCHEDULABLE)
            ->findOrFail($this->applicationId);
        Gate::authorize('schedule', [RecruitmentInterview::class, $application]);

        $this->redirectRoute('recruitment.interviews.create', [
            'applicationId' => $application->id,
            'date' => $this->selectedDate,
            'from_calendar' => 1,
        ], navigate: true);
    }

    public function mount(): void
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);

        $this->month = $this->firstOfMonth()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->firstOfMonth()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->firstOfMonth()->addMonthNoOverflow()->format('Y-m');
    }

    public function thisMonth(): void
    {
        $this->month = now()->format('Y-m');
    }

    public function render()
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);

        $first = $this->firstOfMonth();
        $options = $this->schedulingOptions();
        // Weeks start on Saturday, as the working week does here.
        $gridStart = $first->copy()->startOfWeek(Carbon::SATURDAY);
        $gridEnd = $first->copy()->endOfMonth()->endOfWeek(Carbon::FRIDAY);

        $interviews = RecruitmentInterview::query()
            ->visibleTo(auth()->user())
            ->whereBetween('scheduled_at', [$gridStart, $gridEnd])
            ->when(! $this->showCancelled, fn ($query) => $query->where('status', '!=', RecruitmentInterviewStatus::Cancelled))
            ->with('application.applicant', 'application.campaign.vacancy.position')
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (RecruitmentInterview $interview) => $interview->scheduled_at->toDateString());

        $weeks = [];
        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay()) {
            $weeks[intdiv((int) round($gridStart->diffInDays($day)), 7)][] = [
                'date' => $day->copy(),
                'inMonth' => $day->month === $first->month,
                'isToday' => $day->isToday(),
                'interviews' => $interviews->get($day->toDateString(), collect()),
            ];
        }

        return view('livewire.recruitment.interviews.calendar', [
            'first' => $first,
            'weeks' => $weeks,
            'weekdays' => collect(range(0, 6))->map(fn (int $offset) => $gridStart->copy()->addDays($offset)->translatedFormat('D')),
            'timezone' => config('app.timezone'),
            'canSchedule' => $this->canSchedule(),
            'schedulingOptions' => $options,
        ]);
    }

    private function authorizeScheduling(): void
    {
        Gate::authorize('viewAny', RecruitmentInterview::class);
        abort_unless($this->canSchedule(), 403);
    }

    private function canSchedule(): bool
    {
        return auth()->user()->hasActivePermission('recruitment-applicants.schedule-interviews')
            && auth()->user()->hasActivePermission('recruitment-applicants.view');
    }

    private function schedulingOptions(): Collection
    {
        if ($this->selectedDate === null || ! $this->canSchedule()) {
            return collect();
        }

        return RecruitmentApplication::query()->visibleTo(auth()->user())
            ->whereIn('status', RecruitmentInterviewSchedulingService::SCHEDULABLE)
            ->with('applicant', 'campaign.vacancy.position')->get()
            ->filter(fn (RecruitmentApplication $application): bool => Gate::allows('schedule', [RecruitmentInterview::class, $application]));
    }

    private function firstOfMonth(): Carbon
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1) {
            return Carbon::createFromFormat('!Y-m', $this->month)->startOfMonth();
        }

        return now()->startOfMonth();
    }
}
