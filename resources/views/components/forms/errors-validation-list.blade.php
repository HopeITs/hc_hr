@props([
    // Which tab or section holds each field: [error key or key prefix => section].
    // The longest matching prefix wins, so 'infoForm.username' can override 'infoForm.'.
    'sections' => [],
    // The Livewire method that shows a section, called with its name (e.g.
    // "setActiveTab"). Without it, sections are opened by a `reveal-field`
    // window event (the contract composer listens for it).
    'reveal' => null,
])

{{--
    Form-level error summary. Every field already shows its own error inline,
    so this lists only the errors whose inline message is not on screen —
    typically a field on another tab, since only the active tab is rendered.
    The matching happens in the browser (errorSummary in resources/js/feedback.js).

    Each entry is a link to its field (B2): it opens the tab or section that
    holds it, then moves focus there.
--}}
@if($errors->any())
    @php
        $sectionFor = function (string $key) use ($sections): ?string {
            $best = null;
            $bestLength = -1;

            foreach ($sections as $prefix => $section) {
                if (($key === $prefix || str_starts_with($key, $prefix)) && strlen($prefix) > $bestLength) {
                    [$best, $bestLength] = [$section, strlen($prefix)];
                }
            }

            return $best;
        };
    @endphp
    <div x-data="errorSummary(@js($reveal))" x-show="hasUncovered" role="alert" data-testid="error-summary"
         class="mb-4 rounded-control border border-s-4 border-line-soft border-s-primary bg-card p-3 text-body text-ink">
        <ul class="list-inside list-disc space-y-1">
            @foreach($errors->messages() as $field => $messages)
                @foreach($messages as $message)
                    <li data-error-key="{{ $field }}" x-show="! isCovered(@js($field))">
                        <button type="button"
                                class="text-start underline underline-offset-2 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2"
                                x-on:click="go(@js($field), @js($sectionFor($field)))">{{ $message }}</button>
                    </li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
