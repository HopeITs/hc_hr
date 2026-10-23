{{-- Phone card (B4-D §6): name, position, city, email, status as text + colour, actions reachable. --}}
@php
    $user = auth()->user();
    $canUpdate = $user->hasActivePermission('employees.update');
    $canDelete = $user->hasActivePermission('employees.delete');
    $showCity = $user->activeCityIds() === null;
    $position = $item->primaryContract()?->position?->title;
    $city = $showCity ? $item->city?->name : null;
    $email = $item->account?->email;
@endphp
<div data-card="{{ $item->getKey() }}" class="flex min-w-0 flex-col gap-2">
    <div class="flex min-w-0 items-start gap-3">
        <img src="{{ $item->avatar_url }}" alt="" loading="lazy" class="size-10 max-w-none shrink-0 rounded-full border border-primary bg-white object-cover" />
        <div class="min-w-0 flex-1">
            <button type="button"
                x-on:click="$dispatch('employee-drawer', { id: {{ (int) $item->getKey() }} })"
                aria-haspopup="dialog"
                aria-label="{{ __('general.emp-open-details', ['name' => $item->name]) }}"
                class="block min-h-11 max-w-full text-start font-semibold text-black focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"><bdi>{{ $item->name }}</bdi></button>
            @if($position || $city)
                <p class="text-small text-secondary-medium">
                    @if($position)<bdi>{{ $position }}</bdi>@endif
                    @if($position && $city) &middot; @endif
                    @if($city)<bdi>{{ $city }}</bdi>@endif
                </p>
            @endif
        </div>
    </div>
    @if($email)
        <p class="text-small text-black"><span class="sr-only">{{ __('general.work_email') }}: </span><span dir="ltr" class="break-all">{{ $email }}</span></p>
    @endif
    <div class="flex items-center justify-between gap-2">
        @include('livewire.employee.partials.status', ['employee' => $item])
        @include('livewire.employee.partials.row-actions', ['employee' => $item, 'canUpdate' => $canUpdate, 'canDelete' => $canDelete])
    </div>
</div>
