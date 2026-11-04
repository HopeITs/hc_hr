<?php

namespace App\Livewire\News;

use App\Enums\News\NewsState;
use App\Enums\News\NewsStatus;
use App\Enums\News\NewsType;
use App\Livewire\Concerns\HasListStates;
use App\Livewire\Concerns\WithFeedback;
use App\Models\News;
use App\Models\User;
use App\Support\News\NewsAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Filters\SelectFilter;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

/**
 * The News & Events management list.
 *
 * Rows: whoever may create content (HR, super-admin, general manager,
 * managers) sees every item, as before; acting on one still needs
 * NewsAccess::canManage (a manager acts on their own items only). Anyone
 * else sees what is released and addressed to them.
 *
 * The state tabs only ever narrow query(); every lifecycle action re-loads
 * the record, re-authorizes it and re-checks its derived state.
 */
class NewsTable extends LivewireTable
{
    use HasListStates {
        clearAllFilters as protected clearListFilters;
    }
    use WithFeedback;

    public const STATE_ALL = 'all';

    /** Tabs that need a manager's view of the list (drafts and the like are never released). */
    private const MANAGER_TABS = ['draft', 'scheduled', 'published', 'expired', 'archived'];

    /** How many of the most used tags the tag filter offers. */
    private const TAG_FILTER_OPTIONS = 50;

    private const TABS = ['all', 'draft', 'scheduled', 'published', 'expired', 'archived', 'upcoming', 'today', 'past'];

    public string $sortColumn = 'published_at';

    public string $sortDirection = 'desc';

    /** The state tab (`?state=`). It only narrows query(). */
    #[Url(as: 'state')]
    public string $stateFilter = self::STATE_ALL;

    protected string $view = 'livewire.news.list.table';

    protected string $model = News::class;

    protected $listeners = ['delete', 'refreshNewsTable' => '$refresh'];

    // ------------------------------------------------------------- tabs

    public function setStateFilter(string $state): void
    {
        abort_unless(array_key_exists($state, $this->stateTabs()), 403);

        $this->stateFilter = $state;
        $this->resetPage();
    }

    /** "Clear filters" in the empty state also returns to the All tab. */
    public function clearAllFilters(): void
    {
        $this->stateFilter = self::STATE_ALL;
        $this->clearListFilters();
    }

    public function cardView(): ?string
    {
        return 'livewire.news.list._card';
    }

    // ------------------------------------------------------------- actions

    public function confirmDelete(int $id): void
    {
        $this->managedOrFail($id);

        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        $news = $this->managedOrFail((int) ($payload['id'] ?? 0));

        $news->delete();
        $this->toast();
        $this->dispatch('refreshNewsTable');
    }

    public function confirmPublishNow(int $id): void
    {
        $news = $this->managedOrFail($id);

        if (! $this->stateAllows($news, [NewsState::Draft, NewsState::Scheduled])) {
            return;
        }

        $this->confirm('publishNow', ['id' => $id],
            title: __('news.list.confirm.publish_title'),
            message: __('news.list.confirm.publish_message'),
            confirmLabel: __('news.list.actions.publish_now'),
            danger: false,
        );
    }

    /** Draft or scheduled -> published, Publish At = now. */
    public function publishNow(array $payload): void
    {
        $news = $this->managedOrFail((int) ($payload['id'] ?? 0));

        if (! $this->stateAllows($news, [NewsState::Draft, NewsState::Scheduled])) {
            return;
        }

        $this->persist($news, ['status' => NewsStatus::Published, 'published_at' => now(), 'archived_at' => null]);
        $this->toast(__('news.list.toast.published'));
    }

    public function confirmUnpublish(int $id): void
    {
        $news = $this->managedOrFail($id);

        if (! $this->stateAllows($news, [NewsState::Scheduled])) {
            return;
        }

        $this->confirm('unpublish', ['id' => $id],
            title: __('news.list.confirm.unpublish_title'),
            message: __('news.list.confirm.unpublish_message'),
            confirmLabel: __('news.list.actions.unpublish'),
            danger: false,
        );
    }

    /** Scheduled -> draft. The planned Publish At is kept for the editor. */
    public function unpublish(array $payload): void
    {
        $news = $this->managedOrFail((int) ($payload['id'] ?? 0));

        if (! $this->stateAllows($news, [NewsState::Scheduled])) {
            return;
        }

        $this->persist($news, ['status' => NewsStatus::Draft]);
        $this->toast(__('news.list.toast.unpublished'));
    }

    public function confirmArchive(int $id): void
    {
        $news = $this->managedOrFail($id);

        if (! $this->stateAllows($news, [NewsState::Published, NewsState::Expired])) {
            return;
        }

        $this->confirm('archive', ['id' => $id],
            title: __('news.list.confirm.archive_title'),
            message: __('news.list.confirm.archive_message'),
            confirmLabel: __('news.list.actions.archive'),
            danger: false,
        );
    }

