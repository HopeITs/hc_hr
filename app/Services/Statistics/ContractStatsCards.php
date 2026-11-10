<?php

namespace App\Services\Statistics;

use App\Enums\ContractPlace;
use App\Enums\ContractType as ContractTypeEnum;
use App\Models\Contract;
use App\Models\ContractType;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures of one card on the contract statistics page: a program (in one
 * city or all of them) or a center within a program (in one city or all).
 * Shared by the page (Statistics\ContractStatistics) and its dashboard tile
 * (Dashboard\ContractStatsCard), so both always count the same contracts.
 * Who may ask for which city is the caller's job (activeCityIds()).
 */
class ContractStatsCards
{
    /** @var array<string, list<int>> contract type ids per category, for this request */
    private array $types = [];

    /**
     * A program's card: centers it runs in, employees and the breakdown.
     *
     * @return array{centers: int, employees: int, annual: array, service: array, trial: array}
     */
    public function program(int $programId, ?int $cityId): array
    {
        $positions = Position::where('program_id', $programId)
            ->when($cityId, fn ($q) => $q->where('city_id', $cityId));

        $contracts = Contract::where('is_active', true)
            ->whereHas('position', fn ($q) => $q
                ->where('program_id', $programId)
                ->when($cityId, fn ($q) => $q->where('city_id', $cityId)));

        return ['centers' => (clone $positions)->whereNotNull('center_id')->distinct('center_id')->count()]
            + $this->breakdown($contracts);
    }

    /**
     * A center's card within a program.
     *
     * @return array{centers: null, employees: int, annual: array, service: array, trial: array}
     */
    public function center(int $centerId, int $programId, ?int $cityId): array
    {
        $contracts = Contract::where('is_active', true)
            ->whereHas('position', fn ($q) => $q
                ->where('center_id', $centerId)
                ->where('program_id', $programId)
                ->when($cityId, fn ($q) => $q->where('city_id', $cityId)));

        return ['centers' => null] + $this->breakdown($contracts);
    }

    /** @return array{employees: int, annual: array, service: array, trial: array} */
    private function breakdown(Builder $contracts): array
    {
        return [
            'employees' => (clone $contracts)->distinct('employee_id')->count('employee_id'),
            'annual' => [
                'full' => $this->count($contracts, 'annual', 'type', ContractTypeEnum::FullTime),
                'part' => $this->count($contracts, 'annual', 'type', ContractTypeEnum::PartTime),
            ],
            'service' => [
                'online' => $this->count($contracts, 'service', 'place', ContractPlace::Online),
                'onsite' => $this->count($contracts, 'service', 'place', ContractPlace::Onsite),
            ],
            'trial' => [
                'full' => $this->count($contracts, 'trial', 'type', ContractTypeEnum::FullTime),
                'part' => $this->count($contracts, 'trial', 'type', ContractTypeEnum::PartTime),
            ],
        ];
    }

    /**
     * Contracts of a category (config('contracts.map_by_id'), via
     * ContractType::category: the classification used for leave accrual, PDF
     * export and renewal elsewhere), optionally with one column's value.
     */
    private function count(Builder $contracts, string $category, string $column, int $value): int
    {
        $this->types[$category] ??= ContractType::all()
            ->filter(fn (ContractType $type): bool => $type->category === $category)
            ->pluck('id')
            ->all();

        return (clone $contracts)
            ->whereIn('contract_type_id', $this->types[$category])
            ->where($column, $value)
            ->count();
    }
}
