<?php

namespace App\Http\Requests\Push;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The service worker's pushsubscriptionchange report: the subscription the
 * browser dropped (endpoint + its auth secret, the proof of ownership) and
 * the one that replaces it. No session: the old secret is the only credential.
 */
class RenewPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'old_endpoint' => ['required', 'string', 'max:2048'],
            'old_auth' => ['required', 'string', 'max:32', 'regex:'.PushSubscriptionRequest::BASE64URL],
            'subscription' => ['required', 'array'],
        ] + PushSubscriptionRequest::subscriptionRules('subscription.');
    }
}
