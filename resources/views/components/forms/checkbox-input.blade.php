@props([
    'id' => 'btn'
])
<div class="flex items-center">
    <input {{$attributes->merge([
    'id' => $id,
    'type' => 'radio',
    'class' => 'hc-check rounded-full focus:ring-0'
])}}
    >
    <label for="{{$id}}" class="inline-flex min-h-11 cursor-pointer items-center ps-2 text-body font-medium text-ink">{{$slot}}</label>
</div>
