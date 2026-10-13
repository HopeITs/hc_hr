<x-slot:title>
    {{ __('general.pending_returns') }}
</x-slot:title>

<div class="space-y-8">
    <div class="flex justify-between items-center">
        <x-heading-label>{{ __('general.pending_return_requests') }}</x-heading-label>
        @if (session()->has('message'))
            <span class="text-green-600 text-body font-medium">{{ session('message') }}</span>
        @endif
    </div>

    @if ($pendingReturns->isEmpty())
        <div class="text-center text-secondary-light italic py-12 border border-dashed border-neutral-300 rounded-surface bg-neutral-50">
            {{ __('general.no_pending_returns') }}
        </div>
    @else
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
                                    class="w-full rounded-control border-red-500 text-body focus:border-red-600 focus:ring-red-600">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button wire:click="markReceived({{ $item->id }})"
                                    class="inline-flex items-center px-3 py-2 bg-red-600 text-white text-small font-semibold rounded-control hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition">
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
