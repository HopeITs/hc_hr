<?php

namespace App\Livewire\Onboarding;

use App\Models\Employee;
use App\Models\User;
use App\Services\OnboardingJourneys;
use Illuminate\View\View;
use Livewire\Component;

/**
 * "My first days": the new volunteer's own onboarding journey, from their own
 * account. The same steps HR follows (OnboardingJourneys::steps), worded for
 * the person they are about: what each step is, who does it, when, and the
 * one or two that ask something of them. It only ever reads the signed-in
 * user's own employee, and never shows HR's internal notes (why a step is
 * stuck is HR's to fix, not the volunteer's).
 */
class MyFirstDays extends Component
{
    /** How long the page stays in the menu once everything is done. */
    public const MENU_DAYS_AFTER_DONE = 14;

    /** Shown to the volunteer: the record is HR's paperwork, not a step of theirs. */
    private const HIDDEN_STEPS = ['record'];

    public function mount(): void
    {
        abort_unless(auth()->user()?->employee_id !== null, 403);
    }

    /**
     * Whether "My first days" belongs in this user's menu: a journey still
     * open, or finished in the last two weeks. Staff who never had one (hired
     * before the journey existed) never see it.
     */
    public static function inMenuFor(User $user): bool
    {
        $journey = $user->profile?->onboardingJourney;

        return $journey !== null
            && ($journey->completed_at === null || $journey->completed_at->gt(now()->subDays(self::MENU_DAYS_AFTER_DONE)));
    }

    public function render(OnboardingJourneys $journeys): View
    {
        /** @var Employee|null $employee */
        $employee = auth()->user()->profile;
        abort_unless($employee !== null, 403);

        $steps = collect($journeys->steps($employee) ?? [])
            ->reject(fn (array $step) => in_array($step['key'], self::HIDDEN_STEPS, true))
            ->map(fn (array $step) => $step + ['view' => $this->forVolunteer($step)])
            ->values();

        return view('livewire.onboarding.my-first-days', [
            'journey' => $employee->onboardingJourney,
            'steps' => $steps,
            'done' => $steps->where('state', OnboardingJourneys::STATE_DONE)->count(),
            'yours' => $steps->filter(fn (array $step) => $step['view']['yours'])->values(),
        ])->title(__('onboarding.first-days.title'));
    }

    /**
     * The step as the volunteer reads it: a status (icon + words), and when it
     * is theirs, where to do it.
     *
     * @param  array<string, mixed>  $step
     * @return array{tone: string, icon: string, label: string, yours: bool, action: ?array{label: string, url: string}, date: ?\Carbon\CarbonInterface, date_label: ?string}
     */
    private function forVolunteer(array $step): array
    {
        $state = $step['state'];
        $yours = $state === OnboardingJourneys::STATE_TODO && $step['owner'] === 'volunteer';

        [$tone, $icon, $label] = match (true) {
            $state === OnboardingJourneys::STATE_DONE => ['done', 'check-circle', __('onboarding.first-days.state.done')],
            $yours => ['attn', 'alert', __('onboarding.first-days.state.yours')],
            $state === OnboardingJourneys::STATE_WAITING => ['wait', 'clock', __('onboarding.first-days.state.after-contract')],
            // A step stuck on something (no items set up, no IT in the city…) is HR's to fix: the volunteer sees who follows it, not why.
            default => ['wait', 'clock', __('onboarding.first-days.state.with-'.($step['owner'] === 'volunteer' ? 'hr' : $step['owner']))],
        };

        $action = match (true) {
            $yours && in_array($step['key'], ['policies', 'guideline'], true) => [
                'label' => __('onboarding.first-days.action.'.$step['key']),
                'url' => route('onboarding.policies'),
            ],
            default => null,
        };

        // A session with a date set says when; anything else not done says by when it is expected.
        $scheduled = in_array($step['key'], ['orientation', 'manager_orientation'], true) ? ($step['data']['scheduled_for'] ?? null) : null;
        $isDone = $state === OnboardingJourneys::STATE_DONE;

        return [
            'tone' => $tone,
            'icon' => $icon,
            'label' => $label,
            'yours' => $yours,
            'action' => $action,
            'date' => $isDone ? null : ($scheduled ?? $step['due']),
            'date_label' => $isDone ? null : ($scheduled ? __('onboarding.first-days.scheduled') : ($step['due'] ? __('onboarding.first-days.expected-by') : null)),
        ];
    }
}
