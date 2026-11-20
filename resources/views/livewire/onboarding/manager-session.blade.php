{{--
    The direct manager's orientation session with a new volunteer (track 7).
    Set the date, then record the session once it has happened.
--}}
@php
    $date = fn ($value) => $value?->timezone(config('app.timezone'))->translatedFormat('d M Y');
    $done = $journey->manager_orientation_completed_at !== null;
@endphp

<div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8" data-testid="manager-session">
    <x-page-header :title="__('onboarding.manager-session.title')" :lead="__('onboarding.manager-session.lead', ['name' => $employee->name])" />

    <x-card>
        <p class="text-body text-ink">@lang('onboarding.manager-session.agenda-intro')</p>
        <ul class="mt-2 list-disc space-y-1 ps-5 text-body text-ink-2">
            <li>@lang('onboarding.manager-session.agenda-programs')</li>
            <li>@lang('onboarding.manager-session.agenda-workflow')</li>
        </ul>
        <p class="mt-3 flex flex-wrap items-center gap-2 text-small text-ink-2">
            <span>@lang('employees.journey.due'): <span class="num">{{ $date($journey->orientation_due_on) }}</span></span>
            @if ($journey->managerOrientationIsLate())
                <x-tag variant="late" :label="__('employees.journey.late')" />
            @endif
        </p>
    </x-card>

    <x-card>
        @if ($done)
            <x-status tone="done" icon="check-circle" :label="__('employees.journey.state.done')" />
            <p class="mt-2 text-body text-ink-2">{{ __('onboarding.manager-session.done-on', ['date' => $date($journey->manager_orientation_completed_at)]) }}</p>
        @else
            <form wire:submit="schedule" class="flex flex-wrap items-end gap-3">
                <div>
                    <label for="manager-session-date" class="block text-small text-ink-2">@lang('onboarding.manager-session.date')</label>
                    <x-forms.date-input id="manager-session-date" wire:model="date" class="!w-auto" />
                </div>
                <x-button variant="outline" size="sm" type="submit" target="schedule">
                    {{ $journey->manager_orientation_scheduled_for ? __('employees.journey.reschedule') : __('employees.journey.schedule') }}
                </x-button>
                @if ($canComplete)
                    <x-button size="sm" icon="check" type="button" wire:click="complete" data-testid="manager-session-done">@lang('employees.journey.orientation-action')</x-button>
                @endif
            </form>
            <p class="mt-2 text-small text-primary" role="alert">@error('date'){{ $message }}@enderror</p>
            @if ($journey->manager_orientation_scheduled_for)
                <p class="mt-2 text-small text-ink-2">{{ __('employees.journey.orientation-on', ['date' => $date($journey->manager_orientation_scheduled_for)]) }}</p>
            @endif
        @endif
    </x-card>
</div>
