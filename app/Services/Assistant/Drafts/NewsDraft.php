<?php

namespace App\Services\Assistant\Drafts;

use App\Enums\News\AudienceType;
use App\Enums\News\NewsType;
use App\Livewire\Forms\NewsForm;
use App\Models\Center;
use App\Models\City;
use App\Models\Department;
use App\Models\Employee;
use App\Models\News;
use App\Models\Program;
use App\Models\User;
use App\Support\News\NewsAccess;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * A News & Events item drafted from the chat, created through the page's own
 * NewsForm: the same rules (NewsForm::rules(), including "only the ids the
 * page offers"), the same option lists (NewsForm::optionsFor()) and the same
 * persist(), so the creator, status, published_at, audience rows and the
 * activity log entry are exactly what the page's Publish button writes.
 * Drafts from the chat are always published immediately; images and
 * scheduling are done later on the item's edit page.
 *
 * Tags are free text (what the item is about) and never decide who sees it.
 *
 * Who may create mirrors NewsCreate: the news.create permission (the
 * route's middleware) and NewsAccess::canCreate().
 */
class NewsDraft implements RecordDraft
{
    /** The option lists by the names the model uses, keyed to their model. */
    private const LISTS = [
        'cities' => City::class,
        'centers' => Center::class,
        'programs' => Program::class,
        'departments' => Department::class,
        'employees' => Employee::class,
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

        $audiences = array_column(AudienceType::cases(), 'value');

        return [
            [
                'name' => 'get_news_options',
                'description' => 'The ids a News & Events item can use for its audience (WHO may see it: everyone, or selected cities, centers, programs, departments or employees), plus suggested tags (tags already in use and city, center and program names). Lists cities, centers, programs and departments; employees only when employee_search is given. Call it before drafting an item addressed to something specific.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'employee_search' => ['type' => 'string', 'description' => 'Optional: part of an employee\'s name, to find the id of an employee the item is about or addressed to.'],
                    ],
                ],
            ],
            [
                'name' => 'draft_news',
                'description' => 'Checks a new News & Events item with every rule of the news page and prepares it for the user to confirm. It never publishes anything by itself; on the user\'s confirmation the item is published immediately. The Arabic title and description are required: write them in Arabic (translate the user\'s text if needed) and show them to the user. Keep tags (what it is about) and the audience (who sees it) apart: an item tagged "Aleppo" can still be for all employees. Images and scheduling cannot be set from the chat; the user can do both later on the item\'s edit page. Returns either the errors to tell the user, or the checked draft. Only the user can create it, with the Confirm button shown under your answer.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => NewsType::values(), 'description' => 'news (something that happened, the default), announcement (important organizational information) or event (something taking place at a date and time).'],
                        'title_ar' => ['type' => 'string', 'description' => 'The title in Arabic (at most 255 characters).'],
                        'title_en' => ['type' => 'string', 'description' => 'Optional: the title in English (at most 255 characters).'],
                        'description_ar' => ['type' => 'string', 'description' => 'The text in Arabic.'],
                        'description_en' => ['type' => 'string', 'description' => 'Optional: the text in English.'],
                        'event_starts_at' => ['type' => 'string', 'description' => 'Events only, and then required: when the event starts, as YYYY-MM-DD HH:MM (local time). Ask for it if the user did not say.'],
                        'event_ends_at' => ['type' => 'string', 'description' => 'Events only, optional: when it ends, as YYYY-MM-DD HH:MM, not before the start.'],
                        'display_until' => ['type' => 'string', 'description' => 'Optional: when the item leaves the active feeds, as YYYY-MM-DD (end of that day) or YYYY-MM-DD HH:MM. Must be in the future and, for an event, not before it starts.'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional: up to '.News::MAX_TAGS.' short labels describing what the item is about (e.g. "Aleppo", "Study Zone", "Training"), at most '.News::MAX_TAG_LENGTH.' characters each. Prefer the tag_suggestions from get_news_options when one fits; any text is allowed. Tags never decide who sees the item.'],
                        'audience_type' => ['type' => 'string', 'enum' => $audiences, 'description' => 'Who may see the item: all (every employee, the default) or selected cities, centers, programs, departments or employees.'],
                        'audience_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Required unless audience_type is all: at least one id of that kind, from get_news_options.'],
                    ],
                    'required' => ['title_ar', 'description_ar'],
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
        $type = NewsType::tryFrom((string) ($proposal['type'] ?? '')) ?? NewsType::News;

        return [
            'title' => __('assistant-news.title'),
            'icon' => $type->icon(),
            'rows' => array_filter([
                __('assistant-news.fields.type') => $type->label(),
                __('assistant-news.fields.title_ar') => $proposal['title_ar'] ?? null,
                __('assistant-news.fields.title_en') => $proposal['title_en'] ?? null,
                __('assistant-news.fields.description_ar') => filled($proposal['description_ar'] ?? null) ? Str::limit((string) $proposal['description_ar'], 400) : null,
                __('assistant-news.fields.description_en') => filled($proposal['description_en'] ?? null) ? Str::limit((string) $proposal['description_en'], 400) : null,
                __('assistant-news.fields.event') => $proposal['event'] ?? null,
                __('assistant-news.fields.display_until') => $proposal['display_until'] ?? null,
                __('assistant-news.fields.tags') => is_array($proposal['tags'] ?? null) && $proposal['tags'] !== []
                    ? implode(app()->getLocale() === 'ar' ? '، ' : ', ', array_map('strval', $proposal['tags']))
                    : null,
                __('assistant-news.fields.audience') => $proposal['audience'] ?? null,
                __('assistant-news.fields.publication') => __('assistant-news.publish_now'),
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
                // Every rule again (a target may have been deactivated since).
                $form = $this->checkedForm($proposal);

                return $form->persist(null, NewsForm::INTENT_PUBLISH);
            });

            Cache::put($key, $news->id, now()->addHours(self::REPLAY_TTL_HOURS));

            return $news;
        });

        $canView = NewsAccess::canView($user, $news);

        return [
            'message' => __('assistant-news.created'),
            'url' => $canView ? route('news.show', $news) : route('news.index'),
            'link' => $canView ? __('assistant-news.open') : __('assistant-news.open-list'),
        ];
    }

    /** NewsAccess::canCreate() behind the route's news.create permission. */
    private function mayCreate(User $user): bool
    {
        return $user->hasActivePermission('news.create') && NewsAccess::canCreate($user);
    }

    /** The page's option lists (NewsForm::optionsFor()), as ids and names. */
    private function options(array $args): array
    {
        $search = trim((string) ($args['employee_search'] ?? ''));
        $employees = $search !== '' ? $this->matchingEmployees($search) : collect();

        $lists = [];
        foreach (self::LISTS as $name => $class) {
            if ($class !== Employee::class) {
                $lists[$name] = $this->listed($class);
            }
        }

        return array_filter([
            'audience_types' => array_column(AudienceType::cases(), 'value'),
            ...$lists,
            'employees' => $search !== ''
                ? $employees->take(self::MAX_EMPLOYEES)->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values()->all()
                : null,
            'employees_truncated' => $employees->count() > self::MAX_EMPLOYEES ?: null,
            'tag_suggestions' => NewsForm::tagSuggestions(),
            'note' => 'Use only these ids, for audience_ids (one or more, matching audience_type). audience_type all needs no id. Tags are free text; tag_suggestions only lists common ones.',
        ], fn ($value) => $value !== null);
    }

    private function listed(string $class): array
    {
        return NewsForm::optionsFor($class)->map(fn ($entity) => ['id' => $entity->id, 'name' => $entity->name])->values()->all();
    }

    private function matchingEmployees(string $search): Collection
    {
        $needle = Str::lower($search);

        return NewsForm::optionsFor(Employee::class)->filter(function (Employee $employee) use ($needle): bool {
            $names = Str::lower(implode(' ', [
                $employee->first_name_en, $employee->last_name_en,
                $employee->first_name_ar, $employee->last_name_ar,
            ]));

            return Str::contains($names, $needle);
        })->values();
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
     * as a new item published immediately.
     *
     * @throws ValidationException
     */
    private function checkedForm(array $input): NewsForm
    {
        $form = $this->form();

        $form->type = $this->text($input['type'] ?? '') ?: NewsType::News->value;
        $form->title_ar = $this->text($input['title_ar'] ?? '');
        $form->title_en = $this->text($input['title_en'] ?? '') ?: null;
        $form->description_ar = $this->text($input['description_ar'] ?? '');
        $form->description_en = $this->text($input['description_en'] ?? '') ?: null;
        // event_date: the name this tool used before events had a time.
        $form->event_starts_at = $this->text($input['event_starts_at'] ?? ($input['event_date'] ?? '')) ?: null;
        $form->event_ends_at = $this->text($input['event_ends_at'] ?? '') ?: null;
        $form->display_until = $this->text($input['display_until'] ?? '') ?: null;

        // Tags: a list of strings (a single comma-separated string is split).
        $tags = $input['tags'] ?? [];
        if (is_string($tags)) {
            $tags = preg_split('/[,،]/u', $tags) ?: [];
        }
        $form->tags = is_array($tags)
            ? array_values(array_map(fn ($v) => (string) $v, array_filter($tags, 'is_scalar')))
            : [];

        // An unknown name is left as it is, for the form's own rule to refuse.
        $form->audience_type = $this->text($input['audience_type'] ?? '') ?: AudienceType::All->value;
        $ids = $input['audience_ids'] ?? [];
        $form->audience_ids = is_array($ids)
            ? array_values(array_map(fn ($v) => (string) $v, array_filter($ids, 'is_scalar')))
            : [];

        $form->publish_mode = NewsForm::MODE_NOW;
        $form->publish_at = null;
        $form->photo = null;

        $form->check(null, $this->attributes());

        return $form;
    }

    private function proposal(NewsForm $form): array
    {
        $type = $form->typeEnum();
        $audience = $form->audienceEnum();

        $audienceNames = $audience->needsTargets()
            ? $form->audienceOptions()->whereIn('id', array_map('intval', $form->audience_ids))->pluck('name')
            : collect();

        $start = $type->isEvent() ? $form->parseMoment($form->event_starts_at) : null;
        $end = $type->isEvent() ? $form->parseMoment($form->event_ends_at) : null;
        $until = $form->parseDisplayUntil($form->display_until);

        return [
            'type' => $type->value,
            'title_ar' => $form->title_ar,
            'title_en' => $form->title_en,
            'description_ar' => $form->description_ar,
            'description_en' => $form->description_en,
            'event_starts_at' => $start?->format('Y-m-d H:i'),
            'event_ends_at' => $end?->format('Y-m-d H:i'),
            // As given: a plain date keeps meaning "until the end of that day" on replay.
            'display_until' => $until ? $form->display_until : null,
            'tags' => News::normalizeTags($form->tags),
            'audience_type' => $audience->value,
            'audience_ids' => array_map('intval', $form->audience_ids),
            // For the card only (checkedForm() ignores them).
            'event' => $start ? $start->format('Y-m-d H:i').($end ? ' – '.$end->format('Y-m-d H:i') : '') : null,
            'audience' => $audience->needsTargets()
                ? $audience->label().': '.$audienceNames->implode('، ')
                : $audience->label(),
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return collect(['type', 'title_ar', 'title_en', 'description_ar', 'description_en', 'event_starts_at', 'event_ends_at', 'display_until', 'tags', 'audience_type', 'audience_ids'])
            ->mapWithKeys(fn (string $field) => [$field => __('assistant-news.fields.'.$field)])
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
