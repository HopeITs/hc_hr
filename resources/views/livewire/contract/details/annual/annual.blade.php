@include('livewire.contract.details._styles')

<div class="contract-a4 text-neutral-900 dark:text-neutral-100">
    <div class="contract-body space-y-">
        <div wire:key="annual-container-{{ $pageNo }}">
            @switch($pageNo)
                @case(1) @include('livewire.contract.details.annual.pages.page1') @break
                @case(2) @include('livewire.contract.details.annual.pages.page2') @break
                @case(3) @include('livewire.contract.details.annual.pages.page3') @break
                @case(4) @include('livewire.contract.details.annual.pages.page4') @break
                @case(5) @include('livewire.contract.details.annual.pages.page5') @break
            @endswitch
        </div>
    </div>

    <div class="contract-footer">{{ $pageNo }} of {{ $totalPages }}</div>
</div>

@include('livewire.contract.details._pager')

<div class="hidden print:block">
    @for($i=1; $i<=$totalPages; $i++)
        <div class="contract-a4 p-8 {{ $i < $totalPages ? 'page-break' : '' }}">
            @include("livewire.contract.details.annual.pages.page{$i}")
        </div>
    @endfor
</div>
