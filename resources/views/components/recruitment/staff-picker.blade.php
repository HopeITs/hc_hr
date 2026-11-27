@props(['id', 'candidates', 'seatOwner' => null, 'selectedId' => null])

@php
    $candidates = $candidates
        ->sortBy(fn ($candidate) => $candidate->id === $selectedId ? 0 : ($candidate->id === $seatOwner ? 1 : 2))
        ->unique('employee_id')
        ->sortBy(fn ($candidate) => $candidate->profile?->name ?: $candidate->email);
@endphp

<x-forms.select2-input :id="$id" {{ $attributes }}>
    @if ($seatOwner === null)
        <option value="">{{ __('recruitment.choose') }}</option>
    @endif
    @foreach ($candidates as $candidate)
        @php
            $name = $candidate->profile?->name ?: $candidate->email;
            $position = $candidate->profile?->primaryContract()?->position?->title;
        @endphp
        <option value="{{ $candidate->id }}">
            {{ $name }}
            @if ($position)
                · {{ $position }}
            @endif
            @if ($name !== $candidate->email)
                · {{ $candidate->email }}
            @endif
            @if ($candidate->id === $seatOwner)
                ({{ __('recruitment-applicant.interviews.attends_self') }})
            @endif
        </option>
    @endforeach
</x-forms.select2-input>
