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
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و:max.',
        'string' => 'يجب أن يكون عدد أحرف :attribute بين :min و:max.',
        'array' => 'يجب أن يكون عدد عناصر :attribute بين :min و:max.',
    ],

    'boolean' => 'يجب أن تكون قيمة :attribute نعم أو لا.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخًا صالحًا.',
    'date_equals' => ':attribute يجب أن يكون تاريخًا يساوي :date.',
    'date_format' => ':attribute لا يطابق الصيغة :format.',
    'different' => ':attribute و :other يجب أن يكونا مختلفين.',
    'digits' => 'يجب أن يكون عدد خانات :attribute :digits بالضبط.',
    'digits_between' => 'يجب أن يكون عدد خانات :attribute بين :min و:max.',
    'dimensions' => ':attribute يحتوي على أبعاد صورة غير صالحة.',
    'distinct' => 'حقل :attribute يحتوي على قيمة مكررة.',
    'email' => ':attribute يجب أن يكون بريدًا إلكترونيًا صالحًا.',
    'ends_with' => ':attribute يجب أن ينتهي بأحد القيم التالية: :values.',
    'exists' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'failed' => 'بيانات الاعتماد هذه لا تطابق سجلاتنا.',
    'file' => ':attribute يجب أن يكون ملفًا.',
    'filled' => 'حقل :attribute مطلوب.',

    'gt' => [
        'array' => 'يجب أن يكون عدد عناصر :attribute أكثر من :value.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أكبر من :value.',
        'string' => 'يجب أن يكون عدد أحرف :attribute أكثر من :value.',
    ],

    'gte' => [
        'array' => 'يجب ألا يقل عدد عناصر :attribute عن :value.',
        'file' => 'يجب ألا يقل حجم :attribute عن :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أكبر من أو يساوي :value.',
        'string' => 'يجب ألا يقل عدد أحرف :attribute عن :value.',
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
        'array' => 'يجب أن يكون عدد عناصر :attribute أقل من :value.',
        'file' => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أقل من :value.',
        'string' => 'يجب أن يكون عدد أحرف :attribute أقل من :value.',
    ],

    'lte' => [
        'array' => 'يجب ألا يزيد عدد عناصر :attribute عن :value.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :value كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون أقل من أو يساوي :value.',
        'string' => 'يجب ألا يزيد عدد أحرف :attribute عن :value.',
    ],

    'max' => [
        'array' => 'يجب ألا يزيد عدد عناصر :attribute عن :max.',
        'file' => 'يجب ألا يزيد حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألا تزيد قيمة :attribute عن :max.',
        'string' => 'يجب ألا يزيد عدد أحرف :attribute عن :max.',
    ],

    'mimes' => ':attribute يجب أن يكون ملفًا من النوع: :values.',
    'mimetypes' => ':attribute يجب أن يكون ملفًا من النوع: :values.',

    'min' => [
        'array' => 'يجب ألا يقل عدد عناصر :attribute عن :min.',
        'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string' => 'يجب ألا يقل عدد أحرف :attribute عن :min.',
    ],

    'not_in' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => ':attribute يجب أن يكون رقمًا.',
    'password' => 'كلمة المرور غير صحيحة.',
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
        'array' => 'يجب أن يكون عدد عناصر :attribute :size بالضبط.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => ':attribute يجب أن يكون :size.',
        'string' => 'يجب أن يكون عدد أحرف :attribute :size بالضبط.',
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
    'leave-max-date' => 'عدد أيام الإجازة المطلوبة (:days) يتجاوز عدد الأيام المسموح به (:max).',
    'annual-leave-max-date' => 'لقد تجاوزت رصيدك المتراكم من الإجازات السنوية.',
    // قواعد أضافها Laravel بعد كتابة هذا الملف؛ بدونها تظهر الرسالة الإنجليزية في الواجهة العربية.
    'any_of' => 'قيمة :attribute غير صالحة.',
    'array_keys' => 'يجب ألا يحتوي :attribute إلا على المفاتيح التالية: :values.',
    'ascii' => 'يجب أن يحتوي :attribute على حروف لاتينية وأرقام ورموز أحادية البايت فقط.',
    'base64' => 'يجب أن يكون :attribute نصًا صالحًا بترميز Base64.',
    'can' => 'يحتوي :attribute على قيمة غير مصرّح بها.',
    'contains' => 'ينقص :attribute قيمة مطلوبة.',
    'decimal' => 'يجب أن يكون عدد المنازل العشرية في :attribute :decimal.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other بقيمة :value.',
    'doesnt_contain' => 'يجب ألا يحتوي :attribute على أي من القيم التالية: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي :attribute بأي من القيم التالية: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ :attribute بأي من القيم التالية: :values.',
    'encoding' => 'يجب أن يكون :attribute بترميز :encoding.',
    'enum' => 'القيمة المحددة لـ :attribute غير صالحة.',
    'extensions' => 'يجب أن يكون امتداد :attribute أحد الامتدادات التالية: :values.',
    'hex_color' => 'يجب أن يكون :attribute لونًا صالحًا بالصيغة الست عشرية.',
    'in_array_keys' => 'يجب أن يحتوي :attribute على مفتاح واحد على الأقل من المفاتيح التالية: :values.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صالحًا.',
    'max_digits' => 'يجب ألا يزيد عدد خانات :attribute عن :max.',
    'min_digits' => 'يجب ألا يقل عدد خانات :attribute عن :min.',
    'missing' => 'يجب ألا يكون :attribute موجودًا.',
    'missing_if' => 'يجب ألا يكون :attribute موجودًا عندما يكون :other بقيمة :value.',
    'missing_unless' => 'يجب ألا يكون :attribute موجودًا ما لم يكن :other بقيمة :value.',
    'missing_with' => 'يجب ألا يكون :attribute موجودًا عند وجود :values.',
    'missing_with_all' => 'يجب ألا يكون :attribute موجودًا عند وجود كل :values.',
    'multiple_of' => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'present_if' => 'يجب أن يكون :attribute موجودًا عندما يكون :other بقيمة :value.',
    'present_unless' => 'يجب أن يكون :attribute موجودًا ما لم يكن :other بقيمة :value.',
    'present_with' => 'يجب أن يكون :attribute موجودًا عند وجود :values.',
    'present_with_all' => 'يجب أن يكون :attribute موجودًا عند وجود كل :values.',
    'prohibited_if_accepted' => 'حقل :attribute محظور عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute محظور عند رفض :other.',
    'prohibits' => 'وجود :attribute يمنع وجود :other.',
    'required_array_keys' => 'يجب أن يحتوي :attribute على قيم لكل من: :values.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'uppercase' => 'يجب أن يكون :attribute بحروف كبيرة فقط.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صالحًا.',

    'custom' => [
        // رسالة «الصيغة غير صالحة» لا تقول ما المطلوب كتابته،
        // والحقلان يرفضان الأبجدية الأخرى فيقولان ذلك.
        'data.title_en' => [
            'regex' => 'المسمّى الإنكليزي يقبل أحرفاً إنكليزية ومسافات فقط.',
        ],
        'data.title_ar' => [
            'regex' => 'المسمّى العربي يقبل أحرفاً عربية ومسافات فقط.',
        ],

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
        'end_at' => 'تاريخ النهاية',
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

    'recruitment_file' => [
        'unsupported' => 'نوع الملف غير مسموح لهذا الحقل.',
        'unreadable' => 'تعذّرت قراءة الملف. يرجى رفعه مرة أخرى.',
        'too_large' => 'يجب ألا يتجاوز حجم الملف 10 ميغابايت.',
        'too_many' => 'تم الوصول إلى الحد الأقصى لعدد الملفات في هذا الحقل.',
        'executable' => 'لا يُسمح بالملفات التنفيذية أو التي تحتوي على شيفرات.',
        'content_mismatch' => 'محتوى الملف لا يطابق امتداده.',
    ],

];
