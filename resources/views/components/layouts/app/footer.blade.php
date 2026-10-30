<footer
    {{$attributes->merge([
    'class' => 'border-t-1 shadow-e1 flex  items-center justify-center p-3  antialiased'
])}}
  style="width: -webkit-fill-available;">
    <p class="mb-2 text-body text-center text-ink-2 sm:mb-0">
        &copy; {{date('Y') .' - '. __('auth.copyright')}}

    </p>
</footer>
