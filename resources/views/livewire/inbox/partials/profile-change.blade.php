{{--
    D1 (profile-contract.md §5.1): a change an employee proposed to their own
    profile. HR sees exactly what will be written, and any attached file
    through the private download route, before approving. Rejecting needs a
    reason, which the employee reads. Real <form>: Enter submits.
--}}
@php
    $labels = [
        'name' => __('general.name'),
        'specialization' => __('general.specialization'),
        'degree' => __('general.degree'),
        'status' => __('general.status'),
        'notes' => __('general.notes'),
        'category_id' => __('general.category'),
        'title' => __('general.title'),
        'organization' => __('general.organization'),
        'end_date' => __('general.end_date'),
        'has_certificate' => __('general.has_certificate'),
        'job_title' => __('general.job_title'),
        'company' => __('general.company'),
        'start_date' => __('general.start_date'),
        'place' => __('general.place'),
        'type' => __('general.type'),
        'phone' => __('general.phone'),
        'relation' => __('general.relation'),
    ];
    $shown = function (string $field, mixed $value) use ($row): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return match (true) {
            $row['section'] === 'education' && $field === 'degree' => \App\Enums\EducationType::getLocalizedName((int) $value),
            $row['section'] === 'education' && $field === 'status' => \App\Enums\EducationStatus::getLocalizedName((int) $value),
            $row['section'] === 'relative' && $field === 'relation' => \App\Enums\KinshipType::getLocalizedName((int) $value),
            $row['section'] === 'experience' && $field === 'place' => \App\Enums\ContractPlace::getLocalizedName((int) $value)['value'],
            $row['section'] === 'experience' && $field === 'type' => \App\Enums\ContractType::getLocalizedName((int) $value)['value'],
            $field === 'category_id' => (string) \App\Models\CourseCategory::query()->find($value)?->name,
            $field === 'has_certificate' => $value ? __('general.yes') : __('general.no'),
            default => (string) $value,
        };
    };
@endphp

<div class="flex flex-col gap-2" data-testid="inbox-profile-change">
    @if (($row['proposed'] ?? []) !== [])
        <dl class="grid gap-x-4 gap-y-1 rounded-control bg-soft p-3 text-small sm:grid-cols-2">
            @foreach ($row['proposed'] as $field => $value)
                <div class="min-w-0">
                    <dt class="text-caption text-ink-3">{{ $labels[$field] ?? $field }}</dt>
                    <dd class="break-words font-semibold text-ink"><bdi>{{ $shown($field, $value) }}</bdi></dd>
                </div>
            @endforeach
        </dl>
    @endif
    @if ($row['file_url'])
        <a href="{{ $row['file_url'] }}" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center self-start text-small font-semibold text-ink underline underline-offset-4">{{ __('inbox.profile.file') }}</a>
    @endif

    <form x-data="{ rejecting: false }"
          x-on:submit.prevent="rejecting ? $wire.rejectProfile(@js($row['key'])) : $wire.approveProfile(@js($row['key']))"
          class="flex flex-col gap-2">
        <label for="note-{{ $slug }}" class="text-small font-semibold text-ink">{{ __('inbox.profile.reason') }}</label>
        <input id="note-{{ $slug }}" type="text" maxlength="500" wire:model="notes.{{ $slug }}"
               class="h-11 w-full rounded-control border border-line bg-field px-3">
        @error('notes.'.$slug) <p class="text-small text-primary">{{ $message }}</p> @enderror
        <div class="flex flex-wrap gap-2">
            <x-button type="submit" x-on:click="rejecting = false" data-testid="inbox-profile-approve">{{ __('inbox.profile.approve') }}</x-button>
            <x-button type="submit" variant="outline" x-on:click="rejecting = true" data-testid="inbox-profile-reject">{{ __('inbox.profile.reject') }}</x-button>
        </div>
    </form>
</div>
