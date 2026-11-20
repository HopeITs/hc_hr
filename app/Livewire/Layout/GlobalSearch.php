<?php

namespace App\Livewire\Layout;

use App\Models\Employee;
use App\Models\MessageTemplate;
use App\Services\Assistant\Assistant;
use App\Services\MessageTemplateRenderer;
use App\Services\WorkPresence;
use App\Support\Announcements\AnnouncementTemplates;
use App\Support\CommandPalette;
use App\Support\DirectoryAccess;
use App\Support\Navigation;
use App\Support\NavigationMemory;
use App\Support\Search\NameMatch;
use App\Support\Search\SmartSearch;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Throwable;

/**
 * The header search (C2, C0-Q6): pages of the active role's menu and people
 * by name, in Arabic or English whatever the interface language, and (prompt
 * 10) commands: what the role's New menu may start, plus a few for the page
 * itself (CommandPalette).
 *
 * Smart search adds, beside those:
 *  - more kinds of result (SmartSearch: positions, contracts, leave requests, hiring requests,
 *    applicants, mailing lists, folders, news), each through the scope of the page that lists it;
 *  - people by employee number, phone or email as well as by name, the best match first, and
 *    a near match when the typing has a slip («mohamad» finds «Mohammad»);
 *  - quick actions on a person: email (with their direct manager on CC), WhatsApp, a message
 *    template;
 *  - a type filter (chips), and an "Ask the assistant" line when the text reads as a question.
 *
 * People are the Directory tier and nothing more (DirectoryAccess, A2):
 * the same row set the directory list shows (constrain(): former employees
 * only for the roles allowed to know they existed) and only Directory columns
 * are selected, so no private or financial field is loaded, let alone sent.
 * A result opens the profile page, which checks the viewer's cell again.
 *
 * Nothing is cached: results are worked out on every render from the
 * current term and the *active* role, so a role switch can never show the
 * previous role's results.
 */
class GlobalSearch extends Component
{
    public const MIN_LENGTH = 2;

    public const MAX_PEOPLE = 8;

    public const MAX_PAGES = 6;

    /** Searches per user per minute before the box asks them to wait. */
    public const PER_MINUTE = 60;

    /** How alike a name must be to count as a near match when nothing matched exactly. */
    public const NEAR_MATCH = 0.74;

    public string $q = '';

    /** 'all', or one kind of result (see chips()): the box then searches only that. */
    public string $type = 'all';

    /**
     * This page in the other language, for the "Switch language" command. Kept
     * from the page request: a later update runs on Livewire's update route,
     * whose URL is not the page's.
     */
    #[Locked]
    public string $otherLocaleUrl = '';

    public bool $throttled = false;

    /**
     * Recents (prompt 9) are loaded only once the box is opened with nothing
     * typed, so a page load does not pay for a list nobody looked at.
     */
    public bool $recents = false;

    public function mount(): void
    {
        $other = app()->getLocale() === 'ar' ? 'en' : 'ar';
        $this->otherLocaleUrl = (string) LaravelLocalization::getLocalizedURL($other, null, [], true);
    }

    /** Opened with an empty box: show recent pages and people. A self-scoped read. */
    public function showRecents(): void
    {
        $this->recents = true;
    }

    /** Narrow the search to one kind of result, or back to all. A self-scoped read. */
    public function setType(string $type): void
    {
        $this->type = in_array($type, $this->typeKeys(), true) ? $type : 'all';
    }

    /**
     * The message templates, each already filled in for this person, when "Template" is clicked
     * on a result. Only for who may use templates, and only a person the directory shows them.
     *
     * @return list<array{name: string, channel: string, url: string}>
     */
    public function templatesFor(int $employeeId): array
    {
        $user = auth()->user();

        abort_unless($user?->hasActivePermission('message-templates.use'), 403);

        $employee = DirectoryAccess::constrain($this->directoryQuery(), $user)->whereKey($employeeId)->first();

        if (! $employee) {
            return [];
        }

        $renderer = app(MessageTemplateRenderer::class);

        $saved = MessageTemplate::query()->active()->orderBy('channel')->orderBy('name')->get()
            ->map(fn (MessageTemplate $template) => ['name' => $template->name, 'channel' => $template->channel, 'url' => $renderer->url($template, $employee, $user)])
            ->filter(fn (array $row) => $row['url'] !== null)
            ->values()
            ->all();

        // The ready-made announcements about one person, opened in the composer with the
        // person's Arabic name already in (the wording is Arabic).
        $announcements = collect(['welcome', 'volunteer_resignation', 'administrative_change'])
            ->map(fn (string $key) => AnnouncementTemplates::find($key))
            ->filter()
            ->map(fn ($template) => [
                'name' => $template->label(),
                'channel' => 'announcement',
                'url' => null,
                'compose' => ['template' => $template->key(), 'prefill' => ['name' => $employee->nameForLocale('ar')]],
            ])
            ->values()
            ->all();

        return [...$saved, ...$announcements];
    }

