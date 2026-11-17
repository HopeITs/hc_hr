<?php

namespace App\Support\Recruitment;

/**
 * All indicators for one application, and the criteria version behind them.
 *
 * The summary is a count of criteria met out of those that could be
 * assessed, never a weighted score: each part of it is one row the reviewer
 * can read.
 */
final readonly class MatchingIndicatorSet
{
    /** @param  array<int, MatchingIndicator>  $indicators */
    public function __construct(
        public string $criteriaVersion,
        public array $indicators,
    ) {}

    public function metCount(): int
    {
        return count(array_filter($this->indicators, fn (MatchingIndicator $indicator): bool => $indicator->isMet()));
    }

    public function assessedCount(): int
    {
        return count(array_filter($this->indicators, fn (MatchingIndicator $indicator): bool => $indicator->isAssessed()));
    }

    /** Scored indicators that could not be assessed for missing data. */
    public function unassessedCount(): int
    {
        return count(array_filter(
            $this->indicators,
            fn (MatchingIndicator $indicator): bool => $indicator->scored && ! $indicator->isAssessed(),
        ));
    }

    public function get(string $key): ?MatchingIndicator
    {
        foreach ($this->indicators as $indicator) {
            if ($indicator->key === $key) {
                return $indicator;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(fn (MatchingIndicator $indicator): array => $indicator->toArray(), $this->indicators);
    }
}
