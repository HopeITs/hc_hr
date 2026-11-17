<x-mail::message :preheader="$preheader ?? null">
{{-- Headline: the greeting when one is set, otherwise the subject --}}
@if (! empty($greeting))
# {{ $greeting }}
@elseif (! empty($subject))
# {{ $subject }}
@elseif ($level === 'error')
# {{ __('mail.greeting_error') }}
@else
# {{ __('mail.greeting') }}
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- One-time code (App\Notifications\Messages\BrandedMailMessage::code) --}}
@isset($code)
<x-mail::code :code="$code" :minutes="$codeMinutes ?? null" />

@endisset

{{-- Action Button --}}
@isset($actionText)
<x-mail::button :url="$actionUrl">
{{ $actionText }}
</x-mail::button>

@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Security note (App\Notifications\Messages\BrandedMailMessage::notice) --}}
@isset($notice)
<x-mail::notice :title="__('mail.security_notice')">
{{ $notice }}
</x-mail::notice>

@endisset

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
{{ __('mail.regards') }}<br>
{{ __('mail.signature', ['app' => config('app.name')]) }}
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
{{ __('mail.action_fallback', ['action' => $actionText]) }} <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
