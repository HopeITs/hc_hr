{{-- Public facts only: name and position. Never pass a reason or a note into this view. --}}
<x-mail::message>
<div dir="rtl" lang="ar" style="text-align: right;">

{{ __('recruitment-applicant.decisions.mail.greeting', ['name' => $name], 'ar') }}

{{ __('recruitment-applicant.decisions.mail.body', ['position' => $positionAr ?: $positionEn], 'ar') }}

{{ __('recruitment-applicant.decisions.mail.closing', [], 'ar') }}

{{ __('recruitment-applicant.decisions.mail.signature', [], 'ar') }}

</div>

---

<div dir="ltr" lang="en" style="text-align: left;">

{{ __('recruitment-applicant.decisions.mail.greeting', ['name' => $name], 'en') }}

{{ __('recruitment-applicant.decisions.mail.body', ['position' => $positionEn ?: $positionAr], 'en') }}

{{ __('recruitment-applicant.decisions.mail.closing', [], 'en') }}

{{ __('recruitment-applicant.decisions.mail.signature', [], 'en') }}

</div>
</x-mail::message>
