{{-- Local-only dev button: opens the export-preview route (see
     routes/employees-management.php), which skips the export Gate/city
     check so the print layout can be checked without matching permissions. --}}
<a {{
    $attributes->merge([
        'class' => 'cursor-pointer p-2 text-neutral-500 inline-flex items-center',
        'target' => '_blank',
        'title' => __('general.export').' (dev preview)',
    ])
}}>
    <x-icons.export/>
</a>
