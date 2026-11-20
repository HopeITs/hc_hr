<?php

namespace App\Services;

use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ProfileUpdatedByEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Tells HR what an employee changed on their own profile (meeting 19/11,
 * track 2): the employee edits directly, with no approval, and HR of the
 * employee's city reads a description of the change (field: before → after).
 *
 * Only an employee's own edits are reported; HR editing a record is the
 * ordinary audited path and notifies nobody. One save is one notification,
 * however many fields it changed.
 *
 * Values travel only for the fields in VALUE_SHOWN. Every other field (phones,
 * addresses, e-mail, marital status, children: the ones the activity log also
 * records by name only) is reported as "changed", so a notification never
 * carries more than the log does.
 */
final class ProfileEditNotifier
{
    /** Fields whose before and after values may appear in the notification. */
    public const VALUE_SHOWN = ['skills', 'interests'];

    /** The permission behind EmployeePolicy::update: who maintains the record. */
    private const HR_PERMISSION = 'employees.update';

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  column => [before, after]
     */
    public function fieldsChanged(User $actor, Employee $employee, array $changes): void
    {
        if ($changes === [] || ! $this->isOwnEdit($actor, $employee)) {
            return;
        }

        $lines = [];

        foreach ($changes as $column => [$before, $after]) {
            $lines[] = $this->localized(fn (string $locale): string => in_array($column, self::VALUE_SHOWN, true)
                ? __('employees.profile.notify.value-line', [
                    'field' => $this->fieldLabel($column, $locale),
                    'before' => $this->shown($before, $locale),
                    'after' => $this->shown($after, $locale),
                ], $locale)
                : __('employees.profile.notify.changed-line', ['field' => $this->fieldLabel($column, $locale)], $locale));
        }

        $this->send($actor, $employee, $lines);
    }

    /**
     * A new education, course, experience or family entry, or a new photo.
     *
     * @param  array<string, mixed>  $data
     */
    public function entryAdded(User $actor, Employee $employee, string $section, array $data = []): void
    {
        if (! $this->isOwnEdit($actor, $employee)) {
            return;
        }

        // The entry's own name, never a relative's phone.
        $summary = Str::limit(trim(match ($section) {
            'education' => implode(' · ', array_filter([$data['name'] ?? null, $data['specialization'] ?? null])),
            'course' => (string) ($data['title'] ?? ''),
            'experience' => implode(' · ', array_filter([$data['job_title'] ?? null, $data['company'] ?? null])),
            'relative' => (string) ($data['name'] ?? ''),
            default => '',
        }), 80);

        $line = $this->localized(fn (string $locale): string => $summary === ''
            ? __('employees.profile.notify.added-line-bare', ['section' => __('employees.profile.change.sections.'.$section, [], $locale)], $locale)
            : __('employees.profile.notify.added-line', [
                'section' => __('employees.profile.change.sections.'.$section, [], $locale),
                'entry' => $summary,
            ], $locale));

        $this->send($actor, $employee, [$line]);
    }

    /**
     * HR accounts of the employee's city whose HR role may update the record
     * (EmployeePolicy::update's permission, on a role that covers the city).
     * National HR only when the city has none, so a change is never reported
     * to nobody. The editor is never told about their own change.
     *
     * @return Collection<int, User>
     */
    public function recipients(Employee $employee, ?User $except = null): Collection
    {
        $slug = $employee->city?->slug;
        $users = $slug ? $this->hrWithRoles(City::hrRolesForSlug($slug)) : collect();

        if ($users->isEmpty()) {
            $users = $this->hrWithRoles(['HR-'.City::NATIONAL_SLUG]);
        }

        return $users->reject(fn (User $user): bool => $except !== null && $user->is($except))->values();
    }

    /** @param  array<int, array{en: string, ar: string}>  $lines */
    private function send(User $actor, Employee $employee, array $lines): void
    {
        $recipients = $this->recipients($employee, $actor);

        if ($recipients->isEmpty()) {
            return;
        }

        $notification = new ProfileUpdatedByEmployee($employee, $lines);

        // Nothing is reported for a write that is rolled back.
        DB::afterCommit(fn () => Notification::send($recipients, $notification));
    }

    private function isOwnEdit(User $actor, Employee $employee): bool
    {
        return $actor->employee_id !== null && (int) $actor->employee_id === (int) $employee->getKey();
    }

    /**
     * @param  array<int, string>  $roles
     * @return Collection<int, User>
     */
    private function hrWithRoles(array $roles): Collection
    {
        return User::query()
            ->whereHas('roles', fn (Builder $query): Builder => $query
                ->whereIn('name', $roles)
                ->whereHas('permissions', fn (Builder $permission): Builder => $permission->where('name', self::HR_PERMISSION)))
            ->whereHas('profile', fn (Builder $query): Builder => $query->where('is_active', true))
            ->get();
    }

    private function fieldLabel(string $column, string $locale): string
    {
        return match ($column) {
            'personal_phone' => __('general.personal-phone', [], $locale),
            'personal_email' => __('general.email', [], $locale),
            'address_ar' => __('employees.profile.address-ar', [], $locale),
            'address_en' => __('employees.profile.address-en', [], $locale),
            'skills' => __('general.skills', [], $locale),
            'interests' => __('general.interests', [], $locale),
            'work_phone' => __('general.work-phone', [], $locale),
            'whatsapp_work' => 'WhatsApp',
            'telegram_work' => 'Telegram',
            'marital_status' => __('general.marital-status', [], $locale),
            'children' => trim(__('general.children', [], $locale)),
            default => $column,
        };
    }

    private function shown(mixed $value, string $locale): string
    {
        $value = trim((string) $value);

        return $value === '' ? __('employees.profile.notify.empty', [], $locale) : Str::limit($value, 80);
    }

    /** @return array{en: string, ar: string} */
    private function localized(callable $line): array
    {
        return ['en' => $line('en'), 'ar' => $line('ar')];
    }
}
