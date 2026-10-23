<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * B3: lazy (measured ~50 queries for 5 rows: avatar/name/city/department accessors
 * resolved per row) and independently useful, so it must not hold up the page.
 * Pay figures are never loaded here.
 */
#[Lazy]
class SoonEndingContractsCard extends Component
{
    use HandlesLoadFailure;

    public function placeholder()
    {
        return view('livewire.dashboard.card-placeholder', ['title' => __('general.soon-ending-contracts')]);
    }

    public function render()
    {
        $contracts = $this->guardedLoad(fn () => $this->contracts(), fallback: new Collection);

        return view('livewire.dashboard.soon-ending-contracts-card', [
            'contracts' => $contracts,
        ]);
    }

    private function contracts(): Collection
    {
        $today = Carbon::today();
        $limit = $today->copy()->addDays(30);

        $contracts = Contract::query()
            ->where('is_active', true)
            ->whereNull('terminated_at')
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $limit)
            ->with(['employee.media', 'employee.city', 'position.department'])
            ->orderBy('end_date', 'asc')
            ->limit(5)
            ->get();

        $contracts->each(function ($contract) {
            $end = Carbon::parse($contract->end_date)->startOfDay();
            $contract->days_left = Carbon::today()->diffInDays($end, false);

            $employee = $contract->employee;

            $contract->employee_name = $employee?->name ?? 'N/A';
            $contract->city_name = $employee?->city?->name ?? 'N/A';
            $contract->department_title = $contract->position?->department?->name ?? 'N/A';
            $contract->position_title = $contract->position?->title ?? '';
            // photo_url is not an attribute on Employee; this silently resolved
            // to null and always fell through to the third-party avatar
            // service, leaking employee names to ui-avatars.com on every
            // dashboard render.
            $contract->employee_photo = $employee?->avatar_url ?? asset('images/default-avatar.png');
            $contract->view_route = route('contracts.show', ['contract' => $contract->id]);
        });

        return $contracts;
    }
}
