<?php

namespace App\Livewire\Layout;

use App\Models\Employee;
use App\Support\DirectoryAccess;
use App\Support\Navigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Throwable;

/**
 * The header search (C2, C0-Q6): pages of the active role's menu and people
 * by name, in Arabic or English whatever the interface language.
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

    /**
     * Arabic letters people type interchangeably, folded to one form, plus
     * lower case: «أحمد» = «احمد», «سارة» = «ساره», «مصطفى» = «مصطفي».
     * The same folding is applied to the stored Arabic names in SQL.
     */
    private const FOLD = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ـ' => ''];

    public string $q = '';

    public bool $throttled = false;

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
        $failed = false;

        if (mb_strlen($term) >= self::MIN_LENGTH && ! $this->throttled) {
            try {
                $pages = $this->pages($term);
                $people = $this->people($term);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        return view('livewire.layout.global-search', [
            'term' => $term,
            'pages' => $pages,
            'people' => $people,
            'failed' => $failed,
            'tooShort' => $term !== '' && mb_strlen($term) < self::MIN_LENGTH,
        ]);
    }

    /** @return list<array<string, string>> */
    private function pages(string $term): array
    {
        $user = auth()->user();
        $needle = self::fold($term);

        return collect(Navigation::pages($user, Navigation::isDeliverResponsible($user)))
            ->filter(fn (array $page) => str_contains(self::fold($page['label_en']), $needle)
                || str_contains(self::fold($page['label_ar']), $needle))
            ->unique('route')
            ->take(self::MAX_PAGES)
            ->values()
            ->all();
    }

    private static function fold(string $text): string
    {
        // Short vowels and other diacritics (harakat) are dropped too.
        return mb_strtolower(strtr(preg_replace('/[\x{064B}-\x{0652}\x{0670}]/u', '', $text), self::FOLD));
    }

    /** SQL for a stored Arabic name folded like fold(); REPLACE works on MySQL and SQLite. */
    private static function foldedColumn(string $column): string
    {
        $sql = $column;

        foreach (self::FOLD as $from => $to) {
            $sql = "REPLACE({$sql}, '{$from}', '{$to}')";
        }

        return $sql;
    }

    /** People whose Arabic or English names contain every word typed. */
    private function people(string $term)
    {
        // LIKE wildcards typed by the user are not wildcards; names never contain them.
        $words = collect(preg_split('/\s+/u', str_replace(['%', '_', '\\'], '', self::fold($term))))
            ->filter(fn ($word) => $word !== '')
            ->take(4);

        if ($words->isEmpty()) {
            return collect();
        }

        $query = Employee::query()->select(DirectoryAccess::COLUMNS);

        return DirectoryAccess::constrain($query, auth()->user())
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
            ])
            ->orderBy(transColumn('first_name'))
            ->limit(self::MAX_PEOPLE)
            ->get();
    }
}