    /** Published or expired -> archived (out of every feed). */
    public function archive(array $payload): void
    {
        $news = $this->managedOrFail((int) ($payload['id'] ?? 0));

        if (! $this->stateAllows($news, [NewsState::Published, NewsState::Expired])) {
            return;
        }

        $this->persist($news, ['status' => NewsStatus::Archived, 'archived_at' => now()]);
        $this->toast(__('news.list.toast.archived'));
    }

    /** Archived -> published again (its own dates decide live or expired). */
    public function restore(int $id): void
    {
        $news = $this->managedOrFail($id);

        if (! $this->stateAllows($news, [NewsState::Archived])) {
            return;
        }

        $this->persist($news, [
            'status' => NewsStatus::Published,
            'archived_at' => null,
            'published_at' => $news->published_at ?? now(),
        ]);
        $this->toast(__('news.list.toast.restored'));
    }

    /** "12 Nov 2026, 10:00 – 12:00", or an em dash when it is not an event. */
    public static function eventRange(News $news): string
    {
        if (! $news->isEvent() || ! $news->event_starts_at) {
            return '—';
        }

        $start = $news->event_starts_at;
        $end = $news->event_ends_at;
        $text = $start->translatedFormat('j M Y, H:i');

        if ($end === null) {
            return $text;
        }

        return $text.' – '.($end->isSameDay($start) ? $end->translatedFormat('H:i') : $end->translatedFormat('j M Y, H:i'));
    }

    public static function authorName(?User $user): string
    {
        return $user?->profile?->name
            ?? $user?->email
            ?? __('general.unknown');
    }

    /** @return array<string, string> tab => label, as the active role may use them */
    protected function stateTabs(): array
    {
        return once(function (): array {
            $tabs = collect(self::TABS)
                ->reject(fn (string $tab) => ! $this->managesList() && in_array($tab, self::MANAGER_TABS, true))
                ->mapWithKeys(fn (string $tab) => [$tab => __('news.list.tabs.'.$tab)]);

            return $tabs->all();
        });
    }

    /**
     * Rows per tab under the current role scope, type and tag filters
     * (search excluded). A handful of COUNTs on one table, once per render.
     *
     * @return array<string, int>
     */
    protected function stateCounts(): array
    {
        return once(function (): array {
            $counts = [];

            foreach (array_keys($this->stateTabs()) as $tab) {
                $query = $this->baseQuery()->reorder();
                $this->applyTypeFilter($query);
                $this->applyTagFilter($query);
                $this->applyStateFilter($query, $tab);
                $counts[$tab] = $query->reorder()->count();
            }

            return $counts;
        });
    }

    protected function initialize(): void
    {
        parent::initialize();

        // A tab this role may not use (stale URL, someone else's link) falls back to All.
        if (! array_key_exists($this->stateFilter, $this->stateTabs())) {
            $this->stateFilter = self::STATE_ALL;
        }
    }

    protected function isNarrowed(): bool
    {
        return $this->stateFilter !== self::STATE_ALL || $this->canClearSearch() || $this->canClearFilters();
    }

    protected function emptyStateCreate(): ?array
    {
        return NewsAccess::canCreate(auth()->user())
            ? [__('news.list.new'), route('news.create')]
            : null;
    }

    // ------------------------------------------------------------- query

    protected function query(): Builder
    {
        $query = $this->baseQuery()
            ->with(['tags', 'audienceTargets', 'creator.profile', 'media']);

        $this->applyStateFilter($query, $this->stateFilter);

        return $query;
    }

    /** Every row the active role may list, before tabs, filters and search. */
    protected function baseQuery(): Builder
    {
        $user = auth()->user();

        return News::query()
            ->when(! $this->managesList(), fn (Builder $q) => $q->released()->visibleTo($user));
    }

    protected function applyStateFilter(Builder $query, string $tab): void
    {
        match ($tab) {
            'draft', 'scheduled', 'published', 'expired', 'archived' => $query->inState($tab),
            'upcoming' => $query->live()->upcomingEvents(),
            'today' => $query->live()->todayEvents(),
            'past' => $query->released()->pastEvents(),
            default => null,
        };
    }

    protected function applyTypeFilter(Builder $query): void
    {
        $type = $this->filters['type'] ?? null;

        if (is_string($type) && in_array($type, NewsType::values(), true)) {
            $query->ofType($type);
        }
    }

    protected function applyTagFilter(Builder $query): void
    {
        $tag = $this->filters['tag'] ?? null;

        if (is_string($tag) && trim($tag) !== '' && mb_strlen($tag) <= News::MAX_TAG_LENGTH) {
            $query->tagged($tag);
        }
    }

