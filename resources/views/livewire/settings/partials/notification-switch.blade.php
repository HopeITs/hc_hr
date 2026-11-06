{{--
    One autosaving switch row on the notification settings page.
      id, model (wire:model path), label, description (optional), icon (optional), disabled (optional)
    The description is the switch's aria-describedby; both the words and the
    switch's own 44px box are labels for it, so the whole row can be tapped.
--}}
@php
    $description ??= null;
    $icon ??= null;
    $disabled ??= false;
    $error = $errors->first($model);
@endphp

<div class="flex items-start justify-between gap-4 py-3">
    <div class="flex min-w-0 items-start gap-3">
        @if ($icon)
            <x-ic :name="$icon" class="mt-1 size-5 text-ink-2" />
        @endif
        <div class="min-w-0">
            <label for="{{ $id }}" class="block cursor-pointer font-bold text-ink">{{ $label }}</label>
            @if ($description)
                <p id="{{ $id }}-desc" class="mt-0.5 text-small text-ink-2">{{ $description }}</p>
            @endif
            @if ($error)
                <p id="{{ $id }}-error" class="hc-err mt-1" data-inline-error>{{ $error }}</p>
            @endif
        </div>
    </div>

    <label for="{{ $id }}" class="-my-2.5 inline-flex min-h-11 min-w-11 shrink-0 cursor-pointer items-center justify-center">
        <input id="{{ $id }}" type="checkbox" role="switch" class="hc-switch disabled:cursor-not-allowed disabled:opacity-50"
               wire:model.live="{{ $model }}"
               @disabled($disabled)
               @if ($description || $error) aria-describedby="{{ trim(($description ? $id.'-desc' : '').' '.($error ? $id.'-error' : '')) }}" @endif
               @if ($error) aria-invalid="true" @endif>
    </label>
</div>
