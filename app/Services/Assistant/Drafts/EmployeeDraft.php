<?php

namespace App\Services\Assistant\Drafts;

use App\Enums\MaritalStatus;
use App\Enums\Nationality;
use App\Livewire\Forms\Employee\PersonalInfoForm;
use App\Models\City;
use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFormObjects\FormObjectSynth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A new employee, drafted from the chat and created only on Confirm.
 *
 * Every rule lives in the create screen's own form (PersonalInfoForm): the
 * draft fills that form as the user and runs the steps its persist() runs
 * before writing (normalise, validate the complete record, check the city
 * scope), and submit() creates through saveInformation() itself, which is
 * what the screen's Save button calls: same record status, same observers,
 * same activity log, no account and no photo.
 */
class EmployeeDraft implements RecordDraft
{
    private const OPTIONS_TOOL = 'get_employee_form_options';

    private const DRAFT_TOOL = 'draft_employee';

    /** What the create screen requires for a complete record. */
    private const REQUIRED = [
        'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar',
        'father_name_en', 'father_name_ar', 'mother_name_en', 'mother_name_ar',
        'city_id', 'gender', 'marital_status', 'nationality', 'national_number', 'username',
    ];

    /** The screen's optional text fields the chat may fill. */
    private const OPTIONAL = [
        'dob', 'actual_dob', 'joined_at', 'children',
        'address_en', 'address_ar', 'pob_en', 'pob_ar',
        'civil_registry_office', 'civil_registry_number',
        'work_phone', 'personal_phone', 'personal_email',
    ];

    public function type(): string
    {
        return 'employee';
    }

