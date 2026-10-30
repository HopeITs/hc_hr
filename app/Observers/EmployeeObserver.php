<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\AccessRevoker;
use App\Services\DigitalIdManager;
use App\Support\StatisticsCache;

/**
 * Ties access revocation to the employee's active flag.
 *
 * Deactivation happens from several places — the employee edit form, the
 * `deactivate:employee` scheduled command, contract termination — and each one
 * would otherwise have to remember to terminate sessions. Hooking the model
 * event means there is exactly one place to get right, and any future
 * deactivation path inherits it.
 */
class EmployeeObserver
{
    public function updated(Employee $employee): void
    {
        // Only the active -> inactive transition, and only when that column
        // actually changed in this save.
        if (! $employee->wasChanged('is_active') || $employee->is_active) {
            return;
        }

        // A departed volunteer's printed card must show "card is not valid"
        // (DEC-02), so deactivation disables it too, not only the login.
        // Reactivating does not turn it back on; HR enables it.
        //
        // On a fresh instance: disable() saves, and saving this one again
        // from inside its own "updated" event would carry is_active as dirty
        // once more (the original is only synced after this event), so the
        // observer would run twice and revoke access twice.
        $fresh = $employee->fresh();

        if ($fresh) {
            app(DigitalIdManager::class)->disable($fresh);

            // Keep the caller's instance truthful without making it dirty.
            $employee->setRawAttributes(array_merge($employee->getAttributes(), [
                'digital_id_enabled' => $fresh->getAttributes()['digital_id_enabled'] ?? false,
                'digital_id_revoked_at' => $fresh->getAttributes()['digital_id_revoked_at'] ?? null,
            ]));
            $employee->syncOriginalAttributes(['digital_id_enabled', 'digital_id_revoked_at']);
        }

        $user = $employee->account;

        if (! $user) {
            return;
        }

        app(AccessRevoker::class)->revoke($user, 'employee_deactivated');
    }

    /**
     * Headcount aggregates are keyed off employees as well as contracts (a
     * deactivated or newly created employee changes the totals), so any write
     * here starts a new cache generation.
     *
     * @see StatisticsCache
     */
    public function saved(Employee $employee): void
    {
        StatisticsCache::flush();
    }

    public function deleted(Employee $employee): void
    {
        StatisticsCache::flush();
    }
}
