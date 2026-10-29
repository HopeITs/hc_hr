{{--
    A dashboard tile's icon (DashboardLayout::icon): the sidebar's own icon
    (components/icons/{name}) when there is one, so a shortcut looks like its
    menu entry, otherwise the line icon of that name (<x-ic>).
--}}
@props(['name' => 'file'])

@if (view()->exists('components.icons.'.$name))
    <x-dynamic-component :component="'icons.'.$name" {{ $attributes }} />
@else
    <x-ic :name="$name" {{ $attributes }} />
@endif
