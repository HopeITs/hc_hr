<?php

namespace App\Livewire\Employee\Leave;

use App\Livewire\Concerns\WithFeedback;
use App\Models\BirthdayHolidayChoice;
use App\Models\Holiday;
use App\Services\BirthdayHoliday;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * The volunteer's birthday holiday and, for a direct manager, the moved days
 * waiting for them (team-coverage-contract §6.1, H-4). Renders nothing when
 * there is neither.
 */
class BirthdayHolidayCard extends Component
{
    use WithFeedback;

    /** @var array<string, string|null> chosen date per "holidayId:year" */
    public array $picked = [];

    public function render(BirthdayHoliday $birthdays)
    {
        $user = auth()->user();
        $contract = $user?->profile?->latestActiveContract();
        $today = CarbonImmutable::today();

        $own = $contract
            ? collect($birthdays->occurrencesFor($contract, $today->subDays(7), $today->addYear()))
            : collect();

        return view('livewire.employee.leave.birthday-holiday-card', [
            'own' => $own,
            'team' => $user ? $birthdays->pendingFor($user) : collect(),
        ]);
    }

    public function choose(string $key, BirthdayHoliday $birthdays): void
    {
        [$holidayId, $year] = array_map('intval', explode(':', $key, 2));
        $contract = auth()->user()?->profile?->latestActiveContract();
        abort_unless($contract, 403);

        $this->validate(["picked.{$key}" => 'required|date']);

        $birthdays->choose($contract, Holiday::findOrFail($holidayId), $year, CarbonImmutable::parse($this->picked[$key])->startOfDay());

        $this->toast();
    }

    public function decide(int $choiceId, bool $approve, BirthdayHoliday $birthdays): void
    {
        $birthdays->decide(auth()->user(), BirthdayHolidayChoice::findOrFail($choiceId), $approve);

        $this->toast();
    }
}
