@props([
    'id' => 'btn'
])
<div class="flex items-center">
    <input {{$attributes->merge([
    'id' => $id,
    'type' => 'radio',
    'class' => 'size-4 rounded-inset text-primary bg-neutral-100 border-neutral-300 focus:ring-primary  focus:ring-2 '
])}}
    >
    <label for="{{$id}}" class="ms-2 text-body font-medium text-secondary-medium ">{{$slot}}</label>
</div>
