@php
    use Illuminate\Support\Str;

   $icons = [
    'super-admin' => '
    <svg class="size-4 text-ink-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M3 20h18l-2-9-4 4-4-8-4 8-4-4-2 9z"/>
    </svg>',

    'general-manager' => '
    <svg class="size-4 text-ink-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="3" width="7" height="18" rx="1"/>
      <rect x="14" y="3" width="7" height="18" rx="1"/>
      <path d="M3 9h7M14 9h7M3 15h7M14 15h7"/>
    </svg>',

    'hr-manager' => '
    <svg class="size-4 text-ink-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="8" cy="7" r="4"/>
      <path d="M2 21a6 6 0 0 1 12 0"/>
      <path d="M16 11l1.2 2.4L20 14l-2 2 .5 2.8L16 17l-2.5 1.8L14 16l-2-2.1 2.8-.5L16 11z"/>
    </svg>',

    'hr' => '
    <svg class="size-4 text-ink-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <circle cx="9" cy="7" r="4"/>
      <path d="M2 21a7 7 0 0 1 14 0"/>
      <circle cx="17" cy="8" r="3"/>
      <path d="M17 21a6 6 0 0 1 6-6"/>
    </svg>',

    'employee' => '
    <svg class="size-4 text-ink-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <rect x="3" y="4" width="18" height="16" rx="2"/>
      <circle cx="9" cy="12" r="2.5"/>
      <path d="M15 10h4M15 14h4"/>
    </svg>',
];



    $iconFor = function ($role) use ($icons) {
        $key = Str::slug($role);
        return $icons[$key] ?? $icons['employee'];
    };
@endphp

<div class="space-y-2" id="roleList">
    @if(!empty($roles) && count($roles))
        @foreach($roles as $role)
            <div>
                <button
                    type="button"
                    wire:click="switchTo('{{ $role }}')"
                    class="group w-full rounded-surface border-2 border-line-soft p-3 text-body font-medium
                           hover:border-line hover:bg-hover transition flex items-center gap-3">
                    <span class="inline-grid place-items-center size-6 rounded-control border border-line bg-card">
                        {!! $iconFor($role) !!}
                    </span>

                    <span class="truncate">{{ role_label($role) }}</span>

                    <svg class="ms-auto size-4 opacity-0 group-hover:opacity-100 transition rtl:rotate-180" aria-hidden="true" viewBox="0 0 24 24"
                         fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M9 18l6-6-6-6"/>
                    </svg>
                </button>
            </div>
        @endforeach
    @else
        <div class="text-small text-ink-2">{{ __('No other roles available.') }}</div>
    @endif
</div>

