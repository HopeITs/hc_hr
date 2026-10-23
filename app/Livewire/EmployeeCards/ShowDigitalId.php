<?php

namespace App\Livewire\EmployeeCards;

use App\Models\Employee;
use App\Services\DigitalIdManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The public digital-ID card, /ID/{card}.
 *
 * Only the card code (already in the URL) is component state. The employee is
 * resolved again on every render, so a Livewire request replayed after the
 * card was re-issued or disabled cannot render it either.
 *
 * Anything that is not a current, enabled card of an active employee gets the
 * same bilingual "card is not valid" page with the same 404 and no personal
 * data, so the page cannot be used to test whether an id exists.
 */
#[Layout('components.layouts.public')]
class ShowDigitalId extends Component
{
    #[Locked]
    public string $card = '';

    public function boot(): void
    {
        App::setLocale('en');
    }

    public function mount(string $card): void
    {
        $this->card = $card;
    }

    public function render()
    {
        $manager = app(DigitalIdManager::class);
        $employee = $manager->resolve($this->card);

        if (! $employee) {
            abort(response()->view('livewire.employee-cards.digital-id-not-valid', [], 404));
        }

        $position = $manager->cardContract($employee)?->position;
        $city = $employee->city;
        $emergencyPhone = $this->emergencyPhone($employee);
        $firstName = $employee->first_name_en;
        $lastName = $employee->last_name_en;

        return view('livewire.employee-cards.show-digital-id', [
            'employee' => $employee,
            // The card is unauthenticated, so the internal avatar route would
            // 403 here. The card has just resolved, which is what authorizes
            // this short-lived signed link.
            'avatarSrc' => $employee->signedAvatarUrl(),
            'employeeName' => trim($firstName.' '.$lastName),
            'firstName' => $firstName,
            'lastName' => $lastName,
            'position' => $position,
            'positionTitle' => $position?->title_en,
            'center' => $position?->center,
            'city' => $city,
            'cityName' => $city?->name_en,
            'emergencyPhone' => $emergencyPhone,
            'emergencyPhoneHref' => $this->phoneHref($emergencyPhone),
            'phoneHref' => $this->phoneHref($employee->work_phone),
            'telegramHref' => $this->telegramHref($employee->telegram_work),
            'linkedinHref' => $this->linkedinHref($employee->linkedin),
        ]);
    }

    /**
     * The city's HR hotline set by HR (A2D-Q7), or, while none is set, the
     * first active HR-department employee's work phone in that city.
     */
    private function emergencyPhone(Employee $employee): ?string
    {
        $hotline = trim((string) $employee->city?->hr_hotline);

        return $hotline !== '' ? $hotline : $this->findEmergencyPhone($employee->city_id);
    }

    private function findEmergencyPhone(?int $cityId): ?string
    {
        if (! $cityId) {
            return null;
        }

        return Employee::query()
            ->where('is_active', true)
            ->where('city_id', $cityId)
            ->whereNotNull('work_phone')
            ->where('work_phone', '<>', '')
            ->whereHas('contracts.position.department', fn (Builder $department) => $department->where('code', 'HR'))
            ->orderBy('id')
            ->value('work_phone');
    }

    private function phoneHref(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        return 'tel:'.preg_replace('/[^\d+]/', '', $phone);
    }

    private function telegramHref(?string $telegram): ?string
    {
        $telegram = trim((string) $telegram);

        if ($telegram === '') {
            return null;
        }

        if (str_starts_with($telegram, 'http://') || str_starts_with($telegram, 'https://')) {
            return $telegram;
        }

        return 'https://t.me/'.ltrim($telegram, '@');
    }

    private function linkedinHref(?string $linkedin): ?string
    {
        $linkedin = trim((string) $linkedin);

        if ($linkedin === '') {
            return null;
        }

        if (str_starts_with($linkedin, 'http://') || str_starts_with($linkedin, 'https://')) {
            return $linkedin;
        }

        return 'https://www.linkedin.com/in/'.trim($linkedin, '/');
    }
}
