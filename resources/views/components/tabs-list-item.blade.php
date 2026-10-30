@props(['active' => false , 'activeTab' => 'all'])

<li class="me-2">
     <button type="button"
        {{$attributes->merge([
     'class' =>  $active ?  'inline-block px-4 py-3 rounded-control text-on-brand bg-primary' : 'inline-block px-4 py-3 rounded-control hover:text-ink hover:bg-hover'
 ])}}
        wire:click="setActiveTab('{{$activeTab}}')">
        {{$slot}}
     </button>
</li>
