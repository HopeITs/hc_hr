<?php

// الأخبار والفعاليات. الأقسام تحت المفاتيح الأساسية تضاف لكل شاشة.
return [
    'title' => 'الأخبار والفعاليات',

    'types' => [
        'news' => 'خبر',
        'announcement' => 'إعلان',
        'event' => 'فعالية',
    ],
    'types_hint' => [
        'news' => 'شيء حدث أو تحديث للمشاركة.',
        'announcement' => 'معلومة تنظيمية مهمة، حتى تاريخ معيّن اختيارياً.',
        'event' => 'شيء مجدول ليحدث في تاريخ محدد.',
    ],

    'states' => [
        'draft' => 'مسودة',
        'scheduled' => 'مجدول',
        'published' => 'منشور',
        'expired' => 'منتهي',
        'archived' => 'مؤرشف',
    ],

    'related' => [
        'organization' => 'المنظمة',
        'city' => 'مدينة',
        'center' => 'مركز',
        'program' => 'برنامج / مشروع',
        'department' => 'قسم',
        'employee' => 'موظف',
    ],

    'audience' => [
        'all' => 'جميع الموظفين',
        'cities' => 'مدن محددة',
        'centers' => 'مراكز محددة',
        'programs' => 'برامج محددة',
        'departments' => 'أقسام محددة',
        'employees' => 'موظفون محددون',
    ],

    'timing' => [
        'upcoming' => 'قادمة',
        'today' => 'اليوم',
        'past' => 'سابقة',
    ],
];