    /** Each new term counts towards the per-user limit. Livewire refuses direct calls to updated*. */
    public function updatedQ(): void
    {
        $this->q = mb_substr($this->q, 0, 80);

        if (mb_strlen(trim($this->q)) < self::MIN_LENGTH) {
            $this->throttled = false;

            return;
        }

        $this->throttled = ! RateLimiter::attempt('global-search:'.auth()->id(), self::PER_MINUTE, fn () => true);
    }

    public function render()
    {
        $term = trim($this->q);
        $user = auth()->user();
        $type = in_array($this->type, $this->typeKeys(), true) ? $this->type : 'all';
        $single = $type !== 'all';
        $pages = [];
        $people = collect();
        $commands = [];
        $groups = [];
        $failed = false;
        $showingRecents = false;

        if ($term === '' && $this->recents) {
            try {
                [$pages, $people] = $this->recentItems();
                $showingRecents = true;
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        } elseif (mb_strlen($term) >= self::MIN_LENGTH && ! $this->throttled) {
            try {
                if (! $single || $type === 'pages') {
                    $pages = $this->pages($term);
                }

                if (! $single || $type === 'people') {
                    $people = $this->people($term);
                }

                if (! $single || $type === 'commands') {
                    $commands = CommandPalette::search($user, $term, $this->otherLocaleUrl ?: null);
                }

                if (! in_array($type, ['pages', 'people', 'commands'], true)) {
                    $found = SmartSearch::run($user, $term, $type, $single ? 8 : 4);
                    $groups = $found['groups'];
                    $failed = $found['failed'];
                }
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        // A nicety: a failure here leaves the results standing, without dots.
        $presence = [];

        if ($people->isNotEmpty()) {
            try {
                $presence = app(WorkPresence::class)->for(auth()->user(), $people->pluck('id'));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return view('livewire.layout.global-search', [
            'term' => $term,
            'pages' => $pages,
            'people' => $people,
            'presence' => $presence,
            'commands' => $commands,
            'groups' => $groups,
            'type' => $type,
            'chips' => mb_strlen($term) >= self::MIN_LENGTH ? $this->chips() : [],
            'ask' => $this->assistantRow($term, $type),
            'failed' => $failed,
            'showingRecents' => $showingRecents,
            'tooShort' => $term !== '' && mb_strlen($term) < self::MIN_LENGTH,
        ]);
    }

    /** A question mark (either script), a question word to start, or a sentence of four words or more. */
    public static function looksLikeQuestion(string $term): bool
    {
        if (preg_match('/[?؟]/u', $term) === 1) {
            return true;
        }

        $words = array_values(array_filter(explode(' ', SearchText::normalize($term)), fn (string $word) => $word !== ''));

        if (count($words) >= 4) {
            return true;
        }

        // Question words and verbs that ask for a list, in both languages (folded: متى = متي, أين = اين).
        return in_array($words[0] ?? '', [
            'who', 'what', 'when', 'where', 'which', 'how', 'why', 'list', 'show', 'count', 'find', 'tell',
            'من', 'ما', 'ماذا', 'كم', 'هل', 'متي', 'اين', 'كيف', 'لماذا', 'اعرض', 'اظهر', 'اذكر', 'ابحث',
        ], true);
    }

    /**
     * The last pages and profiles this person opened, checked again now:
     * pages through the active role's menu, people through the directory
     * scope (NavigationMemory), so a lost page or an out-of-scope colleague
     * is never offered, whatever was recorded.
     *
     * @return array{0: list<array<string, string>>, 1: Collection}
     */
    private function recentItems(): array
    {
        $user = auth()->user();
        $allowed = Navigation::shortcuts($user, Navigation::isDeliverResponsible($user));

        $pages = array_map(
            fn (string $id) => ['route' => $allowed[$id]['route'], 'label' => $allowed[$id]['label'], 'area' => ''],
            NavigationMemory::recentPages($user, $allowed),
        );

        return [$pages, NavigationMemory::recentPeople($user, $this->directoryQuery())];
    }

    /** @return list<array<string, string>> */
    private function pages(string $term): array
    {
        $user = auth()->user();
        $needle = SearchText::normalize($term);

        return collect(Navigation::pages($user, Navigation::isDeliverResponsible($user)))
            ->filter(fn (array $page) => str_contains(SearchText::normalize($page['label_en']), $needle)
                || str_contains(SearchText::normalize($page['label_ar']), $needle))
            ->unique('route')
            ->take(self::MAX_PAGES)
            ->values()
            ->all();
    }

    /**
     * People found by what was typed:
     *  - an email (has an @): the work email;
     *  - only digits, spaces, + and dashes (three or more digits): the employee number, or a phone;
     *  - otherwise the name, every word, Arabic or English.
     * Name matches come best first (a name that starts with the word before one that only
     * contains it); when fewer than three matched, near matches are added.
     */
    private function people(string $term): Collection
    {
        $user = auth()->user();
        $words = SearchText::words($term);

        if ($words === []) {
            return collect();
        }

        $raw = trim($term);
        $digits = (string) preg_replace('/\D+/', '', $raw);
        $isEmail = str_contains($raw, '@');
        $isNumber = ! $isEmail && strlen($digits) >= 3 && preg_match('/^[\d\s+\-()]+$/', $raw) === 1;

        // The directory's row set: people who hold a visible position.
        $query = DirectoryAccess::constrain($this->directoryQuery(), $user)
            ->whereHas('contracts.position', fn (Builder $position) => $position->visible());

        if ($isEmail) {
            $query->whereHas('account', fn (Builder $account) => $account->where('email', 'like', NameMatch::like($raw)));
        } elseif ($isNumber) {
            // The last digits, so +963 944…, 0944… and 944… are the same number.
            $tail = substr($digits, -min(9, strlen($digits)));

            $query->where(function (Builder $any) use ($digits, $tail): void {
                if (strlen($digits) <= 6) {
                    $any->orWhere('employees.id', (int) $digits);
                }

                foreach (['work_phone', 'whatsapp_work'] as $column) {
                    $any->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '+', ''), '(', '') like ?", ['%'.$tail.'%']);
                }
            });
        } else {
            NameMatch::employees($query, $term);
        }

        $found = $query->limit(30)->get();

        if (! $isEmail && ! $isNumber) {
            $found = $found->sortBy(fn (Employee $employee) => [$this->rank($employee, $words), mb_strtolower((string) $employee->name)])->values();

            if ($found->count() < 3 && mb_strlen(SearchText::normalize($term)) >= 4) {
                $found = $found->concat($this->nearMatches($term, $found->pluck('id')->all()));
            }
        }

        return $this->withActions($found->take(self::MAX_PEOPLE)->values());
    }

    /** 0 when every word starts a name, higher the more words only appear inside one. */
    private function rank(Employee $employee, array $words): int
    {
        $names = array_map(fn (?string $name) => SearchText::normalize((string) $name), [
            $employee->first_name_en, $employee->last_name_en, $employee->first_name_ar, $employee->last_name_ar,
        ]);
        $score = 0;

        foreach ($words as $word) {
            $starts = collect($names)->contains(fn (string $name) => $name !== '' && str_starts_with($name, $word));
            $score += $starts ? 0 : 1;
        }

        return $score;
    }

    /**
     * Directory people whose name is close to what was typed, for a slip in the typing. The pool is
     * read as bare Directory columns; only the few chosen are loaded in full.
     *
     * @param  list<int>  $skip
     */
    private function nearMatches(string $term, array $skip): Collection
    {
        $user = auth()->user();
        $needle = SearchText::normalize($term);

        $pool = DirectoryAccess::constrain(Employee::query()->select(DirectoryAccess::COLUMNS), $user)
            ->whereHas('contracts.position', fn (Builder $position) => $position->visible())
            ->whereNotIn('employees.id', $skip)
            ->limit(600)
            ->get();

        $scores = [];

        foreach ($pool as $employee) {
            $best = 0.0;

            foreach ([
                $employee->first_name_en.' '.$employee->last_name_en, $employee->first_name_ar.' '.$employee->last_name_ar,
                $employee->first_name_en, $employee->last_name_en, $employee->first_name_ar, $employee->last_name_ar,
            ] as $name) {
                $best = max($best, SearchText::similarity($needle, (string) $name));
            }

            if ($best >= self::NEAR_MATCH) {
                $scores[$employee->id] = $best;
            }
        }

        if ($scores === []) {
            return collect();
        }

        arsort($scores);
        $ids = array_slice(array_keys($scores), 0, self::MAX_PEOPLE);

        return DirectoryAccess::constrain($this->directoryQuery(), $user)->whereIn('employees.id', $ids)->get()
            ->sortByDesc(fn (Employee $employee) => $scores[$employee->id])
            ->each(fn (Employee $employee) => $employee->setAttribute('near_match', true))
            ->values();
    }

    /**
     * Quick actions on each person: email with their direct manager on CC, WhatsApp, and (to who
     * may use them) a message template. Work contact only; the managers' emails come in one batch.
     */
    private function withActions(Collection $people): Collection
    {
        if ($people->isEmpty()) {
            return $people;
        }

        $user = auth()->user();
        $cc = Employee::directManagerEmails($people->all());
        $renderer = app(MessageTemplateRenderer::class);
        // The ready-made announcements are always there for who may use templates (a welcome
        // or a resignation needs no address of the person's own).
        $templates = $user->hasActivePermission('message-templates.use');

        return $people->each(function (Employee $employee) use ($cc, $renderer, $templates): void {
            $actions = [];
            $mail = $employee->mailtoUrl($cc[$employee->id] ?? null);
            $number = $renderer->whatsappNumber($employee);

            if ($mail) {
                $actions[] = ['kind' => 'mail', 'label' => __('smart_search.actions.email'), 'href' => $mail];
            }

            if ($number) {
                $actions[] = ['kind' => 'external', 'label' => 'WhatsApp', 'href' => 'https://wa.me/'.$number];
            }

            if ($templates) {
                $actions[] = ['kind' => 'templates', 'label' => __('smart_search.actions.template'), 'id' => $employee->id];
            }

            $employee->setAttribute('search_actions', $actions);
        });
    }

    /** @return list<string> every type the box accepts for the active role */
    private function typeKeys(): array
    {
        return ['all', 'people', 'pages', 'commands', ...SmartSearch::types(auth()->user())];
    }

    /** @return list<array{key: string, label: string}> */
    private function chips(): array
    {
        return array_map(fn (string $key) => ['key' => $key, 'label' => __('smart_search.types.'.$key)], $this->typeKeys());
    }

    /**
     * "Ask the assistant" when the text reads as a question and the assistant is on. It only
     * opens the assistant page with the question in its box: nothing is sent to the model until
     * the person presses Send there.
     *
     * @return array{href: string}|null
     */
    private function assistantRow(string $term, string $type): ?array
    {
        if ($type !== 'all' || mb_strlen($term) < 6 || ! self::looksLikeQuestion($term)) {
            return null;
        }

        try {
            if (! app(Assistant::class)->isAvailable()) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return ['href' => route('assistant', ['q' => $term])];
    }

    /** Directory columns and what a result line shows; the caller adds the scope. */
    private function directoryQuery(): Builder
    {
        return Employee::query()->select(DirectoryAccess::COLUMNS)
            // "Former" only for someone who actually held a contract; a volunteer
            // still awaiting a first one is inactive too (G-C-13 / F15).
            ->withExists(['realContracts as was_employed'])
            ->with([
                'city:id,name_en,name_ar',
                // The work email, for "email" on a result.
                DirectoryAccess::ACCOUNT_COLUMNS,
                // The current position tells two people with the same name apart.
                'contracts' => fn ($contracts) => $contracts
                    ->select(['id', 'employee_id', 'position_id', 'start_date'])
                    ->whereHas('position', fn (Builder $position) => $position->visible())
                    ->orderByDesc('start_date')
                    ->with('position:id,title_en,title_ar'),
            ]);
    }
}