    public function declarations(User $user): array
    {
        if (! $this->mayCreate($user)) {
            return [];
        }

        $text = fn (string $description): array => ['type' => 'string', 'description' => $description];

        return [
            [
                'name' => self::OPTIONS_TOOL,
                'description' => 'The values the new-employee form accepts for its choice fields, with their ids: the cities (current residence) this user may add an employee in, genders, marital statuses and nationalities. Call it before drafting an employee.',
            ],
            [
                'name' => self::DRAFT_TOOL,
                'description' => 'Checks a new employee with every rule of the new-employee screen (required fields, national ID format and duplicates, username taken, city the user may manage) and prepares it for the user to confirm. It never creates anything: only the user can, with the Confirm button shown under your answer. Every name is needed in both Arabic and English: ask the user for whichever is missing, and never transliterate or translate a name yourself without showing the spelling and asking the user to confirm it. Never guess a value the user did not give (gender, marital status, nationality, national ID, parents names): ask for it. Ask for the username if the user did not give one (you may suggest first.last in lowercase Latin letters, for the user to confirm). The photo, documents, contract and login account are added on the employee page afterwards, not here.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'first_name_en' => $text('First name in English.'),
                        'first_name_ar' => $text('First name in Arabic.'),
                        'last_name_en' => $text('Last name in English.'),
                        'last_name_ar' => $text('Last name in Arabic.'),
                        'father_name_en' => $text("Father's name in English."),
                        'father_name_ar' => $text("Father's name in Arabic."),
                        'mother_name_en' => $text("Mother's name in English."),
                        'mother_name_ar' => $text("Mother's name in Arabic."),
                        'city_id' => ['type' => 'integer', 'description' => 'Current residence: a city id from get_employee_form_options.'],
                        'gender' => ['type' => 'integer', 'description' => 'A gender id from get_employee_form_options (1 male, 2 female).'],
                        'marital_status' => ['type' => 'integer', 'description' => 'A marital status id from get_employee_form_options.'],
                        'nationality' => ['type' => 'integer', 'description' => 'A nationality id from get_employee_form_options.'],
                        'national_number' => $text('National ID number. 11 digits for a Syrian.'),
                        'username' => $text('Username (Latin letters, unique), used for the profile page and the QR ID.'),
                        'dob' => $text('Date of birth as on the ID, YYYY-MM-DD. Optional.'),
                        'actual_dob' => $text('Real birthday if it differs from the ID, YYYY-MM-DD. Optional.'),
                        'joined_at' => $text('Date the person joined the organisation, YYYY-MM-DD. Optional.'),
                        'children' => ['type' => 'integer', 'description' => 'Number of children. Optional.'],
                        'address_en' => $text('Address in English. Optional.'),
                        'address_ar' => $text('Address in Arabic. Optional.'),
                        'pob_en' => $text('Place of birth in English. Optional.'),
                        'pob_ar' => $text('Place of birth in Arabic. Optional.'),
                        'civil_registry_office' => $text('Civil registry office. Optional.'),
                        'civil_registry_number' => $text('Civil registry number. Optional.'),
                        'work_phone' => $text('Work phone, 10 digits starting with 09. Optional.'),
                        'personal_phone' => $text('Personal phone, 10 digits starting with 09. Optional.'),
                        'personal_email' => $text('Personal email address. Optional.'),
                    ],
                    'required' => self::REQUIRED,
                ],
            ],
        ];
    }

    public function call(User $user, string $tool, array $args): array
    {
        if (! $this->mayCreate($user)) {
            return ['error' => "The tool {$tool} is not available."];
        }

        return match ($tool) {
            self::OPTIONS_TOOL => $this->options($user),
            self::DRAFT_TOOL => $this->draft($user, $args),
            default => ['error' => "The tool {$tool} is not available."],
        };
    }

    public function card(array $proposal): array
    {
        $fields = (array) ($proposal['fields'] ?? []);
        $value = fn (string $key): ?string => filled($fields[$key] ?? null) ? (string) $fields[$key] : null;
        $pair = fn (string $attribute): ?string => implode(' / ', array_filter([$value($attribute.'_ar'), $value($attribute.'_en')])) ?: null;

        return [
            'title' => __('assistant-employee.title'),
            'icon' => 'user',
            'rows' => array_filter([
                __('assistant-employee.fields.name_ar') => trim($value('first_name_ar').' '.$value('last_name_ar')),
                __('assistant-employee.fields.name_en') => trim($value('first_name_en').' '.$value('last_name_en')),
                __('assistant-employee.fields.father_name') => $pair('father_name'),
                __('assistant-employee.fields.mother_name') => $pair('mother_name'),
                __('assistant-employee.fields.city') => $this->cityName($fields['city_id'] ?? null),
                __('assistant-employee.fields.gender') => $this->genderLabel($fields['gender'] ?? null),
                __('assistant-employee.fields.marital_status') => $this->maritalLabel($fields['marital_status'] ?? null),
                __('assistant-employee.fields.nationality') => $this->nationalityLabel($fields['nationality'] ?? null),
                __('assistant-employee.fields.national_number') => $value('national_number'),
                __('assistant-employee.fields.username') => $value('username'),
                __('assistant-employee.fields.dob') => $value('dob'),
                __('assistant-employee.fields.actual_dob') => $value('actual_dob'),
                __('assistant-employee.fields.joined_at') => $value('joined_at'),
                __('assistant-employee.fields.children') => $value('children'),
                __('assistant-employee.fields.address') => $pair('address'),
                __('assistant-employee.fields.pob') => $pair('pob'),
                __('assistant-employee.fields.civil_registry_office') => $value('civil_registry_office'),
                __('assistant-employee.fields.civil_registry_number') => $value('civil_registry_number'),
                __('assistant-employee.fields.work_phone') => $value('work_phone'),
                __('assistant-employee.fields.personal_phone') => $value('personal_phone'),
                __('assistant-employee.fields.personal_email') => $value('personal_email'),
            ], fn ($row) => filled($row)),
        ];
    }

    public function submit(User $user, array $proposal, string $token): array
    {
        $key = 'assistant-employee-draft:'.$user->id.':'.sha1($token);

        try {
            $employee = Cache::lock($key.':lock', 30)->block(10, function () use ($user, $proposal, $key): Employee {
                // A repeat of a confirm that already went through: the record it made.
                if (($id = Cache::get($key)) && ($existing = Employee::query()->find($id))) {
                    return $existing;
                }

                $employee = $this->asUser($user, function () use ($user, $proposal): Employee {
                    $this->ensureMayCreate($user);
                    $form = $this->form((array) ($proposal['fields'] ?? []));
                    $this->ensureOfferedCity($user, $form->city_id);

                    // The screen's own Save: normalise, validate, scope, write.
                    return $this->guarded(fn () => $form->saveInformation());
                });

                Cache::put($key, $employee->id, now()->addDay());

                return $employee;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['draft' => __('employees.onboarding.save-in-progress')]);
        }

        return [
            'message' => __('assistant-employee.created', ['name' => $employee->nameForLocale(app()->getLocale())]),
            'url' => $employee->username ? route('employees.show', $employee->username) : null,
            'link' => __('assistant-employee.open'),
        ];
    }

    /** The create screen's own gate: the route's permission, under the active role. */
    private function mayCreate(User $user): bool
    {
        return $user->hasActivePermission('employees.create');
    }

    private function ensureMayCreate(User $user): void
    {
        if (! $this->mayCreate($user)) {
            throw ValidationException::withMessages(['draft' => __('assistant.draft.forbidden')]);
        }
    }

    /**
     * The cities the screen's residence dropdown offers (EmployeeCreate::render():
     * active, not the headquarters row), narrowed to those the active role may
     * create in (EmployeePolicy::create).
     *
     * @return array<int, string>
     */
    private function offeredCities(User $user): array
    {
        return City::query()
            ->where('is_active', true)
            ->where('code', '!=', '000')
            ->orderBy(transColumn('name'))
            ->pluck(transColumn('name'), 'id')
            ->filter(fn ($name, $id) => $user->canAccessCity((int) $id))
            ->all();
    }

    private function ensureOfferedCity(User $user, mixed $cityId): void
    {
        if (filled($cityId) && ! array_key_exists((int) $cityId, $this->offeredCities($user))) {
            throw ValidationException::withMessages(['city_id' => __('employees.validation.city-scope')]);
        }
    }

    /** @return array<string, mixed> */
    private function options(User $user): array
    {
        $list = fn (array $items): array => array_map(
            fn ($id, $name) => ['id' => $id, 'name' => $name],
            array_keys($items),
            array_values($items),
        );

        return [
            'cities' => $list($this->offeredCities($user)),
            'genders' => [
                ['id' => 1, 'name' => __('general.male')],
                ['id' => 2, 'name' => __('general.female')],
            ],
            'marital_statuses' => $list(array_combine(
                MaritalStatus::getValues(),
                array_map(fn ($value) => $this->maritalLabel($value), MaritalStatus::getValues()),
            )),
            'nationalities' => $list(array_combine(
                Nationality::getValues(),
                array_map(fn ($value) => $this->nationalityLabel($value), Nationality::getValues()),
            )),
            'required' => self::REQUIRED,
            'note' => 'Names are needed in both Arabic and English. Photo, documents and contract are added on the employee page afterwards.',
        ];
    }

    /** @return array<string, mixed> */
    private function draft(User $user, array $args): array
    {
        try {
            $fields = $this->asUser($user, function () use ($user, $args): array {
                $form = $this->form($args);

                // What persist() does before it writes, in the same order.
                $this->private($form, fn () => $this->normalizeIdentityAndPhoneFields());
                $form->validate();
                $this->ensureOfferedCity($user, $form->city_id);
                $this->guarded(fn () => $this->private($form, fn () => $this->authorizeScope()));

                return $this->fields($form);
            });
        } catch (ValidationException $e) {
            return [
                'status' => 'invalid',
                'errors' => array_values(array_unique(array_merge(...array_values($e->errors())))),
                'fields' => array_values(array_unique(array_map(
                    fn (string $key): string => str($key)->after('infoForm.')->toString(),
                    array_keys($e->errors()),
                ))),
                'note' => 'Nothing was drafted. Tell the user what to change or what is missing, then draft again.',
            ];
        }

        return [
            'status' => 'drafted',
            'proposal' => [
                'fields' => $fields,
                'name_en' => trim($fields['first_name_en'].' '.$fields['last_name_en']),
                'name_ar' => trim($fields['first_name_ar'].' '.$fields['last_name_ar']),
                'city' => $this->cityName($fields['city_id']),
                'gender' => $this->genderLabel($fields['gender']),
                'marital_status' => $this->maritalLabel($fields['marital_status']),
                'nationality' => $this->nationalityLabel($fields['nationality']),
            ],
            'note' => 'Not created. Summarise the draft (names in both languages exactly as drafted) and ask the user to press Confirm below your answer. Photo and documents are added on the employee page afterwards.',
        ];
    }

    /**
     * The checked values, as the form holds them after normalising.
     *
     * @return array<string, mixed>
     */
    private function fields(PersonalInfoForm $form): array
    {
        $fields = [];

        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $field) {
            $fields[$field] = filled($form->{$field}) ? $form->{$field} : null;
        }

        return $fields;
    }

    /** The screen's form, filled from untrusted values. Nothing here decides validity. */
    private function form(array $input): PersonalInfoForm
    {
        $form = new PersonalInfoForm(new class extends Component
        {
            public function render(): string
            {
                return '<div></div>';
            }
        }, 'infoForm');

        // Most of the screen's rules are #[Validate] attributes, which only
        // count once booted: done here the way Livewire boots a form property.
        $component = $form->getComponent();
        $bootForm = FormObjectSynth::bootFormObject($component, $form, 'infoForm');
        $component->getAttributes()->each(fn ($attribute) => method_exists($attribute, 'boot') ? $attribute->boot() : null);
        $bootForm();

        foreach ([...self::REQUIRED, ...self::OPTIONAL] as $field) {
            $value = $input[$field] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : null;
            $form->{$field} = $value === '' ? null : $value;
        }

        // A new record: no account (persist() refuses one anyway), no photo.
        $form->create_account = false;

        return $form;
    }

    /** Runs one of the form's own private steps, so its rules are not copied here. */
    private function private(PersonalInfoForm $form, Closure $step): mixed
    {
        return Closure::bind($step, $form, PersonalInfoForm::class)();
    }

    /** The form refuses an out-of-scope city with a 403: said as a validation message here. */
    private function guarded(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (AuthorizationException) {
            throw ValidationException::withMessages(['draft' => __('assistant.draft.forbidden')]);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 403) {
                throw ValidationException::withMessages(['draft' => __('assistant.draft.forbidden')]);
            }

            throw $e;
        }
    }

    private function cityName(mixed $cityId): ?string
    {
        return filled($cityId) ? City::query()->whereKey((int) $cityId)->value(transColumn('name')) : null;
    }

    private function genderLabel(mixed $gender): ?string
    {
        return match ((int) $gender) {
            1 => __('general.male'),
            2 => __('general.female'),
            default => null,
        };
    }

    private function maritalLabel(mixed $status): ?string
    {
        return is_numeric($status) ? MaritalStatus::getLocalizedName((int) $status)['value'] : null;
    }

    private function nationalityLabel(mixed $nationality): ?string
    {
        return is_numeric($nationality) ? Nationality::getLocalizedName((int) $nationality) : null;
    }

    /** Runs $callback as $user: the form reads the author and the scope from auth(). */
    private function asUser(User $user, Closure $callback): mixed
    {
        $guard = auth()->guard();
        $previous = $guard->user();

        if ($previous?->is($user)) {
            return $callback();
        }

        $guard->setUser($user);

        try {
            return $callback();
        } finally {
            $previous ? $guard->setUser($previous) : $guard->forgetUser();
        }
    }
}
