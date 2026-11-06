<?php

namespace App\Livewire\Recruitment\Indicators;

use App\Services\RecruitmentIndicatorRules;
use App\Services\RecruitmentMatchingIndicators;
use App\Traits\WithAlerts;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * HR's editor for the matching-indicator rules: which criteria are on, the
 * minimum each one falls back to, and how answers and words are read.
 *
 * The values shown are the effective rules (config plus saved changes), and
 * what is saved is validated and audited by RecruitmentIndicatorRules.
 */
class Rules extends Component
{
    use WithAlerts;

    /** @var array<string, bool> indicator key => switched on */
    public array $enabled = [];

    public string $defaultYears = '';

    public string $defaultLevel = '';

    /** @var array<string, array{min: string, max: string}> */
    public array $ranges = [];

    /** @var array<string, string> level => comma-separated words */
    public array $keywords = [];

    /**
     * The values as last loaded, so the page can list unsaved changes
     * without a round trip.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $saved = [];

    public function mount(RecruitmentIndicatorRules $rules): void
    {
        $this->authorizeManage();
        $this->load($rules);
    }

    public function save(RecruitmentIndicatorRules $rules): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        try {
            $rules->save(auth()->user(), [
                'disabled' => array_keys(array_filter($this->enabled, fn ($on): bool => ! $on)),
                'default_required_experience_years' => $this->defaultYears,
                'default_required_qualification' => $this->defaultLevel,
                'experience_ranges' => $this->ranges,
                'qualification_keywords' => array_map(
                    fn (string $words): array => preg_split('/[,،\n]+/u', $words) ?: [],
                    $this->keywords,
                ),
            ]);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($this->componentField($field), $messages[0]);
            }

            return;
        }

        $this->load($rules);
        $this->successToast(__('recruitment-indicator-rules.saved', ['version' => app(RecruitmentMatchingIndicators::class)->criteriaVersion()]));
    }

    public function resetToDefaults(RecruitmentIndicatorRules $rules): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        $rules->reset(auth()->user());
        $this->load($rules);
        $this->successToast(__('recruitment-indicator-rules.reset_done'));
    }

    public function render(RecruitmentIndicatorRules $rules, RecruitmentMatchingIndicators $indicators)
    {
        $ranks = $rules->get('education_ranks', []);
        asort($ranks);

        return view('livewire.recruitment.indicators.rules', [
            'indicatorKeys' => RecruitmentMatchingIndicators::KEYS,
            'levels' => array_keys($ranks),
            'ranks' => $ranks,
            'customised' => $rules->hasOverrides(),
            'version' => $indicators->criteriaVersion(),
        ]);
    }

    private function load(RecruitmentIndicatorRules $rules): void
    {
        $this->enabled = collect(RecruitmentMatchingIndicators::KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $rules->isEnabled($key)])
            ->all();

        $this->defaultYears = (string) ($rules->get('default_required_experience_years') ?? '');
        $this->defaultLevel = (string) ($rules->get('default_required_qualification') ?? '');

        $this->ranges = collect($rules->get('experience_ranges', []))
            ->map(fn (array $range): array => ['min' => (string) $range[0], 'max' => (string) ($range[1] ?? '')])
            ->all();

        $this->keywords = collect($rules->get('qualification_keywords', []))
            ->only(array_keys($rules->get('education_ranks', [])))
            ->map(fn ($words): string => implode(', ', (array) $words))
            ->all();

        $this->saved = [
            'enabled' => $this->enabled,
            'defaultYears' => $this->defaultYears,
            'defaultLevel' => $this->defaultLevel,
            'ranges' => $this->ranges,
            'keywords' => $this->keywords,
        ];
    }

    /** Service errors are keyed by rule; the form is keyed by property. */
    private function componentField(string $field): string
    {
        return match (true) {
            $field === 'default_required_experience_years' => 'defaultYears',
            $field === 'default_required_qualification' => 'defaultLevel',
            str_starts_with($field, 'experience_ranges.') => 'ranges.'.substr($field, 18),
            str_starts_with($field, 'qualification_keywords.') => 'keywords.'.substr($field, 23),
            default => $field,
        };
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()?->hasActivePermission('recruitment-indicators.manage'), 403);
    }
}
