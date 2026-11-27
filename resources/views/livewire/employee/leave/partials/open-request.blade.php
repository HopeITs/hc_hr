<button type="button"
        x-on:click.stop="{{ $action }}"
        aria-haspopup="dialog"
        aria-label="{{ __('general.a11y.open-leave-request-for', ['name' => $name]) }}"
        class="max-w-full cursor-pointer text-start hover:underline">
    <bdi>{{ $name }}</bdi>
</button>
