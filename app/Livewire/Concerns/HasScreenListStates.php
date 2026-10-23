<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Blade;

/**
 * HasListStates for tables whose empty state carries screen-specific copy, a
 * next action that is not a link (an event the parent module handles), or a
 * filter that lives outside the table (the status tab of the leave screens).
 *
 * A using table implements emptyScreenState() and, when a tab narrows it,
 * narrowedByTab() / clearTab(). Every action is built per render from the
 * signed-in user, so a role that may not take it gets no button at all; the
 * handler the button reaches authorizes again on the server.
 */
trait HasScreenListStates
{
    use HasListStates {
        HasListStates::clearAllFilters as private clearTableFilters;
        HasListStates::isNarrowed as private tableIsNarrowed;
    }

    public function clearAllFilters(): void
    {
        $this->clearTableFilters();

        if ($this->narrowedByTab()) {
            $this->clearTab();
        }

        $this->dispatch('list-filters-cleared');
    }

    public function renderEmptyState(): string
    {
        if ($this->loadFailed) {
            return Blade::render('<x-ui.load-error />');
        }

        if ($this->isNarrowed()) {
            // A tab alone keeps its own wording; any search/filter uses the shared copy.
            $tabOnly = $this->narrowedByTab() && ! $this->tableIsNarrowed();
            $state = [
                'variant' => 'filtered',
                'title' => $tabOnly ? __('states.leave-tab-empty-title') : null,
                'description' => $tabOnly ? __('states.leave-tab-empty-help') : null,
                'action' => ['label' => __('general.clear-filters'), 'wire' => 'clearAllFilters'],
            ];
        } else {
            $screen = $this->emptyScreenState();
            $state = [
                'variant' => 'empty',
                'title' => $screen['title'],
                'description' => $screen['description'],
                'action' => $screen['action'] ?? null,
            ];
        }

        return Blade::render(<<<'BLADE'
            <x-center-empty-state :variant="$state['variant']" :title="$state['title']" :description="$state['description']">
                @if ($state['action'])
                    <x-slot:action>
                        <button
                            type="button"
                            @if (isset($state['action']['wire'])) wire:click="{{ $state['action']['wire'] }}" @else x-on:click="{{ $state['action']['alpine'] }}" @endif
                            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg bg-primary px-5 text-sm font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >{{ $state['action']['label'] }}</button>
                    </x-slot:action>
                @endif
            </x-center-empty-state>
            BLADE, compact('state'));
    }

    /**
     * Copy and (optional) action for a genuinely empty dataset.
     *
     * @return array{title: string, description: string, action?: array{label: string, alpine: string}|null}
     */
    abstract protected function emptyScreenState(): array;

    /** True when a control outside the table (a tab) is hiding rows. */
    protected function narrowedByTab(): bool
    {
        return false;
    }

    /** Resets that outside control; only called when narrowedByTab() was true. */
    protected function clearTab(): void {}

    protected function isNarrowed(): bool
    {
        return $this->tableIsNarrowed() || $this->narrowedByTab();
    }

    protected function emptyStateCreate(): ?array
    {
        return null;
    }
}
