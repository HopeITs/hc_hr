@php use Illuminate\Support\Carbon; @endphp
@props([
    'start'  => null,
    'end'    => null,
    'format' => 'DD-MM-YYYY',
])

@php
    $locale = app()->getLocale();
    Carbon::setLocale($locale);

    $s = $start ? Carbon::parse($start)->isoFormat($format) : null;
    $e = $end   ? Carbon::parse($end)->isoFormat($format)   : null;

    $now = __('general.present');
@endphp

<div class="grid grid-rows-2 gap-1 leading-5 text-center" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
    <div class="inline-flex items-center justify-center gap-2">
        <span class="text-caption text-ink-2">@lang('general.from')</span>
        <span class="font-medium tabular-nums whitespace-nowrap">{{ $s ?? '—' }}</span>
    </div>
    <div class="inline-flex items-center justify-center gap-2">
        <span class="text-caption text-ink-2">@lang('general.to')</span>
        <span class="font-medium tabular-nums whitespace-nowrap">
            {{ $e ?? ($s ? $now : '—') }}
        </span>
    </div>
</div>
