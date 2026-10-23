{{-- Live leave-impact summary beside the dates (A3 step 2).
     $impact comes from MyLeaveRequestModule::leaveImpact(): strings already
     worded and priced on the server by LeaveRequestPricing. Hours and dates
     only — never pay. Shown to the requester only. --}}
@if(! $impact['ready'])
    <p class="rounded-control border border-dashed border-neutral-300 bg-neutral-50 px-3 py-2 text-sm text-neutral-600"
       data-testid="leave-impact-hint">
        @lang('leave_quote.preview.hint')
    </p>
@else
    <section aria-labelledby="leave-impact-title"
             data-testid="leave-impact"
             class="space-y-3 rounded-surface border border-neutral-200 bg-neutral-50 p-3 sm:p-4">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <h3 id="leave-impact-title" class="font-semibold text-neutral-900">@lang('leave_quote.preview.title')</h3>
            <p class="text-xs text-neutral-600">@lang('leave_quote.preview.estimate')</p>
        </div>

        {{-- One short spoken line per change instead of the whole panel. --}}
        <p class="sr-only" aria-live="polite" aria-atomic="true">
            @lang('leave_quote.preview.requested'): {{ $impact['requested'] }}.
            @if($impact['entitled'])
                @lang('leave_quote.preview.projected'): {{ $impact['projected'] }}.
            @endif
        </p>

        @if($impact['entitled'])
            <dl class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <div class="rounded-control bg-white p-2 ring-1 ring-neutral-200">
                    <dt class="text-xs text-neutral-600">@lang('leave_quote.preview.available')</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-neutral-900"><bdi>{{ $impact['available'] }}</bdi></dd>
                </div>
                <div class="rounded-control bg-white p-2 ring-1 ring-neutral-200">
                    <dt class="text-xs text-neutral-600">@lang('leave_quote.preview.pending')</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-neutral-900"><bdi>{{ $impact['pending'] }}</bdi></dd>
                </div>
                <div class="rounded-control bg-white p-2 ring-1 ring-neutral-200">
                    <dt class="text-xs text-neutral-600">@lang('leave_quote.preview.requested')</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-neutral-900" data-testid="leave-impact-requested">
                        <bdi>{{ $impact['requested'] }}</bdi>
                    </dd>
                    <dd class="text-xs text-neutral-600">
                        @unless($impact['hourly'])
                            <span class="block">{{ __('leave_quote.preview.'.($impact['calendar_days'] ? 'calendar_days' : 'working_days')) }}: <bdi>{{ $impact['counted_days'] }}</bdi></span>
                        @endunless
                        <span class="block">@lang('leave_quote.preview.in_hours'): <bdi>{{ $impact['requested_hours'] }}</bdi></span>
                    </dd>
                </div>
                <div @class([
                        'rounded-control p-2 ring-1',
                        'bg-red-50 ring-red-300' => $impact['short'],
                        'bg-white ring-neutral-200' => ! $impact['short'],
                    ])>
                    <dt class="text-xs text-neutral-600">@lang('leave_quote.preview.projected')</dt>
                    <dd @class([
                            'mt-0.5 text-sm font-semibold',
                            'text-red-800' => $impact['short'],
                            'text-neutral-900' => ! $impact['short'],
                        ])><bdi>{{ $impact['projected'] }}</bdi></dd>
                </div>
            </dl>
        @endif

        @if($impact['excluded'] !== [])
            <div>
                <p class="text-xs font-medium text-neutral-700">@lang('leave_quote.preview.excluded')</p>
                <ul class="mt-1 flex flex-wrap gap-1.5">
                    @foreach(array_slice($impact['excluded'], 0, 6) as $excluded)
                        <li class="rounded-full bg-white px-2 py-0.5 text-xs text-neutral-700 ring-1 ring-neutral-200">
                            <bdi dir="ltr">{{ $excluded['date'] }}</bdi>
                            <span>{{ $excluded['day'] }}</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $excluded['reason'] }}</span>
                        </li>
                    @endforeach
                    @if(count($impact['excluded']) > 6)
                        <li class="px-1 py-0.5 text-xs text-neutral-600">
                            {{ __('leave_quote.preview.more_excluded', ['count' => count($impact['excluded']) - 6]) }}
                        </li>
                    @endif
                </ul>
            </div>
        @endif

        @if($impact['errors'] !== [])
            <div class="rounded-control border-s-4 border-red-600 bg-red-50 p-2" data-testid="leave-impact-errors">
                <p class="text-sm font-semibold text-red-800">@lang('leave_quote.preview.errors')</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5 text-sm text-red-800">
                    @foreach($impact['errors'] as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($impact['warnings'] !== [])
            <div class="rounded-control border-s-4 border-amber-500 bg-amber-50 p-2" data-testid="leave-impact-warnings">
                <p class="text-sm font-semibold text-amber-900">@lang('leave_quote.preview.warnings')</p>
                <ul class="mt-1 list-disc space-y-0.5 ps-5 text-sm text-amber-900">
                    @foreach($impact['warnings'] as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <details class="text-sm text-neutral-700">
            <summary class="inline-flex min-h-11 cursor-pointer items-center rounded-control font-medium text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                @lang('leave_quote.preview.how')
            </summary>
            <ul class="mt-1 space-y-0.5">
                @foreach($impact['explanation'] as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </details>
    </section>
@endif
