{{-- Status as text plus colour. --}}
@if($employee->is_active)
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-emerald-50 px-2 py-0.5 text-small font-semibold text-emerald-700 ring-1 ring-emerald-200"><span class="size-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>{{ __('general.active') }}</span>
@else
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full bg-neutral-100 px-2 py-0.5 text-small font-semibold text-secondary-medium ring-1 ring-neutral-200"><span class="size-1.5 rounded-full bg-neutral-400" aria-hidden="true"></span>{{ __('general.inactive') }}</span>
@endif
