{{--
    "Me" tile (MeCard): the viewer's own record at a glance, as label / value
    rows, and the way to the full profile. A missing value says so in ink-3,
    never an empty cell. The photo only when the viewer may see it.
--}}
<div class="hc-me flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.me') }}</h2>
        </header>

        @if ($me === null)
            <p class="hc-nt-empty">{{ __('dashboard.me.none') }}</p>
        @else
            @php
                $locale = app()->getLocale();
                $contract = $me['start']
                    ? collect([$me['type'], $me['start']->locale($locale)->isoFormat('D MMM YYYY').($me['end'] ? ' – '.$me['end']->locale($locale)->isoFormat('D MMM YYYY') : '')])->filter()->implode(' · ')
                    : null;
                $rows = [
                    'contract' => $contract,
                    'department' => $me['department'],
                    'manager' => $me['manager'],
                    'phone' => $me['phone'],
                ];
            @endphp
            <div class="hc-me-body">
                <div class="hc-me-who">
                    @if ($me['avatar'])
                        <img class="hc-nt-av" src="{{ $me['avatar'] }}" alt="" loading="lazy">
                    @else
                        <span class="hc-nt-av hc-me-initial" aria-hidden="true">{{ mb_substr($me['name'], 0, 1) }}</span>
                    @endif
                    <div class="min-w-0">
                        <p class="truncate font-bold text-ink">{{ $me['name'] }}</p>
                        <p class="truncate text-small text-ink-2">{{ collect([$me['position'], $me['center']])->filter()->implode(' · ') ?: __('dashboard.me.not-set') }}</p>
                    </div>
                </div>
                <dl class="hc-me-kv" data-testid="me-details">
                    @foreach ($rows as $row => $value)
                        <dt>{{ __('dashboard.me.'.$row) }}</dt>
                        <dd @class(['num' => in_array($row, ['contract', 'phone'], true)])>
                            @if (filled($value))
                                {{-- A phone number reads left to right inside an Arabic row, on the row's own side. --}}
                                @if ($row === 'phone')<bdi dir="ltr">{{ $value }}</bdi>@else{{ $value }}@endif
                            @else
                                <span class="text-ink-3">{{ $row === 'contract' ? __('dashboard.me.no-contract') : __('dashboard.me.not-set') }}</span>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            </div>
        @endif
    @endif
</div>
