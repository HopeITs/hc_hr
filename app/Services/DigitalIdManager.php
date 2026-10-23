<?php

namespace App\Services;

use App\Models\City;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\DigitalIdReportedLost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Facades\Activity;

/**
 * The public digital-ID card: its link, which card is current, and its
 * lifecycle (re-issue, disable, enable, report lost).
 *
 * Signed decision (docs/ux/access-policy.md §9 A2D-Q6 and the "DEC-02
 * option" row; docs/ux/decisions.md "DEC-02 revision — card link"):
 *
 *     /ID/[city 3 digits][program 3 digits][employee id]-[index]
 *
 * The link is readable and has no secret part; HR accepted in writing that it
 * can be enumerated. What protects it is that it is checked against the
 * employee's *current* data on every request:
 *
 *  - the city code is the employee's city (`cities.code`) and the program code
 *    the program of the position on the employee's current contract, both read
 *    live, so a move to another governorate or into another program ends the
 *    printed link without anyone having to remember to revoke it;
 *  - index 0 is the temporary cardboard card, plastic cards start at 1, and
 *    only the latest index resolves, so issuing the plastic card ends the
 *    cardboard one and a re-issue ends the lost plastic one immediately.
 *
 * Codes are normalised by normaliseCode(): one to three digits, zero-padded
 * to three. Anything else (e.g. a program coded "CORE") yields no link at all
 * rather than a link that could collide with another program's.
 *
 * The audit log gets the actor (causer) and the employee id. There is no
 * secret left to keep out of it, but the token column the previous design
 * used is still never logged.
 */
class DigitalIdManager
{
    public const CARDBOARD_INDEX = 0;

    /** employees.digital_id_card_index is an unsigned small integer. */
    public const MAX_INDEX = 65535;

    /**
     * City and program codes, the employee id without leading zeros, a dash,
     * and the index without leading zeros. \z rather than $ so a trailing
     * newline is not accepted.
     */
    private const PATTERN = '/^(\d{3})(\d{3})([1-9]\d{0,17})-(0|[1-9]\d{0,4})\z/';

    /**
     * Everything the public card renders, so ShowDigitalId does not query again
     * for the same rows.
     *
     * @return array<int|string, mixed>
     */
    public static function cardRelations(): array
    {
        return [
            'city',
            'media',
            'contracts' => fn ($query) => $query
                ->with([
                    'position.center.city',
                    'position.department',
                    'position.program',
                ])
                ->latest('start_date')
                ->latest('id'),
        ];
    }

    /**
     * The employee whose current card is $card, or null for anything that is
     * not a valid card: malformed input, an unknown id, wrong or outdated
     * codes, an older index, a disabled card, an inactive employee.
     *
     * Callers must not distinguish between those cases in what they return.
     */
    public function resolve(string $card): ?Employee
    {
        if (! preg_match(self::PATTERN, $card, $parts) || (int) $parts[4] > self::MAX_INDEX) {
            return null;
        }

        $employee = Employee::query()
            ->whereKey((int) $parts[3])
            ->where('is_active', true)
            // A departed employee's card and a card disabled after being lost
            // both stop here.
            ->where('digital_id_enabled', true)
            ->with(self::cardRelations())
            ->first();

        if (! $employee) {
            return null;
        }

        $expected = $this->cardCode($employee);

        return $expected !== null && hash_equals($expected, $card) ? $employee : null;
    }

    /** The path segment of the employee's current card, or null if none can be built. */
    public function cardCode(Employee $employee): ?string
    {
        $cityCode = $this->cityCode($employee);
        $programCode = $this->programCode($employee);

        if ($cityCode === null || $programCode === null || ! $employee->getKey()) {
            return null;
        }

        return $cityCode.$programCode.$employee->getKey().'-'.$this->currentIndex($employee);
    }

    /**
     * Why no link can be built for this employee, as a translation key, or
     * null when cardCode() succeeds.
     */
    public function missingLinkReason(Employee $employee): ?string
    {
        if (! $employee->city) {
            return 'digital_id.link_missing.no_city';
        }

        if ($this->cityCode($employee) === null) {
            return 'digital_id.link_missing.city_code';
        }

        if (! $this->cardContract($employee)?->position?->program) {
            return 'digital_id.link_missing.no_position';
        }

        if ($this->programCode($employee) === null) {
            return 'digital_id.link_missing.program_code';
        }

        return null;
    }

    public function cityCode(Employee $employee): ?string
    {
        return self::normaliseCode($employee->city?->code);
    }

    public function programCode(Employee $employee): ?string
    {
        return self::normaliseCode($this->cardContract($employee)?->position?->program?->code);
    }

    public function currentIndex(Employee $employee): int
    {
        // An instance created in this request has no value until refreshed;
        // the column defaults to 0.
        return (int) ($employee->digital_id_card_index ?? self::CARDBOARD_INDEX);
    }

    /**
     * The contract whose position the card shows: the employee's current
     * (active, in-date) contract with the latest start date, the newest one
     * first on a tie. The card's position, center and program code all come
     * from this one contract.
     */
    public function cardContract(Employee $employee): ?Contract
    {
        /** @var Collection<int, Contract> $contracts */
        $contracts = $employee->relationLoaded('contracts')
            ? $employee->contracts
            : $employee->contracts()->with('position.program')->get();

        return $contracts
            ->sort(fn (Contract $a, Contract $b) => [$b->start_date?->getTimestamp(), $b->getKey()]
                <=> [$a->start_date?->getTimestamp(), $a->getKey()])
            ->first();
    }

