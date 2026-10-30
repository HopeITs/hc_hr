<?php

// الساعة الكبيرة (Dashboard\ClockCard و Dashboard\ClockPresence): يوم العمل ومن
// هو متصل الآن. ‏:time ساعات:دقائق، مثل 3:15. السطر القصير تحت الوقت يبقى في dashboard.php ('clock').
return [
    'work' => [
        'progress' => 'التقدّم في يوم العمل',
        'done' => 'أمضيت في الدوام :time',
        'not-started' => 'لم يبدأ الدوام بعد',
        'over' => 'انتهى دوامك اليوم',
        'hours' => 'الدوام :hours',
        'holiday' => 'عطلة رسمية: :name',
    ],
    'presence' => [
        'title' => 'متصلون الآن',
        'count' => ':n من :all',
        'none' => 'لا أحد غيرك من فريقك متصل الآن.',
        'more' => '+:n',
        'more-label' => 'و:n آخرون',
    ],
];
