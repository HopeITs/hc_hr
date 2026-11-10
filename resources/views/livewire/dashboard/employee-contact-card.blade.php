{{--
    One person's work contact tile (EmployeeContactCard), added from the work
    contact section of their profile: photo (only with viewAvatar), name,
    position, and the work email and phones as links. No longer allowed: one
    quiet line.
--}}
<div class="hc-empcontact flex h-full w-full flex-col overflow-hidden rounded-surface border border-line-soft bg-card shadow-sm">
    @if ($loadFailed)
        <x-ui.load-error class="h-full min-h-0 border-0" />
    @elseif ($employee === null)
        <p class="hc-nt-empty pt-4">{{ __('dashboard.record.gone') }}</p>
    @else
        <header class="hc-nt-head">
            <h2 class="hc-nt-title">{{ __('general.work-contact') }}</h2>
        </header>

        <div class="hc-me-body">
            <div class="hc-me-who">
                @if ($avatar)
                    <img class="hc-nt-av" src="{{ $avatar }}" alt="" loading="lazy">
                @else
                    <span class="hc-nt-av hc-me-initial" aria-hidden="true">{{ mb_substr((string) $employee->name, 0, 1) }}</span>
                @endif
                <div class="min-w-0">
                    <p class="truncate font-bold text-ink">{{ $employee->name }}</p>
                    @if ($position)
                        <p class="truncate text-small text-ink-2">{{ $position }}</p>
                    @endif
                </div>
            </div>

            @php
                $lines = array_filter([
                    __('general.email') => $email ? ['mailto:'.$email, $email] : null,
                    __('general.work-phone') => $employee->work_phone ? ['tel:'.$employee->work_phone, $employee->work_phone] : null,
                    'WhatsApp' => $employee->whatsapp_work ? [null, $employee->whatsapp_work] : null,
                    'Telegram' => $employee->telegram_work ? [null, $employee->telegram_work] : null,
                ]);
            @endphp
            @if ($lines === [])
                <p class="text-small text-ink-2">{{ __('general.no-available-data') }}</p>
            @else
                <dl class="hc-me-kv">
                    @foreach ($lines as $label => [$href, $value])
                        <dt>{{ $label }}</dt>
                        <dd class="num">
                            @if ($href)
                                <a href="{{ $href }}" class="font-semibold text-ink hover:underline"><bdi dir="ltr">{{ $value }}</bdi></a>
                            @else
                                <bdi dir="ltr">{{ $value }}</bdi>
                            @endif
                        </dd>
                    @endforeach
                </dl>
            @endif
        </div>
    @endif
</div>
