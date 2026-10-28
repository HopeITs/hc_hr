{{--
    The deciding manager's coverage warning (C5, §4.5 team_overlap, Q3: any
    overlap). From TeamCoverage::teamOverlap(): other members of the
    requester's direct-manager team away (accepted or pending) on the
    requester's working days. Advisory only: it never disables a button and
    is never an error. $overlap = ['dates' => [...], 'members' => [...]].
--}}
@if (! empty($overlap['members']))
    <div class="rounded-control border-s-4 border-amber-500 bg-amber-50 p-2" role="note" data-testid="team-overlap">
        <p class="flex items-center gap-1.5 text-sm font-semibold text-amber-900">
            <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2l8.66 15H1.34L10 2zm-1 6v4h2V8H9zm0 5v2h2v-2H9z"/></svg>
            {{ __('team_calendar.overlap.title') }}
        </p>
        <ul class="mt-1 space-y-0.5 text-sm text-amber-900">
            @foreach ($overlap['members'] as $member)
                <li>{{ __('team_calendar.overlap.line', [
                    'name' => $member['name'],
                    'status' => __('team_calendar.status.'.$member['status']),
                    'dates' => collect($member['dates'])->map(fn (string $d) => \Carbon\Carbon::parse($d)->format('d-m'))->implode('، '),
                ]) }}</li>
            @endforeach
        </ul>
        <p class="mt-1 text-xs text-amber-900">{{ __('team_calendar.overlap.advisory') }}</p>
    </div>
@endif
