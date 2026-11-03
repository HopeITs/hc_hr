{{-- Phones: a 2×2 grid of equal tabs (four statuses never wrap unevenly); sm and up: one centred, wrapping row. --}}
<ul {{$attributes->merge(['class' => 'grid grid-cols-2 gap-2 text-body font-medium text-center text-ink-2 sm:flex sm:flex-wrap sm:justify-center'])}}>
{{$slot}}
</ul>
