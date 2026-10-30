<?php

namespace App\Services\Assistant;

use App\Enums\AttachmentCategory;
use App\Enums\Currency;
use App\Enums\LeaveRequestStatus;
use App\Enums\PayMode;
use App\Enums\SalaryPer;
use App\Models\Contract;
use App\Models\ContractPayTier;
use App\Models\Employee;
use App\Models\Folder;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * The functions the assistant may call to read the asking employee's record.
 *
 * None of them takes a parameter: whose record is read always comes from the
 * signed-in user, never from the model, so no wording of a question can point
 * a tool at a colleague. A tool the user may not use is not declared at all,
 * and call() refuses it again in case the model names it anyway.
 */
class EmployeeTools
{
    // The same list ArchivedFilesModule shows; anything else is never listed there.
    private const ARCHIVE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'svg', 'pdf', 'docx', 'xlsx', 'pptx'];

    private const DESCRIPTIONS = [
        'get_my_profile' => "The employee's own name, positions (title, department, center, city), contract start and end dates and work schedule.",
        'get_my_leave_balances' => "The employee's own leave balances per leave type: entitled, used, pending and remaining.",
        'get_my_leave_requests' => "The employee's own 15 most recent leave requests: type, start, end, duration and status.",
        'get_my_salary' => "The employee's own pay on their current contract: amount (or pay tiers), currency and period.",
        'get_my_files' => 'Names, categories and dates of the files in the employee\'s own record and in the archive folders shared with them. No file contents.',
    ];

    /** @return list<array{name: string, description: string}> */
    public function declarations(User $user): array
    {
        return array_map(
            fn (string $name) => ['name' => $name, 'description' => self::DESCRIPTIONS[$name]],
            $this->available($user),
        );
    }

    /** @return array<string, mixed> */
    public function call(User $user, string $name): array
    {
        $employee = $user->profile;

        if (! $employee || ! in_array($name, $this->available($user), true)) {
            return ['error' => "The tool {$name} is not available to this employee."];
        }

        return match ($name) {
            'get_my_profile' => $this->profile($employee),
            'get_my_leave_balances' => $this->leaveBalances($employee),
            'get_my_leave_requests' => $this->leaveRequests($employee),
            'get_my_salary' => $this->salary($employee),
            'get_my_files' => $this->files($user, $employee),
        };
    }

    /** @return list<string> */
    private function available(User $user): array
    {
        if (! $user->profile) {
            return [];
        }

        $canSeeLeaves = $user->hasActivePermission('leaves.view-own');

        return array_values(array_filter([
            'get_my_profile',
            $canSeeLeaves ? 'get_my_leave_balances' : null,
            $canSeeLeaves ? 'get_my_leave_requests' : null,
            config('assistant.share_salary') ? 'get_my_salary' : null,
            'get_my_files',
        ]));
    }

    private function profile(Employee $employee): array
    {
        $locale = app()->getLocale();
        $contracts = $employee->contracts()
            ->with(['position.department', 'position.center.city', 'position.city', 'contractType'])
            ->latest('start_date')
            ->get();

        return [
            'name' => $employee->nameForLocale($locale),
            'city' => $employee->city?->name,
            'active_contracts' => $contracts->map(fn (Contract $contract) => array_filter([
                'position' => $contract->position?->title,
                'department' => $contract->position?->department?->name,
                'center' => $contract->position?->center?->name,
                'city' => ($contract->position?->center?->city ?? $contract->position?->city)?->name,
                'contract_type' => $contract->contractType?->{"name_{$locale}"},
                'start_date' => $contract->start_date?->format('Y-m-d'),
                'end_date' => $contract->end_date?->format('Y-m-d'),
                'weekly_hours' => $contract->weekly_hours,
                'work_days' => $contract->{"work_days_{$locale}"},
                'work_hours' => $this->hours($contract->work_hours_from, $contract->work_hours_to),
                'second_shift' => $this->hours($contract->work_hours_2_from, $contract->work_hours_2_to),
            ], fn ($value) => filled($value)))->values()->all(),
        ];
    }

    private function leaveBalances(Employee $employee): array
    {
        $balances = $employee->availableLeaves();

        if (! $balances || $balances->isEmpty()) {
            return ['balances' => [], 'note' => 'No leave balances on the current contract.'];
        }

        return ['balances' => $balances->map(fn (LeaveBalance $balance) => [
            'leave_type' => $balance->leaveType?->name,
            'entitled' => $this->plain($balance->deserved_formatted),
            'used' => $this->plain($balance->used_formatted),
            'pending_approval' => $this->plain($balance->getFormattedLabel($balance->pending_days ?? 0, $balance->pending_hours ?? 0)),
            'remaining' => $this->plain($balance->remaining),
        ])->values()->all()];
    }

    private function leaveRequests(Employee $employee): array
    {
        // Same ownership rule as MyLeaveRequestModule: through the balance's contract.
        $requests = LeaveRequest::query()
            ->whereHas('leaveBalance.contract', fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->with(['leaveBalance.leaveType', 'leaveBalance.contract.position.center'])
            ->latest()
            ->limit(15)
            ->get();

        return ['requests' => $requests->map(fn (LeaveRequest $request) => [
            'leave_type' => $request->leaveBalance?->leaveType?->name,
            'unit' => $request->type,
            'start' => $request->type === 'hour' ? $request->start_at?->format('Y-m-d H:i') : $request->start_at?->format('Y-m-d'),
            'end' => $request->type === 'hour' ? $request->end_at?->format('Y-m-d H:i') : $request->end_at?->format('Y-m-d'),
            'duration' => $this->plain($request->duration),
            'status' => LeaveRequestStatus::of($request->status)->label(),
        ])->values()->all()];
    }

    private function salary(Employee $employee): array
    {
        $contract = $employee->latestActiveContract();

        if (! $contract) {
            return ['salary' => null, 'note' => 'The employee has no active contract.'];
        }

        $locale = app()->getLocale();
        $result = [
            'position' => $contract->position?->title,
            'currency' => Currency::getLocalizedName((int) $contract->currency)[$locale] ?? null,
            'per' => SalaryPer::getLocalizedName((int) $contract->salary_per)['value'],
        ];

        if ((int) $contract->pay_mode === PayMode::Flexible) {
            $tiers = $contract->payTiers()->get();
            // A null amount is a figure this host cannot decrypt, not a zero.
            $result['pay_tiers'] = $tiers->map(fn (ContractPayTier $tier) => [
                'condition' => $tier->condition,
                'amount' => $tier->amount,
            ])->values()->all();

            if ($tiers->isEmpty() || $tiers->contains(fn (ContractPayTier $tier) => $tier->amount === null)) {
                $result['note'] = 'Some or all pay figures are unavailable here; the employee should ask HR.';
            }

            return $result;
        }

        $result['salary'] = $contract->salary;

        if ($contract->salary === null) {
            $result['note'] = 'The pay figure is unavailable here; the employee should ask HR.';
        }

        return $result;
    }

    private function files(User $user, Employee $employee): array
    {
        $result = [
            'my_record_files' => $employee->employeeAttachments()->map(fn (Media $media) => array_filter([
                'name' => $media->getCustomProperty('name') ?: $media->name,
                'category' => AttachmentCategory::tryFrom((string) $media->getCustomProperty('category'))?->label(),
                'added' => $media->created_at?->format('Y-m-d'),
            ]))->values()->all(),
        ];

        // Only folders the user was explicitly given (folder_user), as on the
        // shared-folders page, which itself needs archives.view.
        if ($user->hasActivePermission('archives.view')) {
            $result['shared_folders'] = $user->accessibleFolders()
                ->with('media')
                ->orderBy('name')
                ->get()
                ->map(fn (Folder $folder) => [
                    'folder' => $folder->name,
                    'files' => $folder->getMedia('archived_files')
                        ->filter(fn (Media $media) => in_array(strtolower((string) $media->extension), self::ARCHIVE_EXTENSIONS, true))
                        ->map(fn (Media $media) => [
                            'name' => $media->file_name,
                            'added' => $media->created_at?->format('Y-m-d'),
                        ])->values()->all(),
                ])->values()->all();
        }

        return $result;
    }

    private function hours(?string $from, ?string $to): ?string
    {
        return $from && $to ? substr($from, 0, 5).'–'.substr($to, 0, 5) : null;
    }

    /** The formatted labels carry <strong> markup for the cards. */
    private function plain(?string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text))) ?? '');
    }
}
