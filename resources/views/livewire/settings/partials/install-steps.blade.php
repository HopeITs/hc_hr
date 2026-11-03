{{-- One platform's install steps (lang/{en,ar}/pwa.php → platforms.<key>). --}}
<h3 class="mb-2 font-bold text-ink">{{ __("pwa.platforms.{$platform}.title") }}</h3>
<ol class="list-decimal space-y-1.5 ps-5 marker:font-semibold marker:text-ink">
    @foreach (__("pwa.platforms.{$platform}.steps") as $step)
        <li>{{ $step }}</li>
    @endforeach
</ol>
