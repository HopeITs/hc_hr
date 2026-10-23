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
        <div class="overflow-hidden rounded-surface border border-neutral-200 shadow-e1">
            <table class="min-w-full text-body text-left text-neutral-800">
                <thead class="bg-neutral-100 text-secondary-medium uppercase text-small tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('general.employee') }}</th>
                        <th class="px-4 py-3">{{ __('general.item') }}</th>
                        <th class="px-4 py-3">{{ __('general.return_requested_at') }}</th>
                        <th class="px-4 py-3">{{ __('general.note') }}</th>
                        <th class="px-4 py-3 text-center w-40"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 bg-white">
                    @foreach ($pendingReturns as $item)
                        <tr class="hover:bg-neutral-50 transition" wire:key="pending-return-{{ $item->id }}">
                            <td class="px-4 py-3 font-medium">{{ $item->employee->name }}</td>
                            <td class="px-4 py-3">{{ $item->item->name }}</td>
                            <td class="px-4 py-3 text-secondary-medium">{{ $item->updated_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                <input type="text"
                                    wire:model.defer="notes.{{ $item->id }}"
                                    wire:key="note-input-{{ $item->id }}"
                                    placeholder="{{ __('general.add-note') }}"
                                    class="w-full rounded-control border-primary text-body focus:border-primary focus:ring-primary">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button wire:click="markReceived({{ $item->id }})"
                                    wire:loading.attr="disabled" wire:target="markReceived({{ $item->id }})"
                                    class="inline-flex items-center px-3 py-2 bg-primary text-white text-small font-semibold rounded-control hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition">
                                    {{ __('general.mark_as_received') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
