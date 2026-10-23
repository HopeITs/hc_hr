<?php

namespace App\Livewire\EmployeeCards;

use App\Livewire\Concerns\WithFeedback;
use App\Models\Employee;
use App\Models\User;
use App\Services\DigitalIdException;
use App\Services\DigitalIdManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The digital-ID card panel on the employee profile: the current card link
 * (HR copies it into the print tool, the app has no QR library), which card is
 * current, whether it is enabled, and the actions the viewer may take.
 *
 * A2D-Q5 (access-policy.md §9): HR for the employee's city, HR-SYR and
 * super-admin re-issue, disable and enable; the employee reports their own card
 * lost and HR acts. Every action authorizes again against the active role of
 * the request it runs in (digital-id.* gates, DigitalIdPolicy), so a role
 * switched since the page loaded is what counts, not the buttons rendered.
 */
class ManageDigitalId extends Component
{
    use WithFeedback;

    /** One report per card per hour is enough to reach HR. */
    private const REPORT_DECAY_SECONDS = 3600;

    #[Locked]
    public int $employeeId;

    public function mount(Employee $employee): void
    {
        $this->employeeId = (int) $employee->getKey();
    }

    /**
     * Issue the plastic card (from the cardboard one) or a replacement. The
     * previous card stops working at once. $expectedIndex is the index the
     * panel showed; if the card changed since, nothing happens.
     */
    public function reissue(int $expectedIndex): void
    {
        $employee = $this->authorizedEmployee('digital-id.manage');

        try {
            $index = app(DigitalIdManager::class)->reissue($employee, $expectedIndex, $this->user());
        } catch (DigitalIdException $exception) {
            $this->toast(__($exception->getMessage()), 'error');

            return;
        }

        // A new card was handed out, so a loss of that one can be reported.
        RateLimiter::clear($this->reportKey($employee));

        $this->toast(__('digital_id.done.reissued', ['index' => $index]));
    }

    public function disable(): void
    {
        $employee = $this->authorizedEmployee('digital-id.manage');

        app(DigitalIdManager::class)->disable($employee, $this->user());

        $this->toast(__('digital_id.done.disabled'));
    }

    public function enable(): void
    {
        $employee = $this->authorizedEmployee('digital-id.manage');

        try {
            app(DigitalIdManager::class)->enable($employee, $this->user());
        } catch (DigitalIdException $exception) {
            $this->toast(__($exception->getMessage()), 'error');

            return;
        }

        $this->toast(__('digital_id.done.enabled'));
    }

    /** The employee reports their own card lost; HR of their city is notified. */
    public function reportLost(): void
    {
        $employee = $this->authorizedEmployee('digital-id.report-lost');
        $key = $this->reportKey($employee);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->toast(__('digital_id.done.already_reported'), 'info');

            return;
        }

        RateLimiter::hit($key, self::REPORT_DECAY_SECONDS);

        app(DigitalIdManager::class)->reportLost($employee, $this->user());

        $this->toast(__('digital_id.done.reported'));
    }

    public function render(): View
    {
        $employee = $this->employee();

        if (! Gate::allows('digital-id.view', $employee)) {
            return view('livewire.employee-cards.manage-digital-id', ['visible' => false]);
        }

        $manager = app(DigitalIdManager::class);
        $index = $manager->currentIndex($employee);

        return view('livewire.employee-cards.manage-digital-id', [
            'visible' => true,
            'canManage' => Gate::allows('digital-id.manage', $employee),
            'canReport' => Gate::allows('digital-id.report-lost', $employee),
            'link' => $employee->digitalIdUrl(),
            'missingLink' => $manager->missingLinkReason($employee),
            'index' => $index,
            'isPlastic' => $index > DigitalIdManager::CARDBOARD_INDEX,
            'isEnabled' => (bool) $employee->digital_id_enabled,
            'isActive' => (bool) $employee->is_active,
            'issuedAt' => $employee->digital_id_rotated_at,
        ]);
    }

    private function authorizedEmployee(string $ability): Employee
    {
        $employee = $this->employee();

        abort_unless(Gate::allows($ability, $employee), 403);

        return $employee;
    }

    private function reportKey(Employee $employee): string
    {
        return 'digital-id-report-lost:'.$employee->getKey();
    }

    private function employee(): Employee
    {
        return Employee::query()
            ->with([
                'city',
                'contracts' => fn ($query) => $query->with('position.program'),
            ])
            ->findOrFail($this->employeeId);
    }

    private function user(): ?User
    {
        /** @var User|null */
        return Auth::user();
    }
}
