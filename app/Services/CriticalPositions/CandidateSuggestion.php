<?php

namespace App\Services\CriticalPositions;

/**
 * One suggested replacement. Reasons are translation keys + params so the same
 * suggestion reads in either language. A suggestion is advice only: it never
 * creates a promotion or a contract.
 */
final readonly class CandidateSuggestion
{
    public const INTERNAL = 'internal';

    public const CV = 'cv';

    /**
     * @param  list<array{key: string, params?: array<string, scalar|null>}>  $reasons
     */
    public function __construct(
        public string $source,
        public int $id,
        public string $name,
        public ?int $score,
        public array $reasons,
        public ?string $url = null,
        public ?string $detail = null,
    ) {}

    /** @return list<string> */
    public function reasonTexts(): array
    {
        return array_map(
            fn (array $reason): string => __($reason['key'], $reason['params'] ?? []),
            $this->reasons,
        );
    }
}