    protected function filters(): array
    {
        $filters = [
            SelectFilter::make(__('news.list.columns.type'), 'type')
                ->options(collect(NewsType::cases())->mapWithKeys(fn (NewsType $t) => [$t->value => $t->label()])->all())
                ->filterUsing(function (Builder $query, mixed $value): void {
                    if (is_string($value) && in_array($value, NewsType::values(), true)) {
                        $query->ofType($value);
                    }
                }),
        ];

        // Tags are free text: the filter offers the tags in use (most used
        // first) and matches case-insensitively. It only narrows query();
        // the tab counts follow it too (applyTagFilter).
        $tags = once(fn () => News::popularTags(self::TAG_FILTER_OPTIONS));

        if ($tags !== []) {
            $filters[] = SelectFilter::make(__('news.list.columns.tags'), 'tag')
                ->options(collect($tags)->mapWithKeys(fn (string $tag) => [$tag => $tag])->all())
                ->filterUsing(function (Builder $query, mixed $value): void {
                    if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= News::MAX_TAG_LENGTH) {
                        $query->tagged($value);
                    }
                });
        }

        return $filters;
    }

    /** Drafts and scheduled items open in preview; the rest on the details page. */
    protected function link(Model $model): ?string
    {
        /** @var News $model */
        return $this->viewUrl($model);
    }

    protected function viewUrl(News $news): ?string
    {
        $unreleased = in_array($news->state(), [NewsState::Draft, NewsState::Scheduled], true);

        if (! $unreleased) {
            return route('news.show', ['news' => $news->getKey()]);
        }

        return NewsAccess::canManage(auth()->user(), $news)
            ? route('news.preview', ['news' => $news->getKey()])
            : null;
    }

    protected function globalSearchColumns(): array
    {
        return ['title_ar', 'title_en', 'description_ar', 'description_en'];
    }

    /** May the active role see the whole list (drafts included)? */
    protected function managesList(): bool
    {
        return once(fn () => NewsAccess::canCreate(auth()->user()));
    }

    // ------------------------------------------------------------- columns

    protected function columns(): array
    {
        return [
            Column::make(__('news.list.columns.title'), transColumn('title'))
                ->displayUsing(fn ($value, Model $m): string => view('livewire.news.list._title', ['news' => $m])->render())
                ->asHtml()
                ->sortable()
                ->searchable(fn (Builder $query, $value) => $query->where(fn (Builder $q) => $q
                    ->where('news.title_ar', 'like', '%'.$value.'%')
                    ->orWhere('news.title_en', 'like', '%'.$value.'%'))),
            Column::make(__('news.list.columns.tags'), function (Model $m): string {
                /** @var News $m */
                return view('livewire.news.list._tags', ['tags' => $m->tagNames()])->render();
            })->asHtml(),
            Column::make(__('news.list.columns.audience'), function (Model $m): string {
                /** @var News $m */
                return $m->audienceSummary();
            }),
            Column::make(__('news.list.columns.publish_at'), 'published_at')
                ->displayUsing(fn ($value, Model $m): string => view('livewire.news.list._publish-at', ['news' => $m])->render())
                ->asHtml()
                ->sortable(),
            Column::make(__('news.list.columns.event_date'), 'event_starts_at')
                ->displayUsing(fn ($value, Model $m): string => self::eventRange($m))
                ->sortable(),
            Column::make(__('news.list.columns.status'), function (Model $m): string {
                return view('livewire.news.list._status', ['news' => $m])->render();
            })->asHtml(),
            Column::make(__('news.list.columns.author'), function (Model $m): string {
                /** @var News $m */
                return self::authorName($m->creator);
            }),
            Column::make(__('news.list.columns.actions'), function (Model $m): string {
                /** @var News $m */
                return $this->rowActions($m);
            })->asHtml()->clickable(false),
        ];
    }

    /** The View link and the More menu of one row (or the menu only, on a card). */
    protected function rowActions(News $news, string $context = 'row'): string
    {
        return view('livewire.news.list._row-actions', [
            'news' => $news,
            'context' => $context,
            'state' => $news->state(),
            'canManage' => NewsAccess::canManage(auth()->user(), $news),
            'viewUrl' => $this->viewUrl($news),
        ])->render();
    }

    private function managedOrFail(int $id): News
    {
        $news = News::query()->findOrFail($id);
        abort_unless(NewsAccess::canManage(auth()->user(), $news), 403);

        return $news;
    }

    /** @param  list<NewsState>  $states */
    private function stateAllows(News $news, array $states): bool
    {
        if (in_array($news->state(), $states, true)) {
            return true;
        }

        // The row was out of date (another tab, the clock): say so and redraw.
        $this->toast(__('news.list.toast.unavailable'), 'info');
        $this->dispatch('refreshNewsTable');

        return false;
    }

    private function persist(News $news, array $attributes): void
    {
        $news->fill($attributes);
        $news->updated_by = auth()->id();
        $news->save();

        $this->dispatch('refreshNewsTable');
    }
}
