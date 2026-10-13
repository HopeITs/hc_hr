{{-- Public facts only: name, position, interview type, time, place / link.
     Never pass a reason, a note, a participant or an evaluation into this view. --}}
@php
    $lines = function (string $locale) use ($kind, $name, $positionAr, $positionEn, $type) {
        $position = $locale === 'ar' ? ($positionAr ?: $positionEn) : ($positionEn ?: $positionAr);

        return [
            'greeting' => __('recruitment-applicant.interviews.mail.greeting', ['name' => $name], $locale),
            'body' => __('recruitment-applicant.interviews.mail.body.'.$kind, [
                'position' => $position,
                'type' => __('recruitment-applicant.interviews.types.'.$type, [], $locale),
            ], $locale),
        ];
    };
@endphp
<x-mail::message>
<div dir="rtl" lang="ar" style="text-align: right;">

{{ $lines('ar')['greeting'] }}

{{ $lines('ar')['body'] }}

**{{ __('recruitment-applicant.interviews.mail.when', [], 'ar') }}:** {{ $dateAr }} — {{ $time }} ({{ $numeric }})<br>
**{{ __('recruitment-applicant.interviews.mail.timezone', [], 'ar') }}:** {{ $timezone }}
@if ($duration && $kind !== 'cancelled')
<br>**{{ __('recruitment-applicant.interviews.mail.duration', [], 'ar') }}:** {{ __('recruitment-applicant.interviews.minutes', ['count' => $duration], 'ar') }}
@endif
@if ($location)
<br>**{{ __('recruitment-applicant.interviews.mail.'.($online ? 'platform' : 'location'), [], 'ar') }}:** {{ $location }}
@endif
@if ($meetingUrl)
<br>**{{ __('recruitment-applicant.interviews.mail.join', [], 'ar') }}:** <a href="{{ $meetingUrl }}">{{ $meetingUrl }}</a>
@endif

{{ __('recruitment-applicant.interviews.mail.closing.'.$kind, [], 'ar') }}

{{ __('recruitment-applicant.interviews.mail.signature', [], 'ar') }}

</div>

---

<div dir="ltr" lang="en" style="text-align: left;">

{{ $lines('en')['greeting'] }}

{{ $lines('en')['body'] }}

**{{ __('recruitment-applicant.interviews.mail.when', [], 'en') }}:** {{ $dateEn }} — {{ $time }} ({{ $numeric }})<br>
**{{ __('recruitment-applicant.interviews.mail.timezone', [], 'en') }}:** {{ $timezone }}
@if ($duration && $kind !== 'cancelled')
<br>**{{ __('recruitment-applicant.interviews.mail.duration', [], 'en') }}:** {{ __('recruitment-applicant.interviews.minutes', ['count' => $duration], 'en') }}
@endif
@if ($location)
<br>**{{ __('recruitment-applicant.interviews.mail.'.($online ? 'platform' : 'location'), [], 'en') }}:** {{ $location }}
@endif
@if ($meetingUrl)
<br>**{{ __('recruitment-applicant.interviews.mail.join', [], 'en') }}:** <a href="{{ $meetingUrl }}">{{ $meetingUrl }}</a>
@endif

{{ __('recruitment-applicant.interviews.mail.closing.'.$kind, [], 'en') }}

{{ __('recruitment-applicant.interviews.mail.signature', [], 'en') }}

</div>
</x-mail::message>
