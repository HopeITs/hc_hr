{{--
    Kept in the DOM even when empty, on purpose. A live region has to exist
    before the text arrives for the update to be announced, and the element
    also reserves its own vertical space — removing it when empty would shift
    the surrounding form layout.

    data-inline-error lets the form's error summary see that this message is
    already on screen (see errors-validation-list).
--}}
<span role="alert"
      aria-live="polite"
      data-inline-error
      {{ $attributes->merge(['class' => 'hc-err mt-3']) }}>{{$slot}}</span>
