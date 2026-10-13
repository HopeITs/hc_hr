<?php

return [

    'lowercase' => ':attribute يجب أن يكون بحروف صغيرة فقط.',
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other بقيمة :value.',
    'active_url' => ':attribute ليس رابطًا صالحًا.',
    'after' => ':attribute يجب أن يكون تاريخًا بعد :date.',
    'after_or_equal' => ':attribute يجب أن يكون تاريخًا بعد أو يساوي :date.',
    'alpha' => ':attribute يجب أن يحتوي على حروف فقط.',
    'alpha_dash' => ':attribute يمكن أن يحتوي فقط على حروف وأرقام وشرطات وشرطات سفلية.',
    'alpha_num' => ':attribute يجب أن يحتوي فقط على حروف وأرقام.',
    'array' => ':attribute يجب أن يكون مصفوفة.',
    'one-year-period' => ':attribute يجب أن يكون ضمن فترة سنة واحدة.',
    'before' => ':attribute يجب أن يكون تاريخًا قبل :date.',
    'before_or_equal' => ':attribute يجب أن يكون تاريخًا قبل أو يساوي :date.',

    'between' => [
        'file' => ':attribute يجب أن يكون بين :min و :max كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون بين :min و :max.',
        'string' => ':attribute يجب أن يكون بين :min و :max حروفًا.',
        'array' => ':attribute يجب أن يحتوي بين :min و :max عناصر.',
    ],

    'boolean' => 'حقل :attribute يجب أن يكون true أو false.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صالحًا.',
    'date_equals' => ':attribute يجب أن يكون تاريخًا يساوي :date.',
    'date_format' => ':attribute لا يطابق الصيغة :format.',
    'different' => ':attribute و :other يجب أن يكونا مختلفين.',
    'digits' => ':attribute يجب أن يكون :digits رقمًا.',
    'digits_between' => ':attribute يجب أن يكون بين :min و :max رقمًا.',
    'dimensions' => ':attribute يحتوي على أبعاد صورة غير صالحة.',
    'distinct' => 'حقل :attribute يحتوي على قيمة مكررة.',
    'email' => ':attribute يجب أن يكون بريدًا إلكترونيًا صالحًا.',
    'ends_with' => ':attribute يجب أن ينتهي بأحد القيم التالية: :values.',
    'exists' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'failed' => 'بيانات الاعتماد هذه لا تطابق سجلاتنا.',
    'file' => ':attribute يجب أن يكون ملفًا.',
    'filled' => 'حقل :attribute مطلوب.',

    'gt' => [
        'array' => ':attribute يجب أن يحتوي على أكثر من :value عناصر.',
        'file' => ':attribute يجب أن يكون أكبر من :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أكبر من :value.',
        'string' => ':attribute يجب أن يكون أكثر من :value حروفًا.',
    ],

    'gte' => [
        'array' => ':attribute يجب أن يحتوي على الأقل :value عناصر.',
        'file' => ':attribute يجب أن يكون على الأقل :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أكبر من أو يساوي :value.',
        'string' => ':attribute يجب أن يكون على الأقل :value حروفًا.',
    ],

    'image' => ':attribute يجب أن يكون صورة.',
    'in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'in_array' => 'حقل :attribute غير موجود في :other.',
    'integer' => ':attribute يجب أن يكون عددًا صحيحًا.',
    'ip' => ':attribute يجب أن يكون عنوان IP صالحًا.',
    'ipv4' => ':attribute يجب أن يكون عنوان IPv4 صالحًا.',
    'ipv6' => ':attribute يجب أن يكون عنوان IPv6 صالحًا.',
    'json' => ':attribute يجب أن يكون نص JSON صالحًا.',

    'lt' => [
        'array' => ':attribute يجب أن يحتوي على أقل من :value عناصر.',
        'file' => ':attribute يجب أن يكون أقل من :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أقل من :value.',
        'string' => ':attribute يجب أن يكون أقل من :value حروفًا.',
    ],

    'lte' => [
        'array' => ':attribute يجب ألا يحتوي على أكثر من :value عناصر.',
        'file' => ':attribute يجب ألا يكون أكبر من :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أقل من أو يساوي :value.',
        'string' => ':attribute يجب ألا يكون أكبر من :value حروفًا.',
    ],

    'max' => [
        'array' => ':attribute لا يجب أن يحتوي على أكثر من :max عناصر.',
        'file' => ':attribute لا يجب أن يكون أكبر من :max كيلوبايت.',
        'numeric' => ':attribute لا يجب أن يكون أكبر من :max.',
        'string' => ':attribute لا يجب أن يكون أكبر من :max حروفًا.',
    ],

    'mimes' => ':attribute يجب أن يكون ملفًا من النوع: :values.',
    'mimetypes' => ':attribute يجب أن يكون ملفًا من النوع: :values.',

    'min' => [
        'array' => ':attribute يجب أن يحتوي على الأقل :min عناصر.',
        'file' => ':attribute يجب أن يكون على الأقل :min كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون على الأقل :min.',
        'string' => ':attribute يجب أن يكون على الأقل :min حروفًا.',
    ],

    'not_in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => ':attribute يجب أن يكون رقمًا.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن تحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن تحتوي :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن تحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت :attribute هذه في تسريب بيانات سابق. يرجى اختيار كلمة مرور أخرى.',
    ],
    'present' => 'حقل :attribute يجب أن يكون موجودًا.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other بقيمة :value.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود كل :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أي من :values.',
    'prohibited' => 'حقل :attribute محظور.',
    'prohibited_if' => 'حقل :attribute محظور عندما يكون :other بقيمة :value.',
    'prohibited_unless' => 'حقل :attribute محظور ما لم يكن :other ضمن :values.',
    'same' => ':attribute و :other يجب أن يتطابقا.',

    'size' => [
        'array' => ':attribute يجب أن يحتوي على :size عناصر.',
        'file' => ':attribute يجب أن يكون :size كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون :size.',
        'string' => ':attribute يجب أن يكون :size حروفًا.',
    ],

    'starts_with' => ':attribute يجب أن يبدأ بأحد القيم التالية: :values.',
    'string' => ':attribute يجب أن يكون نصًا.',
    'timezone' => ':attribute يجب أن يكون منطقة زمنية صالحة.',
    'unique' => ':attribute مستخدم مسبقًا.',
    'uploaded' => 'فشل رفع :attribute.',
    'url' => 'صيغة :attribute غير صالحة.',
    'uuid' => ':attribute يجب أن يكون UUID صالحًا.',
    'birth_date' => 'يرجى اختيار تاريخ قبل اليوم.',
    'captcha' => 'فشل التحقق من CAPTCHA. الرجاء المحاولة مرة أخرى.',
    'leave-max-date' => 'إجمالي مدة الإجازة (:days يومًا) يتجاوز الحد المسموح به (:max يومًا).',
    'annual-leave-max-date' => 'لقد تجاوزت رصيدك المتراكم من الإجازات السنوية.',
    'custom' => [
        'image' => [
            'width' => 'يجب أن يكون عرض الصورة :val بكسل.',
            'height' => 'يجب أن يكون ارتفاع الصورة :val بكسل.',
            'min_width' => 'عرض الصورة يجب ألا يقل عن :val بكسل.',
            'max_width' => 'عرض الصورة يجب ألا يتجاوز :val بكسل.',
            'min_height' => 'ارتفاع الصورة يجب ألا يقل عن :val بكسل.',
            'max_height' => 'ارتفاع الصورة يجب ألا يتجاوز :val بكسل.',
        ],

        'type' => [
            'service_requires_freelance' => 'عقد الخدمة يجب أن يكون دوام حر.',
            'freelance_requires_service' => 'دوام حر متاح فقط لعقود الخدمة.',
        ],

        'weekly_hours' => [
            'required_unless_service' => 'يجب أن تكون الساعات الأسبوعية رقمًا صريحًا لهذه الفئة من العقود — أكمل نطاق الأيام/الساعات، أو صندوق "ساعة/أسبوع" في الدوام المرن.',
        ],

        'salary' => [
            'hidden_from_role' => 'دورك الحالي لا يطّلع على قيم الأجور ولا يعدّلها، لذلك لا يمكنه إنشاء عقد من الصفر. اطلب من الموارد البشرية إنشاءه، أو جدّد عقدًا قائمًا بدلًا من ذلك.',
        ],

        'required-fields' => 'يرجى تعبئة الحقول المطلوبة أولًا.',
        'adult' => 'يجب أن يكون عمرك 16 عامًا على الأقل.',
        'password_rule' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل وتحتوي على حروف وأرقام ورموز.',
        'phone_rule' => 'رقم الهاتف يجب أن يحتوي فقط على أرقام والرموز + ( ) - .',
        'edit_expiry_date' => 'تاريخ الانتهاء الجديد يجب أن يكون مساويًا أو بعد تاريخ الانتهاء القديم.',
    ],

    'attributes' => [
        'email' => 'البريد الإلكتروني',
        'username' => 'اسم المستخدم',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'phone' => 'رقم الهاتف',
        'gender' => 'الجنس',
        'birth_date' => 'تاريخ الميلاد',
        'role' => 'الدور',
        'city_id' => 'اسم المدينة',
        'location' => 'الموقع',
        'message' => 'الرسالة',

        'name' => [
            'ar' => 'الاسم (بالعربية)',
            'en' => 'الاسم (بالإنجليزية)',
        ],

        'father_name' => [
            'ar' => 'اسم الأب (بالعربية)',
            'en' => 'اسم الأب (بالإنجليزية)',
        ],

        'mother_name' => [
            'ar' => 'اسم الأم (بالعربية)',
            'en' => 'اسم الأم (بالإنجليزية)',
        ],

        'address' => [
            'ar' => 'العنوان (بالعربية)',
            'en' => 'العنوان (بالإنجليزية)',
        ],

        'item' => [
            'weekly_hours' => 'ساعات العمل الأسبوعية',

            'type' => [
                'ar' => 'النوع (بالعربية)',
                'en' => 'النوع (بالإنجليزية)',
            ],

            'name' => [
                'ar' => 'الاسم (بالعربية)',
                'en' => 'الاسم (بالإنجليزية)',
            ],

            'description' => [
                'ar' => 'الوصف (بالعربية)',
                'en' => 'الوصف (بالإنجليزية)',
            ],

            'order' => 'الترتيب',
            'place' => 'المكان',
        ],
        'start_at' => 'تاريخ البداية',
        'today' => 'اليوم',
        'end_at' => 'تاريخ انهاية',
        'reason' => 'السبب',

        'g-recaptcha-response' => 'رمز التحقق (لست روبوتًا)',
        'leave_balance_id' => 'نوع الإجازة',
    ],

    'city_not_authorized' => 'يمكنك إدارة السجلات الخاصة بمدينتك فقط.',

    'secure_image' => [
        'unsupported' => 'يُقبل فقط رفع الصور بصيغة JPEG أو PNG أو WebP.',
        'unreadable' => 'تعذّرت قراءة هذا الملف كصورة. يرجى رفع ملف آخر.',
        'too_large' => 'يجب ألا يتجاوز حجم الصورة :max.',
        'too_big' => 'يجب ألا تتجاوز أبعاد الصورة :width × :height بكسل.',
        'too_many_pixels' => 'دقة الصورة عالية جدًا. يرجى تصغيرها قبل الرفع.',
        'aspect_ratio' => 'أبعاد الصورة غير متناسبة. يجب ألا يزيد الضلع الأطول عن :ratio أضعاف الأقصر.',
    ],

];
