{{--
    The deciding manager's coverage warning (C5, §4.5 team_overlap, Q3: any
    overlap). From TeamCoverage::teamOverlap(): other members of the
    requester's direct-manager team away (accepted or pending) on the
    requester's working days. Advisory only: it never disables a button and
    is never an error. $overlap = ['dates' => [...], 'members' => [...]].
--}}
@if (! empty($overlap['members']))
    <div class="rounded-control border-s-4 border-ink bg-soft p-2" role="note" data-testid="team-overlap">
        <p class="flex items-center gap-1.5 text-small font-bold text-ink">
            <x-ic name="warning" class="size-4" />
            {{ __('team_calendar.overlap.title') }}
        </p>
        <ul class="mt-1 space-y-0.5 text-small text-ink">
            @foreach ($overlap['members'] as $member)
                <li>{{ __('team_calendar.overlap.line', [
                    'name' => $member['name'],
                    'status' => __('team_calendar.status.'.$member['status']),
                    'dates' => collect($member['dates'])->map(fn (string $d) => \Carbon\Carbon::parse($d)->format('d-m'))->implode('، '),
                ]) }}</li>
            @endforeach
        </ul>
        <p class="mt-1 text-caption text-ink-2">{{ __('team_calendar.overlap.advisory') }}</p>
    </div>
@endif
