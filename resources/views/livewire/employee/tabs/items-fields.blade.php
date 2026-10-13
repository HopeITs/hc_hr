<div class="space-y-6">
    <x-heading-label>@lang('general.items_from_checklist')</x-heading-label>

    @if(!empty($employeeChecklistItems) && $employeeChecklistItems->isNotEmpty())
        <div class="overflow-x-auto rounded-surface border">
            <table class="min-w-full divide-y divide-neutral-200">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-6 py-3 text-start text-small font-medium text-secondary-medium uppercase tracking-wider">@lang('general.item')</th>
                        <th class="px-6 py-3 text-start text-small font-medium text-secondary-medium uppercase tracking-wider">@lang('general.note')</th>
                        <th class="px-6 py-3 text-start text-small font-medium text-secondary-medium uppercase tracking-wider">@lang('general.status')</th>
                        <th class="px-6 py-3 text-start text-small font-medium text-secondary-medium uppercase tracking-wider">@lang('general.received_at')</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-neutral-200">
                    @foreach ($employeeChecklistItems as $item)
                        @php
                            $status = match (true) {
                                $item->returned_status === 'received' => __('general.received'),
                                $item->delivered_status === 'delivered' => __('general.delivered'),
                                default => __('general.on_hold'),
                            };

                            $statusColor = match ($status) {
                                __('general.received') => 'bg-neutral-100 text-neutral-700',
                                __('general.delivered') => 'bg-neutral-100 text-neutral-700',
                                default => 'bg-neutral-100 text-neutral-700',
                            };
                        @endphp
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-body text-neutral-900">{{ $item->item?->name ?? 'N/A' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-body text-secondary-medium">{{ $item->notes ?: '-' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-body">
                                <span class="px-2 py-1 inline-flex text-small font-medium rounded-full {{ $statusColor }}">
                                    {{ $status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-body text-secondary-medium">
                                {{-- Fix: Only show date if status is delivered AND date exists --}}
                                {{ $item->delivered_status === 'delivered' && $item->delivered_at ? $item->delivered_at->format('Y-m-d') : '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center text-secondary-medium italic py-8">
            @lang('general.no_items_from_checklist')
        </div>
    @endif
</div>
