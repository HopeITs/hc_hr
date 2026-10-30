<x-slot:title>
    {{ __('general.my-items') }}
</x-slot:title>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <x-heading-label>{{ __('general.checklist_items') }}</x-heading-label>
        @unless($loadFailed)
            <span class="text-body text-ink-2" role="status" aria-live="polite">
                {{ trans_choice('states.items-count', $items->count()) }}
            </span>
        @endunless
    </div>

    @if($loadFailed)
        <x-ui.load-error />
    @elseif($items->isEmpty())
        {{-- Read-only list: items arrive from HR, so there is no action to offer. --}}
        <x-center-empty-state :title="__('states.checklist-empty-title')" :description="__('states.checklist-empty-help')" />
    @else
        <div class="overflow-hidden shadow-e1 border border-line-soft rounded-surface">
            <table class="min-w-full divide-y divide-line-soft">
                <thead class="bg-soft">
                    <tr>
                        <th class="px-6 py-3 text-start text-small font-semibold text-ink-2 uppercase tracking-wider">
                            {{trans_choice('general.employee',2)}}
                        </th>
                        <th class="px-6 py-3 text-start text-small font-semibold text-ink-2 uppercase tracking-wider">
                            @lang('general.item')
                        </th>
                        <th class="px-6 py-3 text-start text-small font-semibold text-ink-2 uppercase tracking-wider">
                            @lang('general.status')
                        </th>
                        <th class="px-6 py-3 text-start text-small font-semibold text-ink-2 uppercase tracking-wider">
                            @lang('general.delivered_at')
                        </th>
                        <th class="px-6 py-3 text-start text-small font-semibold text-ink-2 uppercase tracking-wider">
                            @lang('general.notes')
                        </th>
                    </tr>
                </thead>

                <tbody class="bg-card divide-y divide-line-soft">
                    @foreach ($items as $item)
                        <tr class="hover:bg-hover transition">
                            <td class="px-6 py-4 text-body text-ink">
                                {{ $item->employee->name }}
                            </td>
                            <td class="px-6 py-4 text-body text-ink">
                                {{ $item->item->name }}
                            </td>
                            <td class="ps-6 pe-2 py-4 text-body">
                                {{-- Same mapping as the employee's Items tab: delivered = held (in progress), returned = done. --}}
                                @php
                                    [$itemTone, $itemIcon, $itemLabel] = match (strtolower($item->display_status)) {
                                        'delivered' => ['work', 'box', __('employees.items.delivered')],
                                        'returned' => ['done', 'return', __('employees.items.received')],
                                        default => ['wait', 'clock', __('general.on_hold')],
                                    };
                                @endphp
                                <x-status :tone="$itemTone" :icon="$itemIcon" :label="$itemLabel" />
                            </td>
                            <td class="px-6 py-4 text-body text-ink-2">
                                {{ $item->delivered_at ? $item->delivered_at->format('Y-m-d') : '-' }}
                            </td>
                            <td class="px-6 py-4 text-body text-ink-2">
                                {{ $item->notes ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
