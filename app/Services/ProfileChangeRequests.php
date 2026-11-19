<?php

namespace App\Services;

use App\Enums\ContractPlace;
use App\Enums\ContractType;
use App\Enums\EducationStatus;
use App\Enums\EducationType;
use App\Enums\KinshipType;
use App\Models\Employee;
use App\Models\ProfileChangeRequest;
use App\Models\User;
use App\Notifications\ProfileChangeDecided;
use App\Rules\SafeImage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Profile entries the employee adds to their own record (D1,
 * profile-contract.md §5.1; meeting 19/11, track 2).
 *
 * Since the 19/11 decision an active employee adds a new education, course,
 * experience or family entry, or a new photo, directly: it is written to its
 * own table at once and HR of the employee's city is told what was added
 * (ProfileEditNotifier). There is no approval any more.
 *
 * Proposals sent before that decision stay in profile_change_requests and HR
 * still approves or rejects them from the Inbox, through the same writer, so
 * nothing in flight is lost or converted behind anyone's back. No new
 * proposal is created. Pay and identity never pass through here.
 */
final class ProfileChangeRequests
{
    public const ALREADY_HANDLED = 'already_handled';

    public function __construct(private readonly ProfileEditNotifier $notifier) {}

    /** The fields each section accepts, with the edit page's own rules. */
    public function rules(string $section): array
    {
        return match ($section) {
            'education' => [
                'name' => ['required', 'string', 'max:255'],
                'specialization' => ['nullable', 'string', 'max:255'],
                'degree' => ['required', Rule::in(EducationType::getValues())],
                'status' => ['required', Rule::in(EducationStatus::getValues())],
                'notes' => ['nullable', 'string', 'max:1000'],
            ],
            'course' => [
                'category_id' => ['required', 'exists:course_categories,id'],
                'title' => ['required', 'string', 'max:255'],
                'organization' => ['required', Rule::in(['Hope Center', 'Personal', 'Other'])],
                'end_date' => ['required', 'date'],
                'status' => ['required', 'integer', 'min:1', 'max:5'],
                'has_certificate' => ['nullable', 'boolean'],
            ],
            'experience' => [
                'job_title' => ['required', 'string', 'max:255'],
                'company' => ['required', 'string', 'max:255'],
                'start_date' => ['required', 'date'],
                'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                'place' => ['required', Rule::in(ContractPlace::getValues())],
                'type' => ['required', Rule::in(ContractType::getValues())],
            ],
            'relative' => [
                'name' => ['required', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:255'],
                'relation' => ['required', Rule::in(KinshipType::getValues())],
            ],
            'photo' => [],
            default => throw new InvalidArgumentException("Unknown profile section {$section}"),
        };
    }

    /** May $user add entries to their own profile? Only an active employee, for themself. */
    public function canAdd(User $user): bool
    {
        return $user->employee_id !== null && (bool) $user->profile?->is_active;
    }

    /**
     * Write one new entry (or the new photo) to the user's own profile at
     * once, and tell HR of their city what was added.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException when $user may not add to a profile
     * @throws ValidationException
     */
    public function add(User $user, string $section, array $data, ?UploadedFile $file = null): void
    {
        if (! in_array($section, ProfileChangeRequest::SECTIONS, true) || ! $this->canAdd($user)) {
            throw new AuthorizationException;
        }

        $rules = $this->rules($section);
        $data = array_intersect_key($data, $rules);
        $fileRules = $section === 'photo'
            ? ['file' => ['required', 'mimes:jpeg,jpg,png,webp', 'max:4096', new SafeImage('avatar')]]
            : ['file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']];

        $validated = Validator::make([...$data, 'file' => $file], [...$rules, ...$fileRules])->validate();
        unset($validated['file']);

        $employee = $user->profile;

        DB::transaction(function () use ($user, $employee, $section, $validated, $file): void {
            $row = $this->writeEntry($employee, $section, $validated);

            if ($file && $section === 'photo') {
                // Re-encoded once, here, exactly as an HR upload is; the stored original is never touched again.
                app(SecureImageUploadService::class)->attachTo($employee, $file, 'avatar', 'avatar', 'local');
            } elseif ($file && $row) {
                $row->addMedia($file->getRealPath())
                    ->usingFileName($section.'-file.'.$file->getClientOriginalExtension())
                    ->toMediaCollection('file', 'local');
            }

            // Sent after the commit, so a rolled-back write reports nothing.
            $this->notifier->entryAdded($user, $employee, $section, $validated);
        });
    }

    /** HR of the employee's city decides (the same rule as editing the record). */
    public function canDecide(User $user, ProfileChangeRequest $change): bool
    {
        return $user->activeRoleIs('HR', 'super-admin')
            && $change->employee !== null
            && Gate::forUser($user)->allows('update', $change->employee);
    }

    /** @return ProfileChangeRequest|string the decided request, or ALREADY_HANDLED */
    public function approve(User $user, int $id): ProfileChangeRequest|string
    {
        return $this->decide($user, $id, function (ProfileChangeRequest $change): void {
            $this->apply($change);
            $change->status = ProfileChangeRequest::APPROVED;
        });
    }

    /** @return ProfileChangeRequest|string the decided request, or ALREADY_HANDLED */
    public function reject(User $user, int $id, string $reason): ProfileChangeRequest|string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('employees.profile.change.reason-required')]);
        }

        return $this->decide($user, $id, function (ProfileChangeRequest $change) use ($reason): void {
            $change->clearMediaCollection('file');
            $change->clearMediaCollection('photo');
            $change->status = ProfileChangeRequest::REJECTED;
            $change->reason = $reason;
        });
    }

    private function decide(User $user, int $id, callable $write): ProfileChangeRequest|string
    {
        $result = DB::transaction(function () use ($user, $id, $write) {
            $change = ProfileChangeRequest::query()->with('employee')->lockForUpdate()->find($id);

            // Missing and out of scope answer alike.
            if (! $change || ! $this->canDecide($user, $change)) {
                throw new AuthorizationException;
            }

            if ($change->status !== ProfileChangeRequest::PENDING) {
                return self::ALREADY_HANDLED;
            }

            $write($change);
            $change->decided_by = $user->id;
            $change->decided_at = now();
            $change->save();

            return $change;
        });

        if ($result instanceof ProfileChangeRequest) {
            DB::afterCommit(fn () => $result->employee?->account?->notify(new ProfileChangeDecided($result)));
        }

        return $result;
    }

    /** Write an approved proposal to its own table; files are copied, never re-encoded. */
    private function apply(ProfileChangeRequest $change): void
    {
        $employee = $change->employee;
        $row = $this->writeEntry($employee, $change->section, $change->proposed);

        if ($change->section === 'photo') {
            $change->getFirstMedia('photo')?->copy($employee, 'avatar', 'local');
            $change->clearMediaCollection('photo');

            return;
        }

        if ($row && ($file = $change->getFirstMedia('file'))) {
            $file->copy($row, 'file', 'local');
            $change->clearMediaCollection('file');
        }
    }

    /**
     * The one place an entry reaches its table, for a direct addition and for
     * an approved proposal alike. The photo has no row of its own.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeEntry(Employee $employee, string $section, array $data): ?Model
    {
        return match ($section) {
            'education' => $employee->educations()->create($data),
            'course' => $employee->courses()->create([...$data, 'has_certificate' => (bool) ($data['has_certificate'] ?? false)]),
            'experience' => $employee->experiences()->create($data),
            'relative' => $employee->relatives()->create($data),
            'photo' => null,
        };
    }
}
