<?php

namespace App\Livewire\Forms\Employee;

use App\Enums\Nationality;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Rules\SafeImage;
use App\Services\SecureImageUploadService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;
use Livewire\WithFileUploads;

class PersonalInfoForm extends Form
{
    use WithFileUploads;

    /**
     * The fields shown on the employee form's Contact tab; every other field
     * of this form is on the Profile tab. `email` is built from `username`.
     */
    public const CONTACT_FIELDS = [
        'username', 'email', 'create_account', 'work_phone', 'personal_phone', 'personal_email',
        'whatsapp_work', 'telegram_work', 'facebook', 'instagram', 'linkedin',
    ];

    public ?Employee $employee = null;

    public $photo = null;

    #[Validate('required|string')]
    public $first_name_ar;

    #[Validate('required|string')]
    public $first_name_en;

    #[Validate('required|string')]
    public $last_name_ar;

    #[Validate('required|string')]
    public $last_name_en;

    public $id;

    #[Validate('required|string')]
    public $father_name_ar;

    #[Validate('required|string')]
    public $father_name_en;

    #[Validate('required|string')]
    public $mother_name_ar;

    #[Validate('required|string')]
    public $mother_name_en;

    #[Validate('nullable|string')]
    public $address_ar;

    #[Validate('nullable|string')]
    public $address_en;

    public $email;

    public $national_number;

    #[Validate('nullable|date')]
    public $dob;

    #[Validate('nullable|date')]
    public $actual_dob;

    /**
     * Organisational joining date. Optional: when blank, seniority falls back
     * to the earliest contract's start (see Employee::serviceStartDate()).
     */
    #[Validate('nullable|date')]
    public $joined_at;

    #[Validate('required|in:1,2')]
    public $gender;

    #[Validate('required')]
    public $marital_status;

    #[Validate('nullable|integer|min:0')]
    public $children;

    #[Validate('integer')]
    public $nationality;

    #[Validate('required|exists:cities,id')]
    public $city_id;

    #[Validate('nullable|string')]
    public $civil_registry_office;

    #[Validate('nullable|string')]
    public $civil_registry_number;

    #[Validate('nullable|string')]
    public $pob_en;

    #[Validate('nullable|string')]
    public $pob_ar;

    #[Validate('nullable|string|regex:/^09\d{8}$/')]
    public $work_phone;

    #[Validate('nullable|string|regex:/^09\d{8}$/')]
    public $personal_phone;

    #[Validate('nullable|email')]
    public $personal_email;

    #[Validate('nullable|string')]
    public $skills;

    #[Validate('nullable|string')]
    public $interests;

    #[Validate('boolean')]
    public $is_board_member = false;

    #[Validate('nullable|date')]
    public $board_member_started_at;

    #[Validate('nullable|date|after:board_member_started_at')]
    public $board_member_end_at;

    #[Validate('boolean')]
    public $is_active = true;

    public $username;

    /**
     * When true, a platform login account (work email) is created/kept for the
     * employee. When false, the employee exists without platform access — the
     * username is still required (used for the profile page and QR scan ID).
     *
     * B5 (DEC-04, B5D-Q8): a new volunteer gets an account when their first
     * contract starts (EmployeeActivation), never from this form. HR can still
     * create one deliberately for an active employee who has none.
     */
    public bool $create_account = false;

    #[Validate('nullable|exists:employees,id')]
    public $direct_manager_id = null;

    #[Validate('nullable|exists:employees,id')]
    public $tech_manager_id = null;

    #[Validate('nullable|string')]
    public $whatsapp_work;

    #[Validate('nullable|string')]
    public $telegram_work;

    #[Validate('nullable|url')]
    public $facebook;

    #[Validate('nullable|url')]
    public $instagram;

    #[Validate('nullable|url')]
    public $linkedin;

