<?php

namespace App\Services\Inbox;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * One kind of employee journey (onboarding, renewal, exit) as a work-inbox
 * source (DEC-09 increment 2; approval-source-contract.md §3, §5). A journey
 * row lists, it never decides: the item opens the employee edit page, where
 * the journey's own panel and service act and re-authorize.
 *
 * What every source shares, and a subclass cannot loosen:
 *
 *  - who: HR (or super-admin) whose active role holds employees.update, the
 *    EmployeePolicy::update rule; each listed row is re-checked by that policy;
 *  - scope: the journey's employee is active and in the user's active cities
 *    (Employee::scopeInActiveCities), added here to the subclass's query, so
 *    the count and the list are the same rows (G-C-7);
 *  - cost: one count query and one list query per source, with what the row
 *    shows eager-loaded by the subclass; nothing is read per row;
 *  - privacy: the row carries the name, the city, the steps, the due date and
 *    the Late flag. No pay, no contract terms, no national id (§3.2).
 *
 * A subclass names itself, selects the unfinished journeys that wait on HR,
 * and says, from the loaded model alone, which steps wait and when they are due.
 * docs/ux/journey-inbox-implementation.md §3 shows how to add one.
 */
abstract class JourneyInboxSource
{
    /** A step HR acts on now. */
    public const STATE_TODO = 'todo';

    /** A step that cannot finish without a fix elsewhere (shown as a problem). */
    public const STATE_PROBLEM = 'problem';

    /**
     * The source's name: the key prefix ("onboarding:12:journey") and the
     * strings' group (journey_inbox.{name}.*).
     */
    abstract public function name(): string;

    /** The same gate as the other HR-only sources (S5, D1): HR role + employees.update. */
    public function visibleTo(User $user): bool
    {
        $role = (string) $user->myRole();

        return ($role === 'super-admin' || str_starts_with($role, 'HR-'))
            && $user->hasActivePermission('employees.update');
    }

    /**
     * The listed rows (at most $limit) and the exact count of every row it
     * would list.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: int}
     */
    public function collect(User $user, int $limit): array
    {
        if (! $this->visibleTo($user)) {
            return [collect(), 0];
        }

        // EmployeePolicy::update's scope in SQL, so the count is exact; the
        // policy re-checks what is listed. Former staff show only where the
        // source asks for them (includesFormerStaff).
        $query = $this->pending()->whereHas('employee', fn (Builder $employee) => $employee
            ->when(! $this->includesFormerStaff(), fn (Builder $active) => $active->where('is_active', true))
            ->inActiveCities($user));

        $rows = (clone $query)
            ->limit($limit)
            ->get()
            ->filter(fn (Model $journey) => $journey->employee instanceof Employee
                && Gate::forUser($user)->allows('update', $journey->employee))
            ->map(fn (Model $journey) => $this->row($journey))
            // A query that selected a journey with nothing waiting would list an empty row.
            ->filter(fn (array $row) => $row['steps'] !== [])
            ->values();

        return [$rows, $query->count()];
    }

    /**
     * The unfinished journeys with at least one step waiting on HR: a query
     * on the journey table, with an `employee` relation, eager-loading what
     * steps()/due()/late() read. No city or active filter: the base adds those.
     */
    abstract protected function pending(): Builder;

    /**
     * The steps waiting on HR, in journey order, read from the loaded model only.
     *
     * @return list<array{key: string, state: string}>
     */
    abstract protected function steps(Model $journey): array;

    /** The date the waiting work is due, if it has one. */
    abstract protected function due(Model $journey): ?CarbonInterface;

    /** Whether the waiting work is overdue (shown with the Late tag). */
    abstract protected function late(Model $journey): bool;

    /**
     * Whether rows stay listed once the employee is inactive. Only the exit
     * journey needs it: equipment still has to come back after deactivation.
     */
    protected function includesFormerStaff(): bool
    {
        return false;
    }

    /** Since when the row has waited (the inbox sorts on it, oldest first). */
    protected function waitingSince(Model $journey): ?CarbonInterface
    {
        return $journey->getAttribute('started_at') ?? $journey->getAttribute('created_at');
    }

    /** @return array<string, mixed> */
    protected function row(Model $journey): array
    {
        $name = $this->name();
        $employee = $journey->employee;
        $steps = array_map(fn (array $step) => $step + [
            'label' => __("journey_inbox.{$name}.step.{$step['key']}"),
        ], $this->steps($journey));
        $late = $this->late($journey);

        return [
            'key' => "{$name}:{$journey->getKey()}:journey",
            'source' => $name,
            'action' => 'journey',
            'id' => $journey->getKey(),
            'subject' => $employee,
            'former' => false,
            // The row's own words, for every place that names an inbox row.
            'label' => __("journey_inbox.{$name}.title"),
            'item' => collect($steps)->pluck('label')->implode(' · '),
            'steps' => $steps,
            'due' => $this->due($journey),
            'late' => $late,
            'url' => route('employees.edit', $employee),
            'city' => $employee->city?->name,
            'waiting_since' => $this->waitingSince($journey),
            'urgent' => false,
            'version' => null,
            'bulk' => false,
        ];
    }
}
