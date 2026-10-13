<x-slot:title>
    {{ __('general.pending-delivers') }}
</x-slot:title>

<div class="space-y-8">
    <div class="flex justify-between items-center">
        <x-heading-label>{{ __('general.pending_checklist_requests') }}</x-heading-label>
        @if (session()->has('message'))
            <span class="text-neutral-600 text-body font-medium">{{ session('message') }}</span>
        @endif
    </div>

    @if($pendingItems->isEmpty())
        <div class="text-center text-secondary-light italic py-12 border border-dashed border-neutral-300 rounded-surface bg-neutral-50">
            {{ __('general.no_pending_requests') }}
        </div>
    @else
        <div class="overflow-hidden rounded-surface border border-neutral-200 shadow-e1">
            <table class="min-w-full text-body text-left text-neutral-800">
                <thead class="bg-neutral-100 text-secondary-medium uppercase text-small tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('general.employee') }}</th>
                        <th class="px-4 py-3">{{ __('general.item') }}</th>
                        <th class="px-4 py-3">{{ __('general.request_date') }}</th>
                        <th class="px-4 py-3">{{ __('general.note') }}</th>
                        <th class="px-4 py-3 text-center w-40"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100 bg-white">
                    @foreach ($pendingItems as $item)
                        <tr class="hover:bg-neutral-50 transition" wire:key="pending-deliver-{{ $item->id }}">
                            <td class="px-4 py-3 font-medium">{{ $item->employee->name }}</td>
                            <td class="px-4 py-3">{{ $item->item->name }}</td>
                            <td class="px-4 py-3 text-secondary-medium">{{ $item->created_at->format('Y-m-d') }}</td>
                            <td class="px-4 py-3">
                                <input type="text"
                                    wire:model.defer="notes.{{ $item->id }}"
                                    wire:key="note-input-{{ $item->id }}"
                                    placeholder="{{ __('general.add_note') }}"
                                    class="w-full rounded-control border-primary text-body focus:border-primary focus:ring-primary">
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button wire:click="markDelivered({{ $item->id }})"
                                    class="inline-flex items-center px-3 py-2 bg-primary text-white text-small font-semibold rounded-control hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition">
                                    {{ __('general.mark_as_delivered') }}
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
