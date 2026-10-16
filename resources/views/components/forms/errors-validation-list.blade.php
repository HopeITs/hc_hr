{{--
    Form-level error summary. Every field already shows its own error inline,
    so this lists only the errors whose inline message is not on screen —
    typically a field on another tab, since only the active tab is rendered.
    The matching happens in the browser (errorSummary in resources/js/feedback.js).
--}}
@if($errors->any())
    <div x-data="errorSummary" x-show="hasUncovered" role="alert" data-testid="error-summary"
         class="mb-4 rounded-control border border-s-4 border-neutral-200 border-s-primary bg-white p-3 text-body text-primary">
        <ul class="list-inside list-disc space-y-1">
            @foreach($errors->messages() as $field => $messages)
                @foreach($messages as $message)
                    <li data-error-key="{{ $field }}" x-show="! isCovered(@js($field))">{{ $message }}</li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