    public function rules()
    {
        $rules = [
            // 'image' alone accepts anything GD can parse under any name the
            // client chooses. SafeImage narrows the set and the upload service
            // re-encodes it before it is stored.
            'photo' => $this->photo instanceof TemporaryUploadedFile
                ? ['mimes:jpeg,jpg,png,webp', 'max:4096', new SafeImage('avatar')]
                : ['nullable'],
            'national_number' => [
                'required',
                'string',
                Rule::when($this->isSyrianNationality(), ['digits:11'], ['max:255']),
                $this->nationalNumberIsFree(...),
            ],
            'work_phone' => ['nullable', 'regex:/^09\d{8}$/'],
            'personal_phone' => ['nullable', 'regex:/^09\d{8}$/'],
            // Username is always required: it drives the profile page and QR scan ID.
            'username' => [
                'required',
                Rule::unique('employees', 'username')->ignore($this->employee?->id),
            ],
        ];

        // The work email/login account is only validated when HR opts to create it.
        if ($this->create_account) {
            $rules['email'] = [
                'required',
                Rule::unique('users', 'email')->ignore($this->employee?->account?->id),
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'work_phone.regex' => __('employees.validation.work-phone-format'),
            'personal_phone.regex' => __('employees.validation.personal-phone-format'),
            'national_number.digits' => __('employees.validation.national-number-syrian'),
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'national_number' => __('general.national_number'),
            'work_phone' => __('general.work_phone'),
            'personal_phone' => __('general.personal_phone'),
        ];
    }

    public function setEmployee(Employee $employee): void
    {
        $this->employee = $employee;
        $this->id = $employee->id;
        $this->city_id = $employee->city_id;
        $this->national_number = $employee->national_number;
        $this->direct_manager_id = $employee->direct_manager_id;
        $this->tech_manager_id = $employee->tech_manager_id;
        $this->actual_dob = $employee->actual_dob?->format('Y-m-d');
        $this->dob = $employee->dob?->format('Y-m-d');
        $this->joined_at = $employee->joined_at?->format('Y-m-d');
        $this->gender = $employee->gender;
        $this->marital_status = $employee->marital_status;
        $this->children = $employee->children;
        $this->nationality = $employee->nationality;
        $this->civil_registry_office = $employee->civil_registry_office;
        $this->civil_registry_number = $employee->civil_registry_number;

        $this->pob_en = $employee->pob_en;
        $this->pob_ar = $employee->pob_ar;

        $this->work_phone = $employee->work_phone;
        $this->personal_phone = $employee->personal_phone;
        $this->personal_email = $employee->personal_email;
        $this->skills = $employee->skills;
        $this->interests = $employee->interests;
        $this->is_active = $employee->is_active;
        $this->is_board_member = $employee->is_board_member;
        $this->board_member_started_at = $employee->board_member_started_at?->format('Y-m-d');
        $this->board_member_end_at = $employee->board_member_end_at?->format('Y-m-d');
        $this->first_name_en = $employee->first_name_en;
        $this->first_name_ar = $employee->first_name_ar;
        $this->last_name_en = $employee->last_name_en;
        $this->last_name_ar = $employee->last_name_ar;
        $this->father_name_en = $employee->father_name_en;
        $this->father_name_ar = $employee->father_name_ar;
        $this->mother_name_en = $employee->mother_name_en;
        $this->mother_name_ar = $employee->mother_name_ar;
        $this->address_en = $employee->address_en;
        $this->address_ar = $employee->address_ar;
        $this->username = $employee->username;
        $this->email = $employee->account?->email;
        $this->photo = $employee->avatar_url;
        // Pre-tick the checkbox only if a login account already exists.
        $this->create_account = (bool) $employee->account;
        $this->whatsapp_work = $employee->whatsapp_work ?? null;
        $this->telegram_work = $employee->telegram_work ?? null;
        $this->facebook = $employee->facebook ?? null;
        $this->instagram = $employee->instagram ?? null;
        $this->linkedin = $employee->linkedin ?? null;
    }

    /**
     * Save the complete record. A new one, or a draft being finished, becomes
     * "Ready, awaiting contract" (B5-D state C): complete but not active, and
     * with no account, until its first contract starts (B5D-Q4).
     */
    public function saveInformation(): Employee
    {
        return $this->persist(complete: true);
    }

    /**
     * Save what is there as a draft (B5D-Q1-Q3, Q10): the eight name fields,
     * governorate, national ID and username are required; everything else is
     * checked only when filled in. Never for a record that is already complete.
     */
    public function saveDraft(): Employee
    {
        abort_if($this->employee && ! $this->employee->isDraft(), 403);

        return $this->persist(complete: false);
    }

    /** Whether this form may create or keep a login account for its employee. */
    public function mayHaveAccount(): bool
    {
        return $this->employee !== null
            && ! $this->employee->isDraft()
            && ($this->employee->is_active || $this->employee->account !== null);
    }

    protected function buildWorkEmail(?string $username): ?string
    {
        $username = trim($username ?? '');

        return $username ? strtolower($username).'@hcsyria.org' : null;
    }

    protected function extractUsernameFromEmail(?string $email): ?string
    {
        return $email ? explode('@', $email, 2)[0] ?? null : null;
    }

    /**
     * Create/update the login account. Privileged role changes are deliberately
     * restricted to the dedicated Access Management module.
     */
    protected function persistAccount(): void
    {
        if ($this->employee->account) {
            $this->employee->account->update(['email' => $this->email]);

            return;
        }

        $user = User::create([
            'email' => $this->email,
            'password' => Hash::make(Str::password(64)),
            'employee_id' => $this->employee->id,
        ]);

        if (Role::query()->where('name', 'employee')->exists()) {
            $user->assignRole('employee');
        }

        Password::sendResetLink(['email' => $user->email]);
    }

    private function persist(bool $complete): Employee
    {
        // Drafts and volunteers without a contract get no account (DEC-04),
        // whatever the browser sends.
        $this->create_account = $this->create_account && $this->mayHaveAccount();

        // Work email is derived from the username, only when an account is wanted.
        $this->email = $this->create_account ? $this->buildWorkEmail($this->username) : null;
        $this->normalizeIdentityAndPhoneFields();

        $this->validate($complete ? null : $this->draftRules());
        $this->authorizeScope();

        // create_account is a UI-only flag. is_active and record_status are
        // never taken from the browser: activation belongs to the first
        // contract and to the status toggle, not to this form.
        $payload = $this->except(['email', 'photo', 'create_account', 'is_active', 'employee', 'id']);

        DB::transaction(function () use ($payload, $complete): void {
            if ($this->employee) {
                $this->employee->fill($payload);

                if ($complete && $this->employee->isDraft()) {
                    $this->employee->record_status = Employee::RECORD_COMPLETE;
                }
            } else {
                $this->employee = new Employee($payload);
                $this->employee->forceFill([
                    'record_status' => $complete ? Employee::RECORD_COMPLETE : Employee::RECORD_DRAFT,
                    'is_active' => false,
                ]);
            }

            if ($this->employee->isDirty()) {
                $this->employee->last_saved_by = auth()->id();
            }

            $this->employee->save();
        });

        $this->id = $this->employee->id;
        $this->is_active = $this->employee->is_active;

        // Create/update the login account only when HR opted in. Unchecking on
        // an existing employee leaves any current account untouched.
        if ($this->create_account) {
            $this->persistAccount();
        }

        if ($this->photo instanceof TemporaryUploadedFile) {
            // Employee photographs are protection-sensitive: they are stored
            // re-encoded, under a random server-chosen name, on the private
            // disk, and served only through EmployeeAvatarController.
            app(SecureImageUploadService::class)->attachTo(
                model: $this->employee,
                file: $this->photo,
                collection: 'avatar',
                profile: 'avatar',
                disk: 'local',
            );
        }

        return $this->employee;
    }

    /**
     * The full rule set with gender, marital status and nationality made
     * optional and no work email: what a draft needs (employee-lifecycle.md §6,
     * B5D-Q3). Nationality's own rule ("integer") also refuses an empty value,
     * so it has to be made nullable explicitly.
     */
    private function draftRules(): array
    {
        $rules = $this->getRules();

        foreach (['gender', 'marital_status', 'nationality'] as $field) {
            $fieldRules = is_string($rules[$field] ?? null) ? explode('|', $rules[$field]) : (array) ($rules[$field] ?? []);
            $rules[$field] = ['nullable', ...array_values(array_filter($fieldRules, fn ($rule) => $rule !== 'required'))];
        }

        unset($rules['email']);

        return $rules;
    }

    /**
     * A national ID already on file is refused with the existing record's
     * name and city (B5D-Q9), also when it is in another city: nothing else
     * about that record, and no link to it.
     */
    private function nationalNumberIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        $existing = Employee::query()
            ->with('city')
            ->where('national_number', $value)
            ->when($this->employee, fn ($query) => $query->whereKeyNot($this->employee->id))
            ->first();

        if ($existing) {
            $fail(__('employees.validation.national-number-taken', [
                'name' => $existing->nameForLocale(app()->getLocale()),
                'city' => $existing->city?->{transColumn('name')} ?? __('general.unknown'),
            ]));
        }
    }

