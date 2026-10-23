<?php

namespace App\Livewire\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\Blade;

/**
 * Loading / empty / filtered-empty / error states for a ramonrietdijk LivewireTable.
 *
 * The published table view calls renderEmptyState() when it exists, so tables
 * without this trait keep the package's plain "No results" row.
 *
 * Using classes implement emptyStateCreate(), the permission-aware next action
 * for a genuinely empty dataset: [label, href] or null (no button).
 */
trait HasListStates
{
    use HandlesLoadFailure;

    /** Resets every search box and filter; the next render restores the records. */
    public function clearAllFilters(): void
    {
        $this->clearSearch();
        $this->clearFilters();
        $this->dispatch('list-filters-cleared');
    }

    /** Rendered inside the table body by the published view when no rows came back. */
    public function renderEmptyState(): string
    {
        if ($this->loadFailed) {
            return Blade::render('<x-ui.load-error />');
        }

        if ($this->isNarrowed()) {
            return Blade::render(
                '<x-center-empty-state variant="filtered"><x-slot:action>'
                .'<button type="button" wire:click="clearAllFilters" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-primary px-5 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">{{ __(\'general.clear-filters\') }}</button>'
                .'</x-slot:action></x-center-empty-state>',
            );
        }

        $create = $this->emptyStateCreate();

        return Blade::render(
            '<x-center-empty-state variant="empty">'
            .($create ? '<x-slot:action><x-primary-button :href="$href">{{ $label }}</x-primary-button></x-slot:action>' : '')
            .'</x-center-empty-state>',
            $create ? ['label' => $create[0], 'href' => $create[1]] : [],
        );
    }

    /** Polite live-region text announced after every search/filter/page change. */
    public function stateAnnouncement(int $total): string
    {
        if ($this->loadFailed) {
            return __('general.state-error-title');
        }

        return $total === 0
            ? ($this->isNarrowed() ? __('general.state-filtered-title') : __('general.state-empty-title'))
            : trans_choice('general.state-results-count', $total, ['count' => $total]);
    }

    /** @return array{0: string, 1: string}|null */
    abstract protected function emptyStateCreate(): ?array;

    /** A failed query becomes an empty page plus $loadFailed, never a 500. */
    protected function paginate(): LengthAwarePaginator
    {
        return $this->guardedLoad(
            fn () => parent::paginate(),
            fallback: new ConcreteLengthAwarePaginator([], 0, $this->perPage()),
        );
    }

    /** True when a search box or filter is narrowing the list. */
    protected function isNarrowed(): bool
    {
        return $this->canClearSearch() || $this->canClearFilters();
    }
}
