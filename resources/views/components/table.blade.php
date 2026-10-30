<div class="mb-4" >
    <div class="w-1/4">
        <x-forms.input  type="text"  id="search" name="search" :placeholder="__('general.search')" wire:model.live="search"/>
    </div>

</div>
<div class="card border border-line-soft rounded-surface shadow-e1 bg-card mt-2 mb-8">
    <div class="card-body p-0">

        <div class="custom-datatable-filter overflow-x-auto">
            <table id="datatable" class="table w-full border-b border-line-soft text-start">
            <thead class="thead-light">
                <tr>
                    {{ $headings ?? '' }}
                </tr>
                </thead>
                <tbody class="bg-card divide-y divide-line-soft">
                {{ $body ?? '' }}
                </tbody>

            </table>
        </div>
    </div>

    <div class="p-4">
        {{ $pagination }}
    </div>
</div>
