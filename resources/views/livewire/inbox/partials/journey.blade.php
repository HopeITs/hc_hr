{{--
    An employee-journey row (DEC-09 increment 2; JourneyInboxSource): the
    steps waiting on HR, the due date and the Late tag, and one way on, the
    employee page, where the journey's own panel acts and re-authorizes.
    Status is icon + words + fill shape, never colour. No pay, no contract terms.
--}}
@php
    use App\Services\Inbox\JourneyInboxSource;

    $tones = [
        JourneyInboxSource::STATE_TODO => ['attn', 'alert'],
        JourneyInboxSource::STATE_PROBLEM => ['stop', 'warning'],
    ];
@endphp

<div class="flex flex-col gap-2" data-testid="inbox-journey" data-source="{{ $row['source'] }}">
    <ul class="flex flex-col gap-1">
        @foreach ($row['steps'] as $step)
            @php
                [$tone, $icon] = $tones[$step['state']] ?? $tones[JourneyInboxSource::STATE_TODO];
            @endphp
            <li class="flex flex-wrap items-center gap-2 text-small text-ink" data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                <span>{{ $step['label'] }}</span>
                <x-status :tone="$tone" :icon="$icon" :label="__('journey_inbox.state.'.$step['state'])" />
            </li>
        @endforeach
    </ul>

    @if ($row['due'] || $row['late'])
        <p class="flex flex-wrap items-center gap-2 text-small text-ink-2">
            @if ($row['due'])
                <span>{{ __('journey_inbox.due', ['date' => $row['due']->translatedFormat('d M Y')]) }}</span>
            @endif
            @if ($row['late'])
                <x-tag variant="late" data-testid="inbox-journey-late" :label="__('journey_inbox.late')" />
            @endif
        </p>
    @endif

    <div>
        <x-button variant="outline" size="sm" :href="$row['url']" data-testid="inbox-journey-open">
            {{ __('journey_inbox.open') }}
        </x-button>
    </div>
</div>
