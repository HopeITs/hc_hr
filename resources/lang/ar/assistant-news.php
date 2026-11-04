<?php

// عنصر أخبار وفعاليات أعدّه المساعد (App\Services\Assistant\Drafts\NewsDraft).
return [
    'title' => 'مسودة خبر أو فعالية',
    'fields' => [
        'type' => 'النوع',
        'title_ar' => 'العنوان (بالعربية)',
        'title_en' => 'العنوان (بالإنجليزية)',
        'description_ar' => 'النص (بالعربية)',
        'description_en' => 'النص (بالإنجليزية)',
        'event' => 'موعد الفعالية',
        'event_starts_at' => 'بداية الفعالية',
        'event_ends_at' => 'نهاية الفعالية',
        'display_until' => 'يُعرض حتى',
        'related_to' => 'مرتبط بـ',
        'related_id' => 'العنصر المرتبط',
        'related' => 'مرتبط بـ',
        'audience_type' => 'نوع الجمهور',
        'audience_ids' => 'الجمهور',
        'audience' => 'الجمهور',
        'publication' => 'النشر',
    ],
    'publish_now' => 'يُنشر فوراً عند التأكيد',
    'created' => 'نُشر العنصر. يمكنك إضافة صورة أو تغيير موعد النشر من صفحة تعديله.',
    'open' => 'فتح العنصر',
    'open-list' => 'فتح الأخبار والفعاليات',
];
