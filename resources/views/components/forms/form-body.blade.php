<form {{$attributes->merge([
    'class' => 'space-y-4 p-4 border border-line-soft rounded-control bg-card'
])}}>
    {{$slot}}
</form>
