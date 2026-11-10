<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\HandlesLoadFailure;
use App\Services\Statistics\ContractStatsCards;
use App\Support\Dashboard\DashboardLayout;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One card of the contract statistics page on the dashboard (key
 * "cstat.<program>.<center>.<city>", DashboardLayout::contractStatKey): a
 * program's card, or a center's within a program, in one city or every
 * city. The page's own rule (statistics.view and the active role's cities,
 * DashboardLayout::allowed) is asked again on every render; the figures come
 * from the same ContractStatsCards the page uses.
 */
class ContractStatsCard extends Component
{
    use HandlesLoadFailure;

    #[Locked]
    public int $programId = 0;

    #[Locked]
    public int $centerId = 0;

    #[Locked]
    public int $cityId = 0;

    public function placeholder(array $params = []): View
    {
        $key = DashboardLayout::contractStatKey((int) ($params['programId'] ?? 0), (int) ($params['centerId'] ?? 0), (int) ($params['cityId'] ?? 0));

        return view('livewire.dashboard.card-placeholder', ['title' => app(DashboardLayout::class)->label($key)]);
    }

    public function render()
    {
        $user = Auth::user();
        $tiles = app(DashboardLayout::class);
        $key = DashboardLayout::contractStatKey($this->programId, $this->centerId, $this->cityId);
        $allowed = $user && $tiles->allowed($user, $key);
        $card = null;

        if ($allowed) {
            [$program, $center] = $tiles->contractStatModels($key);
            $stats = app(ContractStatsCards::class);
            $city = $this->cityId ?: null;

            $card = $this->guardedLoad(fn () => [
                'name' => $center?->name ?? $program->name,
                'program' => $program,
                'center' => $center,
            ] + ($center
                ? $stats->center($center->id, $program->id, $city)
                : $stats->program($program->id, $city)));
        }

        return view('livewire.dashboard.contract-stats-card', [
            'card' => $card,
            'label' => $allowed ? $tiles->label($key) : '',
            'cardType' => $this->centerId ? 'center' : 'program',
            'allowed' => $allowed,
        ]);
    }
}
