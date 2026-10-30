{{-- One row of the help topic list; `item` is the Alpine loop variable. --}}
<button type="button"
        x-on:click="show(item.key)"
        :data-help-topic-link="item.key"
        class="block w-full rounded-control border border-line-soft bg-card px-3 py-2.5 text-start transition hover:border-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-offset-2 motion-reduce:transition-none">
    <span class="block text-body font-semibold text-ink" x-text="item.title"></span>
    <span class="mt-0.5 block text-small text-ink-2" x-text="item.summary"></span>
</button>
