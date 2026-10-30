<x-slot:title>
    {{ __('general.pending_returns') }}
</x-slot:title>

<div class="space-y-8">
    <div class="flex justify-between items-center">
        <x-heading-label>{{ __('general.pending_return_requests') }}</x-heading-label>
    </div>

    @if ($loadFailed)
        <x-ui.load-error />
    @elseif ($pendingReturns->isEmpty())
        <x-center-empty-state :title="__('states.pending-returns-empty-title')" :description="__('states.pending-returns-empty-help')" />
    @else
        <p class="sr-only" role="status" aria-live="polite">{{ trans_choice('states.items-count', $pendingReturns->count()) }}</p>
        <div class="overflow-hidden rounded-surface border border-line-soft shadow-e1">
            <table class="min-w-full text-body text-start text-ink">
                <thead class="bg-surface text-ink-2 uppercase text-small tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('general.employee') }}</th>
                        <th class="px-4 py-3">{{ __('general.item') }}</th>
                        <th class="px-4 py-3">{{ __('general.return_requested_at') }}</th>
                        <th class="px-4 py-3">{{ __('general.note') }}</th>
                        <th class="px-4 py-3 text-center w-40"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft bg-card">
                    @foreach ($pendingReturns as $item)
                        <tr class="hover:bg-hover transition" wire:key="pending-return-{{ $item->id }}">
                            <td class="px-4 py-3 font-medium">{{ $item->employee->name }}</td>
                            <td class="px-4 py-3">{{ $item->item->name }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $item->updated_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                <input type="text"
                                    wire:model.defer="notes.{{ $item->id }}"
                                    wire:key="note-input-{{ $item->id }}"
                                    placeholder="{{ __('general.add-note') }}"
                                    class="w-full rounded-control border-line bg-field text-body">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <x-button variant="outline" size="sm" wire:click="markReceived({{ $item->id }})" :target="'markReceived('.$item->id.')'">
                                    {{ __('general.mark_as_received') }}
                                </x-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
