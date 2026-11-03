@props(['active' => false , 'activeTab' => 'all'])

<li class="min-w-0">
     <button type="button"
        aria-pressed="{{ $active ? 'true' : 'false' }}"
        {{$attributes->merge([
     'class' => 'inline-flex min-h-11 w-full items-center justify-center break-words rounded-control px-4 py-2 sm:w-auto '.($active ? 'text-on-brand bg-primary' : 'hover:text-ink hover:bg-hover')
 ])}}
        wire:click="setActiveTab('{{$activeTab}}')">
        {{$slot}}
     </button>
</li>
