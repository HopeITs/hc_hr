{{-- Local-only dev button: opens the export-preview route (see
     routes/employees-management.php), the print layout as plain HTML. It holds
     the same export/salary/city gates as the PDF, since it carries the pay clause. --}}
<a {{
    $attributes->merge([
        'class' => 'cursor-pointer p-2 text-ink-2 hover:text-ink inline-flex items-center',
        'target' => '_blank',
        'title' => __('general.export').' (dev preview)',
    ])
}}>
    <x-icons.export/>
</a>
