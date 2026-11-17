<?php

namespace App\Services\Recruitment\Privacy;

/**
 * An export that passed every check and has been audited. The ids are exactly
 * the rows that will be written — the audited count is not an estimate.
 */
final readonly class PreparedExport
{
    /**
     * @param  array<int, int>  $ids  application ids, already inside the user's scope
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public array $ids,
        public array $filters,
    ) {}

    public function count(): int
    {
        return count($this->ids);
    }

    public function filename(): string
    {
        return 'recruitment-applicants-'.now()->format('Ymd-His').'.csv';
    }
}
