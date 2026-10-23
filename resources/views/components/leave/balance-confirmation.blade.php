{{-- Summary shown in the save-confirmation dialog of an annual balance adjustment. --}}
@props([
    'name' => '',
    'operation' => '',
    'amount' => '',
    'addBadge' => '',
    'notes' => null,
])
<div class="flex flex-col items-start justify-start space-y-2 text-start">
    <span><b>{{ trans_choice('general.employee', 1) }}:</b> <bdi>{{ $name }}</bdi></span>
    <span><b>@lang('general.operation'):</b> {{ $operation }}</span>
    <span><b>@lang('general.amount'):</b> {{ $amount }}</span>
    <span><b>@lang('general.add-as-badge'):</b> {{ $addBadge }}</span>
    @if(filled($notes))
        <span><b>@lang('general.notes'):</b> {{ $notes }}</span>
    @endif
</div>
