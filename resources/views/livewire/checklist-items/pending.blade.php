<x-slot:title>
    {{ __('general.pending-delivers') }}
</x-slot:title>

<div class="space-y-8">
    <div class="flex justify-between items-center">
        <x-heading-label>{{ __('general.pending_checklist_requests') }}</x-heading-label>
    </div>

    @if($loadFailed)
        <x-ui.load-error />
    @elseif($pendingItems->isEmpty())
        <x-center-empty-state :title="__('states.pending-delivers-empty-title')" :description="__('states.pending-delivers-empty-help')" />
    @else
        <p class="sr-only" role="status" aria-live="polite">{{ trans_choice('states.items-count', $pendingItems->count()) }}</p>
        <div class="overflow-hidden rounded-surface border border-line-soft shadow-e1">
            <table class="min-w-full text-body text-start text-ink">
                <thead class="bg-surface text-ink-2 uppercase text-small tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('general.employee') }}</th>
                        <th class="px-4 py-3">{{ __('general.item') }}</th>
                        <th class="px-4 py-3">{{ __('general.request_date') }}</th>
                        <th class="px-4 py-3">{{ __('general.note') }}</th>
                        <th class="px-4 py-3 text-center w-40"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft bg-card">
                    @foreach ($pendingItems as $item)
                        <tr class="hover:bg-hover transition" wire:key="pending-deliver-{{ $item->id }}">
                            <td class="px-4 py-3 font-medium">{{ $item->employee->name }}</td>
                            <td class="px-4 py-3">{{ $item->item->name }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $item->created_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                <input type="text"
                                    wire:model.defer="notes.{{ $item->id }}"
                                    wire:key="note-input-{{ $item->id }}"
                                    placeholder="{{ __('general.add-note') }}"
                                    class="w-full rounded-control border-line bg-field text-body">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <x-button variant="outline" size="sm" wire:click="markDelivered({{ $item->id }})" :target="'markDelivered('.$item->id.')'">
                                    {{ __('general.mark_as_delivered') }}
                                </x-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
