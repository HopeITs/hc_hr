@props(['filled' => false])
<svg {{ $attributes->merge(['class' => 'size-4']) }}
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="{{ $filled ? 'currentColor' : 'none' }}"
    stroke="currentColor"
    stroke-width="1.5"
    aria-hidden="true">
    <path stroke-linejoin="round" d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.13 5.11a.56.56 0 0 0 .47.34l5.52.44c.5.04.7.66.32.99l-4.2 3.6a.56.56 0 0 0-.18.56l1.28 5.38a.56.56 0 0 1-.84.61l-4.73-2.89a.56.56 0 0 0-.58 0l-4.73 2.89a.56.56 0 0 1-.84-.61l1.28-5.38a.56.56 0 0 0-.18-.56l-4.2-3.6a.56.56 0 0 1 .32-.99l5.52-.44a.56.56 0 0 0 .47-.34l2.13-5.11Z" />
</svg>