    private function normalizeIdentityAndPhoneFields(): void
    {
        foreach (['national_number', 'work_phone', 'personal_phone'] as $field) {
            $this->{$field} = filled($this->{$field})
                ? trim(en_digits($this->{$field}))
                : null;
        }

        // A cleared native date input sends '', which MySQL rejects for a date column.
        $this->joined_at = filled($this->joined_at) ? $this->joined_at : null;
    }

    private function isSyrianNationality(): bool
    {
        return (int) $this->nationality === Nationality::Syrian;
    }

    private function authorizeScope(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        if ($this->employee) {
            Gate::authorize('update', $this->employee);
        } else {
            Gate::authorize('create', [Employee::class, (int) $this->city_id]);
        }

        if (! $user->canAccessCity((int) $this->city_id)) {
            throw ValidationException::withMessages([
                'city_id' => __('employees.validation.city-scope'),
            ]);
        }

        foreach (['direct_manager_id', 'tech_manager_id'] as $field) {
            $managerId = $this->{$field};
            if ($managerId && ! Employee::query()
                ->whereKey($managerId)
                ->where('city_id', $this->city_id)
                ->exists()) {
                throw ValidationException::withMessages([
                    $field => __('employees.validation.manager-city'),
                ]);
            }
        }
    }
}
