@props([
    'activeTab'
])
<div {{$attributes->merge([
    'class' => 'flex flex-col lg:flex-row gap-4 lg:gap-8'
])}}
>
    {{$slot}}

</div>
