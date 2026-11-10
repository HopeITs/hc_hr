<?php

namespace App\Services\Assistant\Drafts;

use App\Livewire\Forms\NewsForm;
use App\Models\Center;
use App\Models\City;
use App\Models\Employee;
use App\Models\News;
use App\Models\Program;
use App\Models\User;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * A news item drafted from the chat, created through the News page's own
 * NewsForm: the same rules (NewsForm::rules()), the same target lists
 * (NewsForm::entities(), what the page's dropdown offers) and the same
 * persist(), so the creator, published_at and the activity log entry are
 * exactly what the page writes. Images are not drafted: one can be added
 * later on the news item's page (the form's photo is optional).
 *
 * Who may create mirrors NewsCreate::canCreateNews(): the news.create
 * permission (the route's middleware) and an active role of HR,
 * super-admin, general-manager or manager.
 */
class NewsDraft implements RecordDraft
{
    /** The page's target types, by the short names the model uses. */
    private const TARGETS = [
        'city' => City::class,
        'center' => Center::class,
        'program' => Program::class,
        'employee' => Employee::class,
    ];

    private const MAX_EMPLOYEES = 20;

    private const REPLAY_TTL_HOURS = 24;

    public function type(): string
    {
        return 'news';
    }

    public function declarations(User $user): array
    {
        if (! $this->mayCreate($user)) {
            return [];
        }

        return [
            [
                'name' => 'get_news_options',
                'description' => 'What a new news item can be addressed to: everyone, or one city, center, program or employee, with the ids to use. Employees are only listed when employee_search is given. Call it before drafting news for a specific audience.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'employee_search' => ['type' => 'string', 'description' => 'Optional: part of an employee\'s name, to find the id of an employee the news is addressed to.'],
                    ],
                ],
            ],
            [
                'name' => 'draft_news',
                'description' => 'Checks a new news item with every rule of the news page and prepares it for the user to confirm. It never publishes anything. The Arabic title and description are required: write them in Arabic (translate the user\'s text if needed) and show them to the user. Images cannot be attached from the chat; the user can add one later on the news page. Returns either the errors to tell the user, or the checked draft. Only the user can create it, with the Confirm button shown under your answer.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'title_ar' => ['type' => 'string', 'description' => 'The title in Arabic (at most 255 characters).'],
                        'title_en' => ['type' => 'string', 'description' => 'Optional: the title in English (at most 255 characters).'],
                        'description_ar' => ['type' => 'string', 'description' => 'The text of the news item in Arabic.'],
                        'description_en' => ['type' => 'string', 'description' => 'Optional: the text in English.'],
                        'event_date' => ['type' => 'string', 'description' => 'The date of the event or news as YYYY-MM-DD. Ask for it if the user did not say.'],
                        'display_until' => ['type' => 'string', 'description' => 'Optional: the last day the item is shown, as YYYY-MM-DD.'],
                        'target_type' => ['type' => 'string', 'enum' => ['all', ...array_keys(self::TARGETS)], 'description' => 'Who the news is for: all (the whole organization, the default), or one city, center, program or employee.'],
                        'target_id' => ['type' => 'integer', 'description' => 'Required unless target_type is all: the id of that city, center, program or employee, from get_news_options.'],
                    ],
                    'required' => ['title_ar', 'description_ar', 'event_date'],
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
            'get_news_options' => $this->options($args),
            'draft_news' => $this->draft($user, $args),
            default => ['error' => "The tool {$tool} is not available."],
        };
    }

    public function card(array $proposal): array
    {
        return [
            'title' => __('assistant-news.title'),
            'icon' => 'file',
            'rows' => array_filter([
                __('assistant-news.fields.title_ar') => $proposal['title_ar'] ?? null,
                __('assistant-news.fields.title_en') => $proposal['title_en'] ?? null,
                __('assistant-news.fields.description_ar') => filled($proposal['description_ar'] ?? null) ? Str::limit((string) $proposal['description_ar'], 400) : null,
                __('assistant-news.fields.description_en') => filled($proposal['description_en'] ?? null) ? Str::limit((string) $proposal['description_en'], 400) : null,
                __('assistant-news.fields.event_date') => $proposal['event_date'] ?? null,
                __('assistant-news.fields.display_until') => $proposal['display_until'] ?? null,
                __('assistant-news.fields.target') => $proposal['target'] ?? null,
            ], fn ($value) => filled($value)),
        ];
    }

    public function submit(User $user, array $proposal, string $token): array
    {
        if (! $this->mayCreate($user)) {
            throw ValidationException::withMessages(['draft' => __('assistant.draft.forbidden')]);
        }

        $key = 'assistant:news-draft:'.$user->id.':'.$token;

        $news = Cache::lock($key.':lock', 10)->block(5, function () use ($user, $proposal, $key): News {
            // A second confirm of the same draft: the item the first one created.
            if (($id = Cache::get($key)) && ($existing = News::query()->find($id))) {
                return $existing;
            }

            $news = $this->asUser($user, function () use ($proposal): News {
                // Every rule again (the target may have been deactivated since).
                $form = $this->checkedForm($proposal);

                return $form->persist();
            });

            Cache::put($key, $news->id, now()->addHours(self::REPLAY_TTL_HOURS));

            return $news;
        });

        $canEdit = $user->hasActivePermission('news.update');

        return [
            'message' => __('assistant-news.created'),
            'url' => $canEdit ? route('news.edit', $news) : route('news.index'),
            'link' => $canEdit ? __('assistant-news.open') : __('assistant-news.open-list'),
        ];
    }

    /** NewsCreate::canCreateNews() behind the route's news.create permission. */
    private function mayCreate(User $user): bool
    {
        return $user->hasActivePermission('news.create')
            && $user->activeRoleIs('HR', 'super-admin', 'general-manager', 'manager');
    }

    /** The page's target dropdowns (NewsForm::entities()), as ids and names. */
    private function options(array $args): array
    {
        $search = trim((string) ($args['employee_search'] ?? ''));
        $employees = $search !== '' ? $this->matchingEmployees($search) : collect();

        return array_filter([
            'target_types' => ['all', ...array_keys(self::TARGETS)],
            'cities' => $this->listed(City::class),
            'centers' => $this->listed(Center::class),
            'programs' => $this->listed(Program::class),
            'employees' => $search !== ''
                ? $employees->take(self::MAX_EMPLOYEES)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()->all()
                : null,
            'employees_truncated' => $employees->count() > self::MAX_EMPLOYEES ?: null,
            'note' => 'Use only these ids. target_type all needs no id. Images can be added later on the news page.',
        ], fn ($value) => $value !== null);
    }

    private function listed(string $type): array
    {
        return $this->entities($type)->map(fn ($entity) => ['id' => $entity->id, 'name' => $entity->name])->values()->all();
    }

    private function matchingEmployees(string $search): Collection
    {
        $needle = Str::lower($search);

        return $this->entities(Employee::class)->filter(function (Employee $employee) use ($needle): bool {
            $names = Str::lower(implode(' ', [
                $employee->first_name_en, $employee->last_name_en,
                $employee->first_name_ar, $employee->last_name_ar,
            ]));

            return Str::contains($names, $needle);
        })->values();
    }

    /** What the page's dropdown offers for a target type. */
    private function entities(string $type): Collection
    {
        $form = $this->form();
        $form->newsable_type = $type;

        return $form->entities();
    }

    /** Validates without writing: the error messages, or the proposal for the card. */
    private function draft(User $user, array $args): array
    {
        try {
            $form = $this->asUser($user, fn () => $this->checkedForm($args));
        } catch (ValidationException $e) {
            return [
                'status' => 'invalid',
                'errors' => array_values(array_unique(array_merge(...array_values($e->errors())))),
                'note' => 'Nothing was drafted. Tell the user what to change, then draft again.',
            ];
        }

        return [
            'status' => 'drafted',
            'proposal' => $this->proposal($form),
            'note' => 'Not created. Summarise the draft and ask the user to press Confirm below your answer.',
        ];
    }

    /**
     * The page's form filled from a draft and checked with its own rules,
     * plus the one check its dropdown makes: the target must be one it lists.
     *
     * @throws ValidationException
     */
    private function checkedForm(array $input): NewsForm
    {
        $form = $this->form();

        $form->title_ar = $this->text($input['title_ar'] ?? '');
        $form->title_en = $this->text($input['title_en'] ?? '') ?: null;
        $form->description_ar = $this->text($input['description_ar'] ?? '');
        $form->description_en = $this->text($input['description_en'] ?? '') ?: null;
        $form->event_date = $this->text($input['event_date'] ?? '') ?: null;
        $form->display_until = $this->text($input['display_until'] ?? '') ?: null;

        $target = $this->text($input['target_type'] ?? '');
        // An unknown name is left as it is, for the form's own rule to refuse.
        $form->newsable_type = ($target === '' || $target === 'all') ? '' : (self::TARGETS[$target] ?? $target);
        $id = $input['target_id'] ?? '';
        $form->newsable_id = $form->newsable_type === '' || ! is_scalar($id) ? '' : (string) $id;

        $form->validate(null, [], $this->attributes());

        if ($form->newsable_type !== ''
            && ! $this->entities($form->newsable_type)->contains('id', (int) $form->newsable_id)) {
            throw ValidationException::withMessages([
                'newsable_id' => __('assistant-news.errors.target'),
            ]);
        }

        return $form;
    }

    private function proposal(NewsForm $form): array
    {
        $target = array_search($form->newsable_type, self::TARGETS, true) ?: 'all';
        $entity = $target === 'all'
            ? null
            : $this->entities($form->newsable_type)->firstWhere('id', (int) $form->newsable_id);

        return [
            'title_ar' => $form->title_ar,
            'title_en' => $form->title_en,
            'description_ar' => $form->description_ar,
            'description_en' => $form->description_en,
            'event_date' => Carbon::parse($form->event_date)->format('Y-m-d'),
            'display_until' => $form->display_until ? Carbon::parse($form->display_until)->format('Y-m-d') : null,
            'target_type' => $target,
            'target_id' => $entity ? (int) $entity->id : null,
            'target' => $entity
                ? __('assistant-news.targets.'.$target).': '.$entity->name
                : __('assistant-news.targets.all'),
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return collect(['title_ar', 'title_en', 'description_ar', 'description_en', 'event_date', 'display_until'])
            ->mapWithKeys(fn (string $field) => [$field => __('assistant-news.fields.'.$field)])
            ->put('newsable_type', __('assistant-news.fields.target_type'))
            ->put('newsable_id', __('assistant-news.fields.target'))
            ->all();
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** The page's form, outside the page. Nothing here decides validity. */
    private function form(): NewsForm
    {
        return new NewsForm(new class extends Component
        {
            public function render(): string
            {
                return '<div></div>';
            }
        }, 'form');
    }

    /** Run as $user: persist() records auth()->id() as the creator. */
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
