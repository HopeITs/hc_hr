<?php

namespace App\Livewire\Layout;

use App\Models\Employee;
use App\Support\CommandPalette;
use App\Support\DirectoryAccess;
use App\Support\Navigation;
use App\Support\NavigationMemory;
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

    public string $q = '';

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
        $pages = [];
        $people = collect();
        $commands = [];
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
                $pages = $this->pages($term);
                $people = $this->people($term);
                $commands = CommandPalette::search(auth()->user(), $term, $this->otherLocaleUrl ?: null);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        return view('livewire.layout.global-search', [
            'term' => $term,
            'pages' => $pages,
            'people' => $people,
            'commands' => $commands,
            'failed' => $failed,
            'showingRecents' => $showingRecents,
            'tooShort' => $term !== '' && mb_strlen($term) < self::MIN_LENGTH,
        ]);
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
     * SQL for a stored Arabic name with SearchText's letters folded (diacritics
     * are left: REPLACE cannot match a range). Works on MySQL and SQLite.
     */
    private static function foldedColumn(string $column): string
    {
        $sql = $column;

        foreach (SearchText::FOLD as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /** People whose Arabic or English names contain every word typed. */
    private function people(string $term)
    {
        // LIKE wildcards typed by the user are not wildcards; names never contain them.
        $words = collect(preg_split('/\s+/u', str_replace(['%', '_', '\\'], '', SearchText::normalize($term))))
            ->filter(fn ($word) => $word !== '')
            ->take(4);

        if ($words->isEmpty()) {
            return collect();
        }

        return DirectoryAccess::constrain($this->directoryQuery(), auth()->user())
            // The directory's row set: people who hold a visible position.
            ->whereHas('contracts.position', fn (Builder $position) => $position->visible())
            ->where(function (Builder $names) use ($words): void {
                foreach ($words as $word) {
                    $names->where(fn (Builder $any) => $any
                        ->where('first_name_en', 'like', "%{$word}%")
                        ->orWhere('last_name_en', 'like', "%{$word}%")
                        ->orWhereRaw(self::foldedColumn('first_name_ar').' like ?', ["%{$word}%"])
                        ->orWhereRaw(self::foldedColumn('last_name_ar').' like ?', ["%{$word}%"]));
                }
            })
            ->orderBy(transColumn('first_name'))
            ->limit(self::MAX_PEOPLE)
            ->get();
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
                // The current position tells two people with the same name apart.
                'contracts' => fn ($contracts) => $contracts
                    ->select(['id', 'employee_id', 'position_id', 'start_date'])
                    ->whereHas('position', fn (Builder $position) => $position->visible())
                    ->orderByDesc('start_date')
                    ->with('position:id,title_en,title_ar'),
            ]);
    }
}
