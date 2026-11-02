<?php

// عقد جديد جهّزه المساعد (ContractDraft)؛ لا يُنشأ إلا بعد أن يؤكده المستخدم.
return [
    'title' => 'عقد جديد',
    'created' => 'تم إنشاء عقد :name.',
    'open' => 'فتح العقد',
    'day-range' => 'من :from إلى :to',
    'pay' => ':amount :currency لكل :per',
    'fields' => [
        'employee' => 'الموظف',
        'position' => 'الوظيفة',
        'city' => 'المدينة',
        'contract_type' => 'نوع العقد',
        'from' => 'من',
        'to' => 'إلى',
        'schedule' => 'وقت العمل',
        'weekly_hours' => 'ساعات العمل أسبوعيًا',
        'pay' => 'الأجر',
    ],
    'errors' => [
        'employee' => 'اختر موظفًا يحق لك إنشاء عقد له.',
        'days' => 'اختر أيام العمل (أول يوم وآخر يوم في الأسبوع).',
    ],
];
