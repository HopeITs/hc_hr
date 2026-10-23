

<script defer nonce="{{ Vite::cspNonce() }}" src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/js/all.min.js" integrity="sha512-gBYquPLlR76UWqCwD06/xwal4so02RjIR0oyG1TIhSGwmBTRrIkQbaPehPF8iwuY9jFikDHMGEelt0DtY7jtvQ==" crossorigin="anonymous" referrerpolicy="no-referrer" data-navigate-once></script>

<script defer nonce="{{ Vite::cspNonce() }}" src="https://cdn.jsdelivr.net/npm/flowbite@3.1.2/dist/flowbite.min.js" data-navigate-once></script>


@livewireScripts
@yield('js')
@stack('js')
