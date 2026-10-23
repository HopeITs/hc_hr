<?php

// قسم الموظفين: جدول الموظفين، وتبويبات نموذج الموظف، وصفحة الملف الشخصي،
// ومعاينة حقل رفع الملفات المشترك. الكلمات العامة (حفظ، حذف، فعّال، ...) تبقى
// في general.php؛ هنا فقط ما يضيفه هذا القسم.

return [
    'table' => [
        'id' => 'الرقم',
    ],

    'form' => [
        'username-placeholder' => 'اسم المستخدم',
        'work-channel' => ':channel (العمل)',
        'add-skill' => 'أضف مهارة',
        'add-interest' => 'أضف اهتمامًا',
    ],

    'courses' => [
        'organization-hope-center' => 'مركز الرجاء',
        'organization-personal' => 'شخصي',
        'organization-other' => 'أخرى',
    ],

    'items' => [
        'title' => 'جميع العناصر',
        'delivered' => 'تم التسليم',
        'received' => 'تم الاستلام',
    ],

    'file-input' => [
        'new-profile-photo' => 'الصورة الشخصية الجديدة',
        'current-profile-photo' => 'الصورة الشخصية الحالية',
        'new-photo' => 'الصورة الجديدة',
        'current-photo' => 'الصورة الحالية',
        'no-image' => 'لا توجد صورة',
        'no-file' => 'لا يوجد ملف',
        'file' => 'ملف',
    ],

    'statistics' => [
        'quarter' => 'الربع :number',
    ],

    'validation' => [
        'city-scope' => 'يمكنك إدارة موظفي مدينتك النشطة فقط.',
        'manager-city' => 'يجب أن يكون المدير المختار من مدينة الموظف نفسها.',
        'work-phone-format' => 'يجب أن يتكون هاتف العمل من 10 أرقام تماماً وأن يبدأ بـ 09.',
        'personal-phone-format' => 'يجب أن يتكون الهاتف الشخصي من 10 أرقام تماماً وأن يبدأ بـ 09.',
        'national-number-syrian' => 'يجب أن يتكون الرقم الوطني للسوريين من 11 رقماً تماماً.',
    ],
    'drawer' => [
        'pending-requests' => 'طلبات معلّقة',
        'leave-request' => 'طلب إجازة',
        'position-n' => 'المنصب :n',
    ],
];
