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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one writer of profile change requests (D1, profile-contract.md §5.1;
 * table approved by the technical lead 2026-10-27).
 *
 * An active employee proposes one new entry to their own education, courses,
 * experience or family, or a new photo. Nothing reaches the profile until HR
 * of the employee's city (EmployeePolicy::update) approves it, which writes
 * the entry to its own table in the same transaction. A rejection needs a
 * reason, which the employee reads. One open proposal per section: a newer
 * one replaces it. Pay and identity never pass through here.
 */
final class ProfileChangeRequests
{
    public const ALREADY_HANDLED = 'already_handled';

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

    /** May $user propose changes to their own profile? Only an active employee, for themself. */
    public function canPropose(User $user): bool
    {
        return $user->employee_id !== null && (bool) $user->profile?->is_active;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function submit(User $user, string $section, array $data, ?UploadedFile $file = null): ProfileChangeRequest
    {
        if (! in_array($section, ProfileChangeRequest::SECTIONS, true) || ! $this->canPropose($user)) {
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

        return DB::transaction(function () use ($user, $employee, $section, $validated, $file) {
            // One open proposal per section: the newer one replaces it.
            ProfileChangeRequest::query()->pending()
                ->where('employee_id', $employee->id)->where('section', $section)
                ->lockForUpdate()->get()
                ->each(function (ProfileChangeRequest $old): void {
                    $old->clearMediaCollection('file');
                    $old->clearMediaCollection('photo');
                    $old->update(['status' => ProfileChangeRequest::REPLACED]);
                });

            $change = ProfileChangeRequest::query()->create([
                'employee_id' => $employee->id,
                'section' => $section,
                'proposed' => $validated,
                'status' => ProfileChangeRequest::PENDING,
                'requested_by' => $user->id,
            ]);

            if ($file && $section === 'photo') {
                // Re-encoded once, here, exactly as an HR upload is; approval copies it as is.
                app(SecureImageUploadService::class)->attachTo($change, $file, 'photo', 'avatar', 'local');
            } elseif ($file) {
                $change->addMedia($file->getRealPath())
                    ->usingFileName($section.'-file.'.$file->getClientOriginalExtension())
                    ->toMediaCollection('file', 'local');
            }

            return $change;
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
        $data = $change->proposed;

        $row = match ($change->section) {
            'education' => $employee->educations()->create($data),
            'course' => $employee->courses()->create([...$data, 'has_certificate' => (bool) ($data['has_certificate'] ?? false)]),
            'experience' => $employee->experiences()->create($data),
            'relative' => $employee->relatives()->create($data),
            'photo' => null,
        };

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
}
