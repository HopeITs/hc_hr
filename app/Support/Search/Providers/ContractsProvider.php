<?php

namespace App\Support\Search\Providers;

use App\Enums\ContractType;
use App\Models\Contract;
use App\Models\User;
use App\Support\Search\NameMatch;
use App\Support\Search\SearchProvider;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contracts by the employee's name, or by the contract's own number. The rows come through
 * Contract::visibleTo (a manager's direct reports, a city HR's city, head office everything), and
 * only for roles that hold contracts.view. Pay is never selected: a contract line shows the
 * person, the position and the dates, nothing else.
 */
final class ContractsProvider implements SearchProvider
{
    public function allows(User $user): bool
    {
        return $user->hasActivePermission('contracts.view');
    }

    public function search(User $user, string $term, int $limit): array
    {
        $number = ctype_digit(trim($term)) ? (int) trim($term) : null;

        return Contract::query()
            ->visibleTo($user)
            ->where('contracts.type', '!=', ContractType::Draft)
            ->where(function (Builder $match) use ($term, $number): void {
                $match->whereHas('employee', fn (Builder $employee) => NameMatch::employees($employee, $term));

                if ($number !== null) {
                    $match->orWhere('contracts.id', $number);
                }
            })
            ->select(['contracts.id', 'contracts.employee_id', 'contracts.position_id', 'contracts.start_date', 'contracts.end_date', 'contracts.terminated_at'])
            ->with([
                'employee:id,first_name_en,first_name_ar,last_name_en,last_name_ar,username',
                'position:id,title_en,title_ar',
            ])
            ->orderByDesc('contracts.end_date')
            ->limit($limit)
            ->get()
            ->map(function (Contract $contract): array {
                $ended = $contract->terminated_at !== null || $contract->end_date?->isPast();
                $actions = [];

                if ($contract->employee?->username) {
                    $actions[] = ['kind' => 'link', 'label' => __('smart_search.actions.open-employee'), 'href' => route('employees.show', $contract->employee->username)];
                }

                return SearchResult::make(
                    'contracts',
                    $contract->id,
                    (string) ($contract->employee?->name ?? '—'),
                    SearchResult::line(
                        '#'.$contract->id,
                        $contract->position?->title,
                        $contract->end_date ? __($ended ? 'smart_search.ended-on' : 'smart_search.ends-on', ['date' => $contract->end_date->format('d/m/Y')]) : null,
                    ),
                    route('contracts.show', $contract->id),
                    $actions,
                );
            })
            ->all();
    }
}
