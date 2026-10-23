<?php

namespace App\Livewire\News;

use App\Livewire\Concerns\WithFeedback;
use App\Models\News;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use RamonRietdijk\LivewireTables\Columns\Column;
use RamonRietdijk\LivewireTables\Enums\Direction;
use RamonRietdijk\LivewireTables\Livewire\LivewireTable;

class NewsTable extends LivewireTable
{
    use WithFeedback;

    protected string $model = News::class;

    protected $listeners = ['delete', 'refreshNewsTable' => '$refresh'];

    public function confirmDelete(int $id): void
    {
        $news = News::findOrFail($id);
        abort_unless($this->canManageNews($news), 403);

        $this->confirm('delete', ['id' => $id]);
    }

    public function delete(array $payload): void
    {
        $news = News::findOrFail($payload['id']);
        abort_unless($this->canManageNews($news), 403);

        $news->delete();
        $this->toast();
        $this->dispatch('refreshNewsTable');
    }

    protected function link(Model $model): ?string
    {
        /** @var News $model */
        return $this->canManageNews($model) ? route('news.edit', ['news' => $model->getKey()]) : null;
    }

    protected function query(): Builder
    {
        $employee = auth()->user()?->profile;

        return News::query()
            ->with(['newsable', 'creator.profile', 'updater.profile'])
            ->when(
                $employee
                    && ! ($this->canManageAllNews() || $this->isManager())
                    && method_exists(News::class, 'scopeVisibleTo'),
                fn (Builder $q) => $q->visibleTo($employee),
            );
    }

    protected function globalSearchColumns(): array
    {
        return ['title_ar', 'description_ar'];
    }

    protected function columns(): array
    {
        $showActions = $this->canManageAllNews() || $this->isManager();
        $titleColumn = 'title_ar';

        return [
            Column::make('ID', 'id')->sortable(),
            Column::make(__('general.title'), $titleColumn)
                ->displayUsing(function ($value, Model $m): string {
                    /** @var News $m */
                    return $m->localizedTitle();
                })
                ->sortable()
                ->searchable(),
            Column::make(__('general.target_group'), 'newsable_name')
                ->sortable(fn (Builder $query, Direction $direction) => $query->orderBy('newsable_type', $direction->value)),
            Column::make(__('general.display_until'), 'display_until')
                ->displayUsing(fn ($v) => $v ? $v->format('Y-m-d') : __('general.always_display'))
                ->sortable(),
            Column::make(__('general.event_date'), 'event_date')
                ->displayUsing(fn ($v) => $v ? $v->format('Y-m-d') : __('general.na'))
                ->sortable(),
            Column::make(__('general.published_at'), 'published_at')
                ->displayUsing(fn ($v) => optional($v)->diffForHumans())
                ->sortable(),
            Column::make(__('general.created_by'), function (Model $m): string {
                /** @var News $m */
                return $this->authorName($m->creator);
            }),
            Column::make(__('general.updated_by'), function (Model $m): string {
                /** @var News $m */
                return $this->authorName($m->updater);
            }),
            Column::make(__('general.actions'), function (Model $m): string {
                /** @var News $m */
                $id = $m->getKey();

                if (! $this->canManageNews($m)) {
                    return '';
                }

                $editUrl = route('news.edit', ['news' => $id]);
                $editBtn = Blade::render('<x-table.edit-btn href="'.$editUrl.'" />');
                $deleteBtn = Blade::render(
                    '<x-table.delete-btn wire:click="confirmDelete('.$id.')" :modelId="'.$id.'" />',
                );

                return '<div class="flex items-center justify-center gap-2">'.$editBtn.$deleteBtn.'</div>';
            })->asHtml()->visible($showActions)->clickable(false),
        ];
    }

    private function canManageNews(News $news): bool
    {
        return $this->canManageAllNews()
            || ($this->isManager() && (int) $news->created_by === (int) auth()->id());
    }

    private function canManageAllNews(): bool
    {
        return auth()->user()?->activeRoleIs('HR', 'super-admin', 'general-manager') ?? false;
    }

    private function authorName(?User $user): string
    {
        return $user?->profile?->name
            ?? $user?->email
            ?? __('general.unknown');
    }

    private function isManager(): bool
    {
        return auth()->user()?->activeRoleIs('manager') ?? false;
    }
}
