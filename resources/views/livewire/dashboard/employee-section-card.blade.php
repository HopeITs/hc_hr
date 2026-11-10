{{--
    One section of a person's profile as a tile (EmployeeSectionCard), added
    from that section with "Add to dashboard". The same words and values as
    the profile; nothing the profile would not show this viewer. No longer
    allowed: one quiet line. Empty: data-tile-empty, so the tile folds.
--}}
@php
    $locale = app()->getLocale();
    $notSpecified = __('general.not-specified');
    $date = fn ($value) => $value ? \Carbon\Carbon::parse($value)->locale($locale)->isoFormat('D MMM YYYY') : $notSpecified;
    $currency = [0 => '', 1 => 'SYP', 2 => 'USD', 3 => 'EUR'];
@endphp
<div class="hc-empsec flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm" data-section="{{ $section }}">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif ($employee === null)
        <p class="hc-nt-empty pt-4">{{ __('dashboard.record.gone') }}</p>
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('dashboard.tiles.rec-'.$section, ['name' => $employee->name]) }}</h2>
        </header>

        @switch($section)
            @case('overview')
                <div class="hc-me-body">
                    <dl class="hc-me-kv">
                        @foreach ([
                            [__('general.position'), $data['position']],
                            [trans_choice('general.job-level', 1), $data['jobLevel']],
                            [__('general.direct-manager'), $data['manager']],
                            [trans_choice('general.program', 1), $data['program']],
                            [trans_choice('general.city', 1), $data['city']],
                        ] as [$label, $value])
                            <dt>{{ $label }}</dt>
                            <dd @class(['font-semibold', 'text-ink-3' => ! $value])>{{ $value ?? $notSpecified }}</dd>
                        @endforeach
                    </dl>
                </div>
                @break

            @case('timeline')
                @if ($data['events'] === [])
                    <p class="hc-nt-empty" data-tile-empty>{{ __('employees.profile.timeline-empty') }}</p>
                @else
                    <ol class="hc-list">
                        @foreach ($data['events'] as $event)
                            <li class="hc-list-row" data-event="{{ $event['kind'] }}">
                                <x-ic class="size-4 shrink-0 text-ink-2" :name="match (true) {
                                    $event['kind'] === 'badge.warning' => 'warning',
                                    $event['kind'] === 'badge.reward', $event['kind'] === 'badge.salary' => 'star',
                                    default => 'dot',
                                }" />
                                <span class="min-w-0 flex-1">
                                    <b class="block truncate">
                                        {{ __('employees.profile.events.'.str_replace('.', '-', $event['kind'])) }}
                                        @if ($event['title'] && ! str_starts_with($event['kind'], 'badge.'))
                                            · <span class="font-semibold">{{ $event['title'] }}</span>
                                        @endif
                                    </b>
                                    <span class="block truncate text-small text-ink-2">
                                        <bdi class="num">{{ $date($event['date']) }}</bdi>@if ($event['detail']) · {{ $event['detail'] }}@endif
                                    </span>
                                    @if ($event['pay'] && $event['pay']['amount'])
                                        <span class="block text-small font-semibold text-ink"><bdi class="num">{{ number_format((float) $event['pay']['amount']) }} {{ $currency[$event['pay']['currency'] ?? 0] ?? '' }}</bdi></span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
                @break

            @case('education')
            @case('experience')
            @case('courses')
                @if ($data['items']->isEmpty())
                    <p class="hc-nt-empty" data-tile-empty>{{ __('general.no-available-data') }}</p>
                @else
                    @php
                        $organizations = [
                            'Hope Center' => __('employees.courses.organization-hope-center'),
                            'Personal' => __('employees.courses.organization-personal'),
                            'Other' => __('employees.courses.organization-other'),
                        ];
                    @endphp
                    <ul class="hc-list">
                        @foreach ($data['items'] as $item)
                            <li class="hc-list-row">
                                <span class="min-w-0 flex-1">
                                    @if ($section === 'education')
                                        <b class="block truncate">{{ $item->name ?? ($item->specialization ?? $notSpecified) }}</b>
                                        <span class="block truncate text-small text-ink-2">
                                            {{ \App\Enums\EducationType::getLocalizedName((int) $item->degree) }} ·
                                            {{ \App\Enums\EducationStatus::getLocalizedName((int) $item->status) }}
                                        </span>
                                    @elseif ($section === 'experience')
                                        <b class="block truncate">{{ $item->job_title ?? $notSpecified }}</b>
                                        <span class="block truncate text-small text-ink-2">{{ $item->company ?? $notSpecified }}</span>
                                        <span class="block text-small text-ink-2"><bdi class="num">{{ $date($item->start_date) }}</bdi> – <bdi class="num">{{ $date($item->end_date) }}</bdi></span>
                                    @else
                                        <b class="block truncate">{{ $item->title ?? $notSpecified }}</b>
                                        <span class="block truncate text-small text-ink-2">{{ $item->organization ? ($organizations[$item->organization] ?? $item->organization) : ($item->granted_by ?? $notSpecified) }}</span>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @break

            @case('skills')
                @if ($data['skills'] === [] && $data['interests'] === [])
                    <p class="hc-nt-empty" data-tile-empty>{{ __('general.no-available-data') }}</p>
                @else
                    <div class="hc-me-body">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($data['skills'] as $skill)
                                <span class="rounded-round bg-ink px-3 py-1.5 text-small font-bold text-on-ink">{{ $skill }}</span>
                            @endforeach
                        </div>
                        @if ($data['interests'] !== [])
                            <p class="text-small font-semibold text-ink-2">@lang('general.interests')</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($data['interests'] as $interest)
                                    <span class="rounded-round bg-surface px-3 py-1.5 text-small font-semibold text-ink-2">{{ $interest }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
                @break

            @case('badges')
                @if ($data['items']->isEmpty())
                    <p class="hc-nt-empty" data-tile-empty>{{ __('general.no-available-data') }}</p>
                @else
                    <ul class="hc-list">
                        @foreach ($data['items'] as $badge)
                            <li class="hc-list-row">
                                <span class="min-w-0 flex-1">
                                    <b class="block truncate">{{ \App\Enums\BadgeType::getLocalizedName((int) $badge->type) }}</b>
                                    @if ($badge->reason)
                                        <span class="block truncate text-small text-ink-2">{{ $badge->reason }}</span>
                                    @endif
                                </span>
                                <bdi class="num shrink-0 text-small text-ink-2">{{ $date($badge->date) }}</bdi>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @break

            @case('personal')
                <div class="hc-me-body">
                    <dl class="hc-me-kv">
                        @foreach ([
                            [__('general.national-id'), $employee->national_number],
                            [__('general.birthday'), $employee->actual_dob ? $date($employee->actual_dob) : null],
                            [__('general.birth-place'), $employee->{'pob_'.$locale}],
                            [__('general.nationality'), $employee->nationality !== null ? \App\Enums\Nationality::getLocalizedName((int) $employee->nationality) : null],
                            [__('general.marital-status'), $employee->marital_status !== null ? \App\Enums\MaritalStatus::getLocalizedName((int) $employee->marital_status)['value'] : null],
                            [__('general.children'), $employee->children ?? 0],
                            [__('general.civil-registry-office'), $employee->civil_registry_office],
                            [__('general.civil-registry-number'), $employee->civil_registry_number],
                            [__('general.address'), $employee->address],
                        ] as [$label, $value])
                            <dt>{{ $label }}</dt>
                            <dd @class(['font-semibold', 'text-ink-3' => $value === null || $value === ''])><bdi>{{ ($value === null || $value === '') ? $notSpecified : $value }}</bdi></dd>
                        @endforeach
                    </dl>
                </div>
                @break

            @case('personal-contact')
                <div class="hc-me-body">
                    <dl class="hc-me-kv">
                        @foreach ([[__('general.email'), $employee->personal_email, 'mailto:'], [__('general.personal-phone'), $employee->personal_phone, 'tel:']] as [$label, $value, $scheme])
                            <dt>{{ $label }}</dt>
                            <dd class="num">
                                @if ($value)
                                    <a href="{{ $scheme.$value }}" class="font-semibold text-ink hover:underline"><bdi dir="ltr">{{ $value }}</bdi></a>
                                @else
                                    <span class="text-ink-3">{{ $notSpecified }}</span>
                                @endif
                            </dd>
                        @endforeach
                    </dl>
                </div>
                @break

            @case('family')
                @if ($data['items']->isEmpty())
                    <p class="hc-nt-empty" data-tile-empty>{{ __('general.no-available-data') }}</p>
                @else
                    <ul class="hc-list">
                        @foreach ($data['items'] as $relative)
                            <li class="hc-list-row">
                                <span class="min-w-0 flex-1">
                                    <b class="block truncate"><bdi>{{ $relative->name }}</bdi></b>
                                    <span class="block truncate text-small text-ink-2">
                                        {{ \App\Enums\KinshipType::getLocalizedName((int) $relative->relation) }}
                                        @if ($relative->phone)
                                            · <bdi dir="ltr">{{ $relative->phone }}</bdi>
                                        @endif
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @break
        @endswitch
    @endif
</div>
