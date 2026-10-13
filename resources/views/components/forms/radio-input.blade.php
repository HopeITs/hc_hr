@props([
    'id' => 'btn'
])
<div class="flex items-center">
    <input {{$attributes->merge([
    'id' => $id,
    'type' => 'radio',
    'class' => 'size-4 text-red-600 bg-neutral-100 border-neutral-300 focus:border-red-200  focus:ring-0 '
])}}
    >
    <label for="{{$id}}" class="ms-2 text-body font-medium text-neutral-900 ">{{$slot}}</label>
</div>
