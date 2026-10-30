{{--
    The Profile and Contact tabs share one form (infoForm), so they share these
    buttons (B5, docs/ux/employee-lifecycle.md §5.6):

      - creating:  Save (complete record) · Save draft · Cancel
      - a draft:   Complete record · Save draft
      - otherwise: Save, as before

    "Save" on a new record or a draft completes it ("Ready, awaiting contract");
    "Save draft" needs only the draft fields. Inside <x-forms.dirty-gate>, so
    `dirty` is in scope: a draft is saved only when something changed, but a
    draft can be completed as it stands.

    Both are <x-button>s, whose busy state follows their own action only: the
    legacy buttons went disabled on every request of the component, so each
    autosave (phase 2, employee-lifecycle.md §5.6) made them flicker and could
    swallow a click.
--}}
@php
    $recordIsDraft = ! $isCreateMode && $employee->isDraft();
@endphp

@if ($isCreateMode || $recordIsDraft)
    <x-button wire:click="saveInformation" data-testid="save-complete"
        :data-tour="$tour ? 'employee.save' : null">
        @lang($isCreateMode ? 'employees.onboarding.save-complete' : 'employees.onboarding.complete-record')
    </x-button>
    <x-button variant="quiet" wire:click="saveDraftRecord" data-testid="save-draft"
        x-bind:disabled="! dirty">
        @lang('employees.onboarding.save-draft')
    </x-button>
    @if ($isCreateMode)
        {{-- C2: back to where New was pressed (EmployeeCreate only; the edit screen has no returnTo). --}}
        <x-secondary-button :href="$returnTo ?? route('employees.index')" class="!w-auto">@lang('general.cancel')</x-secondary-button>
    @endif
@else
    <x-primary-button type="button" wire:click="saveInformation" class="!w-auto" x-bind:disabled="! dirty"
        :data-tour="$tour ? 'employee.save' : null">
        @lang('general.save')
    </x-primary-button>
@endif
