@props([
    'id' => 'btn'
])
<div class="flex items-center">
    <input {{$attributes->merge([
    'id' => $id,
    'type' => 'radio',
    'class' => 'size-4 rounded-inset text-red-600 bg-neutral-100 border-neutral-300 focus:ring-red-500  focus:ring-2 '
])}}
    >
    <label for="{{$id}}" class="ms-2 text-body font-medium text-secondary-medium ">{{$slot}}</label>
</div>
