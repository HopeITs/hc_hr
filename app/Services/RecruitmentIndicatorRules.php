<?php

namespace App\Services;

use App\Models\RecruitmentIndicatorSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The matching-indicator rules in force: config('recruitment.indicators') with
 * whatever HR has saved laid over it.
 *
 * Only the overrides are stored, so a rule nobody touched keeps following the
 * config, and "reset" is just deleting the row. Because the effective rules
 * are what RecruitmentMatchingIndicators hashes into its criteria version, a
 * saved change starts a new version by itself and old snapshots stay
 * explainable.
 */
class RecruitmentIndicatorRules
{
    public const AUDIT_LOG = 'recruitment-indicators';

    /** @var array<string, mixed>|null */
    private ?array $overrides = null;

    public function __construct(private readonly ActivityLogger $activity) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = config('recruitment.indicators', []);

        foreach (['experience_ranges', 'qualification_keywords'] as $key) {
            if (isset($this->overrides()[$key])) {
                $rules[$key] = array_replace($rules[$key] ?? [], $this->overrides()[$key]);
            }
        }

        foreach (['default_required_experience_years', 'default_required_qualification', 'disabled'] as $key) {
            if (array_key_exists($key, $this->overrides())) {
                $rules[$key] = $this->overrides()[$key];
            }
        }

        return $rules;
    }

    /** One rule by dotted path, e.g. `education_ranks` or `experience_ranges.1_3`. */
    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->rules(), $path, $default);
    }

    public function isEnabled(string $indicatorKey): bool
    {
        return ! in_array($indicatorKey, (array) $this->get('disabled', []), true);
    }

    public function hasOverrides(): bool
    {
        return $this->overrides() !== [];
    }

    /**
     * @param  array{
     *     disabled?: array<int, string>,
     *     default_required_experience_years?: int|string|null,
     *     default_required_qualification?: string|null,
     *     experience_ranges?: array<string, array{min?: mixed, max?: mixed}>,
     *     qualification_keywords?: array<string, array<int, string>>,
     * }  $input
     */
    public function save(User $actor, array $input): void
    {
        $clean = $this->validated($input);
        $before = $this->overrides();

        DB::transaction(function () use ($actor, $clean): void {
            $row = RecruitmentIndicatorSetting::query()->lockForUpdate()->first() ?? new RecruitmentIndicatorSetting;
            $row->forceFill(['overrides_json' => $clean, 'updated_by_user_id' => $actor->getKey()])->save();
        });

        $this->overrides = $clean;
        $this->log('recruitment_indicator_rules.updated', $actor, ['before' => $before, 'after' => $clean]);
    }

    public function reset(User $actor): void
    {
        $before = $this->overrides();

        RecruitmentIndicatorSetting::query()->delete();
        $this->overrides = [];

        $this->log('recruitment_indicator_rules.reset', $actor, ['before' => $before]);
    }

    // --------------------------------------------

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        return $this->overrides ??= (array) RecruitmentIndicatorSetting::query()->value('overrides_json');
    }

    /** @return array<string, mixed> */
    private function validated(array $input): array
    {
        $errors = [];
        $config = config('recruitment.indicators', []);

        // Anything the caller leaves out keeps its current value.
        $current = $this->rules();
        $input += [
            'disabled' => $current['disabled'] ?? [],
            'default_required_experience_years' => $current['default_required_experience_years'] ?? null,
            'default_required_qualification' => $current['default_required_qualification'] ?? null,
        ];
        $levels = array_keys($config['education_ranks'] ?? []);

        $disabled = array_values(array_unique(array_map('strval', (array) ($input['disabled'] ?? []))));
        if (array_diff($disabled, RecruitmentMatchingIndicators::KEYS) !== []) {
            $errors['disabled'] = __('recruitment-indicator-rules.errors.unknown_indicator');
        }

        $years = $input['default_required_experience_years'] ?? null;
        $years = ($years === '' || $years === null) ? null : $years;
        if ($years !== null && (! is_numeric($years) || (int) $years != $years || $years < 0 || $years > 50)) {
            $errors['default_required_experience_years'] = __('recruitment-indicator-rules.errors.years');
        }

        $level = ($input['default_required_qualification'] ?? '') === '' ? null : $input['default_required_qualification'];
        if ($level !== null && ! in_array($level, $levels, true)) {
            $errors['default_required_qualification'] = __('recruitment-indicator-rules.errors.level');
        }

        $ranges = [];
        foreach (array_keys($config['experience_ranges'] ?? []) as $option) {
            $min = $input['experience_ranges'][$option]['min'] ?? $current['experience_ranges'][$option][0] ?? null;
            $max = $input['experience_ranges'][$option]['max'] ?? $current['experience_ranges'][$option][1] ?? null;
            $max = $max === '' ? null : $max;

            $valid = is_numeric($min) && (int) $min == $min && $min >= 0 && $min <= 50
                && ($max === null || (is_numeric($max) && (int) $max == $max && $max >= $min && $max <= 50));

            if (! $valid) {
                $errors['experience_ranges.'.$option] = __('recruitment-indicator-rules.errors.range');

                continue;
            }

            $ranges[$option] = [(int) $min, $max === null ? null : (int) $max];
        }

        $keywords = [];
        foreach ($levels as $levelKey) {
            $list = collect((array) ($input['qualification_keywords'][$levelKey] ?? $current['qualification_keywords'][$levelKey] ?? []))
                ->map(fn ($keyword): string => trim((string) $keyword))
                ->filter()
                ->unique()
                ->values();

            if ($list->count() > 30 || $list->contains(fn (string $keyword): bool => mb_strlen($keyword) > 60)) {
                $errors['qualification_keywords.'.$levelKey] = __('recruitment-indicator-rules.errors.keywords');

                continue;
            }

            $keywords[$levelKey] = $list->all();
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'disabled' => $disabled,
            'default_required_experience_years' => $years === null ? null : (int) $years,
            'default_required_qualification' => $level,
            'experience_ranges' => $ranges,
            'qualification_keywords' => $keywords,
        ];
    }

    private function log(string $event, User $actor, array $properties): void
    {
        $this->activity->log($event, causer: $actor, properties: $properties + ['role' => $actor->myRole()], logName: self::AUDIT_LOG);
    }
}
