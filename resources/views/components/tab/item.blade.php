@props(['title' , 'active' => false ,'activeTab'])
<li>
    <button {{$attributes->merge([
    'class' => $active ? 'w-full flex items-center p-3 rounded-control bg-primary text-on-brand ' : 'w-full flex items-center p-3 rounded-control bg-soft hover:bg-hover disabled:hover:bg-hover disabled:text-ink-3'
])
}} wire:click="setActiveTab('{{$activeTab}}')"

    >
        {{$slot}}
        <span class="ms-2 font-semibold">{{$title}}</span>
    </button>
</li>
