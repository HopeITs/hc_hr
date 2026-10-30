{{-- Intrinsic size of assets/img/001_HC.png; reserves the box so the logo cannot shift layout.
     CSS classes passed by the caller still win over the width/height attributes.
     `app-logo` shows the (all-red) logo white in dark mode (app.css, --logo-filter). --}}
<img src="{{ asset('assets/img/001_HC.png') }}"
     alt="{{ config('app.name') }}"
     width="276"
     height="74"
     decoding="async"
     fetchpriority="high"
     {{$attributes->merge(['class' => 'app-logo'])}}
/>
