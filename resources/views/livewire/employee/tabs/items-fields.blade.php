<div class="space-y-6">
    <x-heading-label>@lang('employees.items.title')</x-heading-label>

    @if(!empty($employeeChecklistItems) && $employeeChecklistItems->isNotEmpty())
        <div class="overflow-x-auto rounded-surface border">
            <table class="min-w-full divide-y divide-line-soft">
                <thead class="bg-soft">
                    <tr>
                        <th class="px-6 py-3 text-start text-small font-medium text-ink-2 uppercase tracking-wider">@lang('general.item')</th>
                        <th class="px-6 py-3 text-start text-small font-medium text-ink-2 uppercase tracking-wider">@lang('general.note')</th>
                        <th class="px-6 py-3 text-start text-small font-medium text-ink-2 uppercase tracking-wider">@lang('general.status')</th>
                        <th class="px-6 py-3 text-start text-small font-medium text-ink-2 uppercase tracking-wider">@lang('general.received_at')</th>
                    </tr>
                </thead>
                <tbody class="bg-card divide-y divide-line-soft">
                    @foreach ($employeeChecklistItems as $item)
                        @php
                            $status = match (true) {
                                $item->returned_status === 'received' => __('employees.items.received'),
                                $item->delivered_status === 'delivered' => __('employees.items.delivered'),
                                default => __('general.on_hold'),
                            };

                            // Returned = done; delivered = the employee holds it (in progress); otherwise waiting.
                            [$statusTone, $statusIcon] = match (true) {
                                $item->returned_status === 'received' => ['done', 'return'],
                                $item->delivered_status === 'delivered' => ['work', 'box'],
                                default => ['wait', 'clock'],
                            };
                        @endphp
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-body text-ink">{{ $item->item?->name ?? __('general.not-specified') }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-body text-ink-2">{{ $item->notes ?: '-' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-body">
                                <x-status :tone="$statusTone" :icon="$statusIcon" :label="$status" />
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-body text-ink-2">
                                {{-- Fix: Only show date if status is delivered AND date exists --}}
                                <bdi>{{ $item->delivered_status === 'delivered' && $item->delivered_at ? $item->delivered_at->format('Y-m-d') : '-' }}</bdi>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center text-ink-2 italic py-8">
            @lang('general.no_items_from_checklist')
        </div>
    @endif
</div>
