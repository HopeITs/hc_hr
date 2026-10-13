<x-slot:title>
    {{ __('general.my-items') }}
</x-slot:title>

<div class="space-y-8">
    <div class="flex items-center justify-between">
        <x-heading-label>{{ __('general.checklist_items') }}</x-heading-label>
        <span class="text-body text-secondary-medium">
            {{ $items->count() }} {{ \Illuminate\Support\Str::plural(__('general.item'), $items->count()) }}
        </span>
    </div>

    @if($items->isEmpty())
        <div class="text-center text-secondary-medium italic py-12 bg-neutral-50 rounded-surface border border-dashed">
            {{ __('general.no_items') }}
        </div>
    @else
        <div class="overflow-hidden shadow-e1 border border-neutral-200 rounded-surface">
            <table class="min-w-full divide-y divide-neutral-200">
                <thead class="bg-neutral-50">
                    <tr>
                        <th class="px-6 py-3 ltr:text-left rtl:text-right text-small font-semibold text-secondary-medium uppercase tracking-wider">
                            {{trans_choice('general.employee',2)}}
                        </th>
                        <th class="px-6 py-3ltr:text-left rtl:text-right text-small font-semibold text-secondary-medium uppercase tracking-wider">
                            @lang('general.item')
                        </th>
                        <th class="px-6 py-3 ltr:text-left rtl:text-right text-small font-semibold text-secondary-medium uppercase tracking-wider">
                            @lang('general.status')
                        </th>
                        <th class="px-6 py-3 ltr:text-left rtl:text-right text-small font-semibold text-secondary-medium uppercase tracking-wider">
                            @lang('general.delivered_at')
                        </th>
                        <th class="px-6 py-3 ltr:text-left rtl:text-right text-small font-semibold text-secondary-medium uppercase tracking-wider">
                            @lang('general.notes')
                        </th>
                    </tr>
                </thead>

                <tbody class="bg-white divide-y divide-neutral-100">
                    @foreach ($items as $item)
                        <tr class="hover:bg-neutral-50 transition">
                            <td class="px-6 py-4 text-body text-neutral-800">
                                {{ $item->employee->name }}
                            </td>
                            <td class="px-6 py-4 text-body text-neutral-800">
                                {{ $item->item->name }}
                            </td>
                            <td class="ps-6 pe-2 py-4 text-body">
                                <span class="px-2 py-1 inline-flex text-small ltr:-ms-2 rtl:text-right font-medium rounded-full
                                    @if(strtolower($item->display_status) === 'delivered')
                                        bg-neutral-100 text-neutral-700
                                    @elseif(strtolower($item->display_status) === 'on hold')
                                        bg-neutral-100 text-neutral-700
                                    @else
                                        bg-neutral-100 text-neutral-800
                                    @endif">
                                    {{ $item->display_status }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-body  text-secondary-medium">
                                {{ $item->delivered_at ? $item->delivered_at->format('Y-m-d') : '-' }}
                            </td>
                            <td class="px-6 py-4 text-body text-secondary-medium">
                                {{ $item->notes ?: '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
