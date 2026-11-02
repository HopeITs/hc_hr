<?php

// خبر أعدّه المساعد (App\Services\Assistant\Drafts\NewsDraft).
return [
    'title' => 'مسودة خبر',
    'fields' => [
        'title_ar' => 'العنوان (بالعربية)',
        'title_en' => 'العنوان (بالإنجليزية)',
        'description_ar' => 'النص (بالعربية)',
        'description_en' => 'النص (بالإنجليزية)',
        'event_date' => 'تاريخ الحدث',
        'display_until' => 'يُعرض حتى',
        'target_type' => 'نوع الجهة المستهدفة',
        'target' => 'الجهة المستهدفة',
    ],
    'targets' => [
        'all' => 'الجميع',
        'city' => 'المدينة',
        'center' => 'المركز',
        'program' => 'البرنامج',
        'employee' => 'الموظف',
    ],
    'errors' => [
        'target' => 'اختر الجهة المستهدفة من القائمة التي تعرضها صفحة الأخبار.',
    ],
    'created' => 'نُشر الخبر. يمكنك إضافة صورة له من صفحته.',
    'open' => 'فتح الخبر',
    'open-list' => 'فتح الأخبار',
];
