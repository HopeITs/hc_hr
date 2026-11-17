<?php

namespace App\Support\Recruitment;

use App\Enums\RecruitmentIndicatorOutcome;

/**
 * One indicator, with everything needed to explain it.
 *
 * `reason` is a translation key and `params` its replacements, so the same
 * result reads correctly in either language and can be stored as data.
 * `evidence` lists what was compared — the applicant's side and the
 * vacancy's side — exactly as the screen shows it.
 */
final readonly class MatchingIndicator
{
    /**
     * @param  array<string, scalar|null>  $params
     * @param  array<string, scalar|null>  $evidence
     */
    public function __construct(
        public string $key,
        public string $outcome,
        public string $reason,
        public array $params = [],
        public array $evidence = [],
        public bool $scored = true,
    ) {}

    public function isAssessed(): bool
    {
        return $this->scored && RecruitmentIndicatorOutcome::isAssessed($this->outcome);
    }

    public function isMet(): bool
    {
        return $this->scored && $this->outcome === RecruitmentIndicatorOutcome::Met;
    }

    public function label(): string
    {
        return __('recruitment-applicant.indicators.names.'.$this->key);
    }

    public function explanation(): string
    {
        return __('recruitment-applicant.indicators.reasons.'.$this->reason, $this->params);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'params' => $this->params,
            'evidence' => $this->evidence,
            'scored' => $this->scored,
        ];
    }
}
