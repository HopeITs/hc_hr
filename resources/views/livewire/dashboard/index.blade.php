@php
    $employee = auth()->user()->profile;

    $firstName = $employee?->first_name;

    $contract = $employee?->latestActiveContract();

    $locale = app()->getLocale();
    $titleCol = $locale === 'ar' ? 'title_ar' : 'title_en';

    $positionTitle = $contract?->position?->$titleCol
        ?? $contract?->position?->title_en
        ?? '—';
@endphp

<x-slot:title>
    {{ __('general.dashboard') }}
</x-slot:title>

@section('greeting')
    <div class="py-4 pt-24">
        <h1 class="text-secondary-dark font-semibold text-h3">
            {{ __('dashboard.greeting', ['name' => $firstName]) }}
        </h1>

        <p class="text-secondary-light text-body">
            {{ $positionTitle }}
        </p>
    </div>
@endsection

<div class="space-y-6">

    {{-- C4 (C0-Q5): the active role's next actions first; the existing cards below, unchanged. --}}
    <livewire:dashboard.for-you-now />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="flex h-full min-h-0 flex-col gap-6 lg:col-span-2">
            @if(isset($failedSections['leaves']))
                <div data-tour="dashboard.my-leaves" class="w-full"><x-ui.load-error class="h-full " /></div>
            @elseif($hasLeaves)
                <div data-tour="dashboard.my-leaves" class="flex items-center justify-start font-semibold text-white rounded-surface">
                    <livewire:dashboard.my-leaves-card/>
                </div>
            @endif
            <div data-tour="dashboard.notes" class="flex min-h-68 flex-1 items-stretch justify-start rounded-surface font-semibold text-white">
                {{-- No identifier is passed: the card derives its owner from
                     the session. Passing auth()->id() here supplied a User id
                     where an Employee id was expected. --}}
                <livewire:dashboard.employee-notes-card/>
            </div>
        </div>

        <div data-tour="dashboard.team" class="w-full rounded-surface text-white font-semibold  {{$hasLeaves ? 'h-full' : 'h-68'}}">
            @if(isset($failedSections['team']))
                <x-ui.load-error class="h-full " />
            @else
                <x-dashboard-cards.team-members-card/>
            @endif
        </div>


        <div data-tour="dashboard.news" class="flex items-center justify-center h-full row-span-2 font-semibold text-white rounded-surface">
            <x-dashboard-cards.news-card/>
        </div>


        <div class="flex items-center justify-center font-semibold text-white h-70">
            <livewire:dashboard.calendar-card/>
        </div>

        <div data-tour="dashboard.upcoming-leaves" class="flex items-center justify-center font-semibold text-white h-70">
            <livewire:dashboard.upcoming-leaves-card/>

        </div>

        <div class="flex items-center justify-center font-semibold text-white h-70">
            <livewire:news.today-events-card/>
        </div>

        <div class="flex items-center justify-center font-semibold text-white h-70">
            @if(isset($failedSections['upcoming-events']))
                <x-ui.load-error class="h-full " />
            @else
                <x-dashboard-cards.upcoming-events-card :upcoming-events="$upcomingEvents"/>
            @endif
        </div>

        <div data-tour="dashboard.pending-requests" class="flex items-center justify-center font-semibold text-white h-70">
            @if(isset($failedSections['pending']))
                <x-ui.load-error class="h-full " />
            @else
                <x-dashboard-cards.pending-requests-card :pending-requests="$pendingRequests"/>
            @endif
        </div>

        <div class="flex items-center justify-center font-semibold text-white h-70">
            @if(isset($failedSections['delivered']))
                <x-ui.load-error class="h-full " />
            @else
                <x-dashboard-cards.checklist-items-card :delivered-items="$deliveredItems"/>
            @endif
        </div>

        <div class="flex items-center justify-center font-semibold text-white h-70">
            @if(isset($failedSections['badges']))
                <x-ui.load-error class="h-full " />
            @else
                <x-dashboard-cards.my-badges-card :my-badges="$myBadges"/>
            @endif
        </div>
    </div>




</div>
