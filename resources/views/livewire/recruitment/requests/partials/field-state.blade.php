{{-- An approver asked for this field: it waits on the requester (red tag).
     A locked field says why it cannot be typed into, quietly. --}}
@if ($isChangeRequested ?? false)
    <x-tag variant="act" icon="pen">{{ __('recruitment.field_change_requested') }}</x-tag>
@endif

@if ($isLocked ?? false)
    <p class="mt-1 inline-flex items-center gap-1.5 text-caption font-medium text-ink-2">
        <svg class="size-3.5 shrink-0" aria-hidden="true" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M5 8V6a5 5 0 0 1 10 0v2h.5A1.5 1.5 0 0 1 17 9.5v7A1.5 1.5 0 0 1 15.5 18h-11A1.5 1.5 0 0 1 3 16.5v-7A1.5 1.5 0 0 1 4.5 8H5Zm2 0h6V6a3 3 0 1 0-6 0v2Z" clip-rule="evenodd" />
        </svg>
        {{ __('recruitment.field_locked_no_changes_requested') }}
    </p>
@endif
