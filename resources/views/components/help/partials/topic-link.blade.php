{{-- One row of the help topic list; `item` is the Alpine loop variable. --}}
<button type="button"
        x-on:click="show(item.key)"
        :data-help-topic-link="item.key"
        class="block w-full rounded-control border border-neutral-200 bg-white px-3 py-2.5 text-start transition hover:border-neutral-400 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary motion-reduce:transition-none">
    <span class="block text-body font-semibold text-neutral-900" x-text="item.title"></span>
    <span class="mt-0.5 block text-small text-secondary-medium" x-text="item.summary"></span>
</button>
