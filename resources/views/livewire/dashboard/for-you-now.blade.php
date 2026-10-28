{{--
    "For you now / لك الآن" (C4, interaction-contract.md §5). One ordered block
    for the active role, above the existing cards. Every figure comes from
    the page it links to (ForYouNow); a part with nothing to show is left out,
    and a part that failed says so without hiding the others. No pay.
--}}
@php
    $locale = app()->getLocale();
    $date = fn (?string $d) => $d ? \Carbon\Carbon::parse($d)->locale($locale)->isoFormat('D MMM') : '';
    $range = fn (?string $from, ?string $to) => $from === $to ? $date($from) : $date($from).' – '.$date($to);
    $link = 'inline-flex min-h-11 items-center gap-1 rounded-control px-2 text-small font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
    $button = 'inline-flex min-h-11 items-center justify-center rounded-control bg-primary px-4 text-small font-semibold text-white shadow-e1 hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary';
@endphp

<section aria-labelledby="for-you-title" data-testid="for-you-now" data-tour="dashboard.for-you"
         class="rounded-surface bg-white p-4 ring-1 ring-neutral-200 shadow-sm sm:p-5">
    <h2 id="for-you-title" class="text-h5 font-semibold text-secondary-dark">{{ __('dashboard.for-you.title') }}</h2>

    @if ($parts === [] && $failed === [])
        {{-- Nothing waiting: the block stays, small, so the page keeps its shape. --}}
        <p class="mt-2 text-body text-neutral-700" data-testid="for-you-empty">{{ __('dashboard.for-you.empty') }}</p>
    @endif

    <ol class="mt-3 grid gap-3 lg:grid-cols-3">
        @foreach ($parts as $key => $part)
            <li wire:key="for-you-{{ $key }}" data-part="{{ $key }}"
                class="flex min-w-0 flex-col rounded-control border border-neutral-200 bg-neutral-50 p-3">
                <h3 class="text-body font-semibold text-neutral-900">{{ __("dashboard.for-you.{$key}.title") }}</h3>

                @switch($key)
                    @case('decisions')
                    @case('inbox')
                    @case('inbox-items')
                        <p class="mt-1 text-small text-neutral-800" data-testid="for-you-{{ $key }}-count">
                            {{ trans_choice("dashboard.for-you.{$key}.count", $part['count'], ['count' => $part['count']]) }}
                        </p>
                        @if (! empty($part['kinds']))
                            <p class="mt-0.5 text-caption text-neutral-600">
                                {{ collect($part['kinds'])->map(fn ($n, $kind) => __("inbox.filter.{$kind}").' '.$n)->implode(' · ') }}
                            </p>
                        @endif
                        @if ($part['started'] > 0)
                            <p class="mt-0.5 text-caption font-semibold text-amber-800" data-testid="for-you-{{ $key }}-started">
                                <span aria-hidden="true">⚠</span> {{ trans_choice('dashboard.for-you.already-started', $part['started'], ['count' => $part['started']]) }}
                            </p>
                        @endif
                        @if ($part['soon'] > 0)
                            <p class="mt-0.5 text-caption font-semibold text-amber-800" data-testid="for-you-{{ $key }}-soon">
                                <span aria-hidden="true">⚠</span> {{ trans_choice('dashboard.for-you.starts-soon', $part['soon'], ['count' => $part['soon']]) }}
                            </p>
                        @endif
                        <ul class="mt-2 divide-y divide-neutral-200">
                            @foreach ($part['rows'] as $i => $row)
                                <li wire:key="for-you-{{ $key }}-{{ $i }}">
                                    <a href="{{ $row['url'] }}" wire:navigate class="flex min-h-11 items-center justify-between gap-2 py-1.5 text-small hover:underline focus-visible:outline-2 focus-visible:outline-primary">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-neutral-900">{{ $row['name'] }}</span>
                                            <span class="block truncate text-caption text-neutral-600">
                                                {{ $row['what'] }}@if ($row['from']) · {{ $range($row['from'], $row['to']) }}@endif
                                            </span>
                                        </span>
                                        @if ($row['start_flag'])
                                            <span class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-caption font-semibold text-amber-800 ring-1 ring-amber-200">{{ __($row['start_flag'] === 'started' ? 'inbox.started' : 'inbox.urgent') }}</span>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }} mt-auto self-start">{{ __('dashboard.for-you.see-all') }}<span aria-hidden="true" class="rtl:rotate-180">→</span></a>
                        @break

                    @case('team-off')
                        <p class="mt-1 text-caption font-semibold uppercase text-neutral-600">{{ __('dashboard.for-you.team-off.today') }}</p>
                        @forelse ($part['today'] as $entry)
                            <p class="mt-1 text-small" wire:key="off-today-{{ $entry['key'] }}">@include('livewire.team.partials.chip', ['entry' => $entry])</p>
                        @empty
                            <p class="mt-1 text-small text-neutral-700">{{ __('dashboard.for-you.team-off.nobody-today') }}</p>
                        @endforelse
                        @if ($part['later'] !== [])
                            <p class="mt-2 text-caption font-semibold uppercase text-neutral-600">{{ __('dashboard.for-you.team-off.week') }}</p>
                            @foreach ($part['later'] as $entry)
                                <p class="mt-1 text-small" wire:key="off-later-{{ $entry['key'] }}">
                                    @include('livewire.team.partials.chip', ['entry' => $entry])
                                    <span class="text-caption text-neutral-600">{{ $range($entry['from'], $entry['to']) }}</span>
                                </p>
                            @endforeach
                        @endif
                        <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }} mt-auto self-start">{{ __('team_calendar.title') }}<span aria-hidden="true" class="rtl:rotate-180">→</span></a>
                        @break

                    @case('balance')
                        @if ($part['remaining'])
                            <p class="mt-1 text-small text-neutral-800" data-testid="for-you-balance">{{ __('dashboard.for-you.balance.remaining', ['type' => $part['type'], 'amount' => $part['remaining']]) }}</p>
                        @endif
                        @if ($part['pending'])
                            <p class="mt-0.5 text-caption text-neutral-600">{{ __('dashboard.for-you.balance.pending', ['amount' => $part['pending']]) }}</p>
                        @endif
                        @if ($part['holiday'])
                            <p class="mt-0.5 text-caption text-neutral-600">{{ __('dashboard.for-you.balance.holiday', ['date' => $date($part['holiday'])]) }}</p>
                        @endif
                        <div class="mt-auto flex flex-wrap items-center gap-2 pt-2">
                            @if ($part['can_request'])
                                <a href="{{ $part['request_url'] }}" wire:navigate class="{{ $button }}" data-testid="for-you-request-leave">{{ __('dashboard.for-you.balance.request') }}</a>
                            @endif
                            <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }}">{{ __('dashboard.for-you.balance.my-leaves') }}</a>
                        </div>
                        @break

                    @case('my-requests')
                        <ul class="mt-1 divide-y divide-neutral-200">
                            @foreach ($part['rows'] as $i => $row)
                                <li wire:key="for-you-request-{{ $i }}" class="py-1.5 text-small">
                                    <span class="block font-medium text-neutral-900">{{ $row['type'] }} · {{ $range($row['from'], $row['to']) }}</span>
                                    <span class="block text-caption text-neutral-600">{{ $row['next'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }} mt-auto self-start">{{ __('dashboard.for-you.see-all') }}<span aria-hidden="true" class="rtl:rotate-180">→</span></a>
                        @break

                    @case('my-items')
                        @if ($part['receive'] > 0)
                            <p class="mt-1 text-small text-neutral-800">{{ trans_choice('dashboard.for-you.my-items.receive', $part['receive'], ['count' => $part['receive']]) }}</p>
                        @endif
                        @if ($part['return'] > 0)
                            <p class="mt-1 text-small text-neutral-800">{{ trans_choice('dashboard.for-you.my-items.return', $part['return'], ['count' => $part['return']]) }}</p>
                        @endif
                        <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }} mt-auto self-start">{{ __('dashboard.for-you.open') }}<span aria-hidden="true" class="rtl:rotate-180">→</span></a>
                        @break

                    @case('contracts-ending')
                        <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }} mt-1 self-start text-body" data-testid="for-you-contracts-ending">
                            {{ trans_choice('dashboard.for-you.contracts-ending.count', $part['count'], ['count' => $part['count']]) }}<span aria-hidden="true" class="rtl:rotate-180">→</span>
                        </a>
                        @break

                    @case('employees-waiting')
                        @if ($part['awaiting'] > 0)
                            <a href="{{ $part['awaiting_url'] }}" wire:navigate class="{{ $link }} self-start" data-testid="for-you-awaiting">{{ trans_choice('dashboard.for-you.employees-waiting.awaiting', $part['awaiting'], ['count' => $part['awaiting']]) }}</a>
                        @endif
                        @if ($part['incomplete'] > 0)
                            <a href="{{ $part['incomplete_url'] }}" wire:navigate class="{{ $link }} self-start" data-testid="for-you-incomplete">{{ trans_choice('dashboard.for-you.employees-waiting.incomplete', $part['incomplete'], ['count' => $part['incomplete']]) }}</a>
                        @endif
                        @break

                    @case('key-figures')
                        <dl class="mt-1 grid grid-cols-2 gap-2">
                            <div><dt class="text-caption text-neutral-600">{{ __('dashboard.for-you.key-figures.employees') }}</dt><dd class="text-h5 font-semibold text-neutral-900">{{ $part['employees'] }}</dd></div>
                            <div><dt class="text-caption text-neutral-600">{{ __('dashboard.for-you.key-figures.contracts') }}</dt><dd class="text-h5 font-semibold text-neutral-900">{{ $part['contracts'] }}</dd></div>
                        </dl>
                        <a href="{{ $part['url'] }}" wire:navigate class="{{ $link }} mt-auto self-start">{{ __('dashboard.for-you.key-figures.statistics') }}<span aria-hidden="true" class="rtl:rotate-180">→</span></a>
                        @break

                    @case('admin-shortcuts')
                        <ul class="mt-1">
                            @foreach ($part['links'] as $shortcut)
                                <li><a href="{{ $shortcut['url'] }}" wire:navigate class="{{ $link }}">{{ $shortcut['label'] }}</a></li>
                            @endforeach
                        </ul>
                        @break
                @endswitch
            </li>
        @endforeach

        @foreach ($failed as $key)
            <li wire:key="for-you-failed-{{ $key }}" class="rounded-control border border-neutral-200 bg-neutral-50 p-3" role="alert" data-part-failed="{{ $key }}">
                <h3 class="text-body font-semibold text-neutral-900">{{ __("dashboard.for-you.{$key}.title") }}</h3>
                <p class="mt-1 text-small text-neutral-700">{{ __('dashboard.for-you.failed') }}</p>
                <button type="button" wire:click="$refresh" class="{{ $link }}">{{ __('general.toast.try-again') }}</button>
            </li>
        @endforeach
    </ol>
</section>