    /** One to three digits, zero-padded to three; anything else is not a card code. */
    public static function normaliseCode(mixed $code): ?string
    {
        $code = trim((string) $code);

        return preg_match('/^\d{1,3}\z/', $code) === 1
            ? str_pad($code, 3, '0', STR_PAD_LEFT)
            : null;
    }

    /**
     * Issue the next card: the plastic card (index 1) after the cardboard one,
     * or a replacement plastic card after that. The previous index stops
     * resolving in the same transaction.
     *
     * $expectedIndex is the index the caller showed when HR confirmed. If it
     * moved meanwhile (a double click, a second tab), nothing changes: the
     * card being printed would otherwise already be out of date.
     *
     * The new card is enabled: issuing a replacement is how a lost card is
     * resolved. An inactive employee gets no card.
     */
    public function reissue(Employee $employee, int $expectedIndex, ?User $actor = null): int
    {
        $locked = DB::transaction(function () use ($employee, $expectedIndex, $actor): Employee {
            /** @var Employee $locked */
            $locked = Employee::query()
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $current = $this->currentIndex($locked);

            if ($current !== $expectedIndex) {
                throw DigitalIdException::staleIndex();
            }

            if (! $locked->is_active) {
                throw DigitalIdException::inactiveEmployee();
            }

            if ($this->cardCode($locked) === null) {
                throw DigitalIdException::noCardLink();
            }

            if ($current >= self::MAX_INDEX) {
                throw DigitalIdException::limitReached();
            }

            $locked->forceFill([
                'digital_id_card_index' => $current + 1,
                'digital_id_enabled' => true,
                'digital_id_rotated_at' => now(),
                'digital_id_revoked_at' => null,
            ])->save();

            $this->record($locked, 'digital_id_reissued', [
                'previous_index' => $current,
                'card_index' => $current + 1,
            ], $actor);

            return $locked;
        });

        $this->syncInstance($employee, $locked);

        return $this->currentIndex($employee);
    }

    public function disable(Employee $employee, ?User $actor = null): void
    {
        // Strict comparison: an Employee instance that was not selected with
        // this column reads null here, and `! null` would short-circuit a
        // revocation that must never be skipped.
        if ($employee->digital_id_enabled === false) {
            return;
        }

        $employee->forceFill([
            'digital_id_enabled' => false,
            'digital_id_revoked_at' => now(),
        ])->save();

        $this->record($employee, 'digital_id_disabled', [
            'card_index' => $this->currentIndex($employee),
        ], $actor);
    }

    /**
     * Turn the current card back on (a card that was found again). An inactive
     * employee's card stays off: reactivating the employee does not revive it
     * either, HR enables it afterwards.
     */
    public function enable(Employee $employee, ?User $actor = null): void
    {
        if (! $employee->is_active) {
            throw DigitalIdException::inactiveEmployee();
        }

        if ($employee->digital_id_enabled === true) {
            return;
        }

        $employee->forceFill([
            'digital_id_enabled' => true,
            'digital_id_revoked_at' => null,
        ])->save();

        $this->record($employee, 'digital_id_enabled', [
            'card_index' => $this->currentIndex($employee),
        ], $actor);
    }

    /**
     * A staff member reports their own card lost. Nothing about the card
     * changes: HR of the employee's city is notified and decides (A2D-Q5).
     *
     * @return int how many HR accounts were notified
     */
    public function reportLost(Employee $employee, User $reporter): int
    {
        $this->record($employee, 'digital_id_reported_lost', [
            'card_index' => $this->currentIndex($employee),
        ], $reporter);

        $recipients = $this->hrRecipients($employee)
            ->reject(fn (User $user) => $user->is($reporter))
            ->values();

        Notification::send($recipients, new DigitalIdReportedLost($employee));

        return $recipients->count();
    }

    /**
     * Every HR account responsible for the employee's city, the same routing
     * leave requests use. National HR (HR-SYR) only when the city has none, so
     * a report never disappears silently.
     *
     * @return Collection<int, User>
     */
    private function hrRecipients(Employee $employee): Collection
    {
        $citySlug = $employee->city?->slug;
        $roles = $citySlug ? City::hrRolesForSlug($citySlug) : [];

        $recipients = $this->activeUsersWithRoles($roles);

        return $recipients->isNotEmpty()
            ? $recipients
            : $this->activeUsersWithRoles(['HR-'.City::NATIONAL_SLUG]);
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function activeUsersWithRoles(array $roles): Collection
    {
        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->whereHas('profile', fn ($query) => $query->where('is_active', true))
            ->get();
    }

    private function syncInstance(Employee $employee, Employee $fresh): void
    {
        $employee->forceFill($fresh->only([
            'digital_id_card_index',
            'digital_id_enabled',
            'digital_id_rotated_at',
            'digital_id_revoked_at',
        ]))->syncOriginal();
    }

    /** @param  array<string, mixed>  $properties */
    private function record(Employee $employee, string $event, array $properties = [], ?User $actor = null): void
    {
        $logger = Activity::useLog('security')
            ->performedOn($employee)
            ->withProperties(['employee_id' => $employee->getKey()] + $properties);

        if ($actor) {
            $logger->causedBy($actor);
        }

        $logger->log($event);
    }
}
