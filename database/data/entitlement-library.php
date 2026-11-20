<?php

/*
 * The Permissions Library catalog (meeting 19/11, track 5), loaded by
 * EntitlementLibrarySeeder. Built from the organisation's real positions,
 * departments, programs and mail lists: what each job needs outside HopeHR.
 * Every name stays editable on the library page; running the seeder again
 * adds what is missing and never removes a link HR made by hand.
 *
 * Each item:
 *   en, ar, category (Entitlement::CATEGORIES), list (a mail list address),
 *   description, and who gets it by default:
 *     titles       regexes on the position's English title (case-insensitive)
 *     departments  department codes (MGMT, FDesk, Tchr, Comp, IT, Comm, HR,
 *                  Fin, MEAL, Fund, Logi, LegA, Kprs, Asst, PSS)
 *     programs     program names
 *     centers      words in the center's English name
 *     city         a city slug (SYR, ALP, HMS, ...): only positions of that city
 *     only_titles  title regexes the position must also match
 *     except_departments  department codes left out (keepers have no mailbox)
 *     levels       job level names: a job-level default, every city
 *   titles / departments / programs / centers are alternatives (any one is
 *   enough); city narrows them.
 *
 * The per-city staff Telegram groups are added by the seeder for every city
 * that has positions (see 'city_groups').
 */

$managers = ['Program Manager', 'Project Manager', 'Department Manager'];
$executives = ['^Chief Executive Officer', 'Deputy Chief Executive Officer', '^Consaltant', '^Consultant'];
$finManagers = ['^Financial Manager', 'Senior Financial Manager', 'Senior Accountant'];
$office = ['Fin', 'HR', 'IT', 'MEAL', 'Comm', 'Fund', 'Logi', 'LegA'];
$projectLead = ['^Program Manager', '^Relief Program Manager', '^Project Manager', 'Project Assistant Manager', '^Project Coordinator'];

return [

    'items' => [

        // ── Management ───────────────────────────────────────────────
        ['en' => 'Managers Telegram group', 'ar' => 'مجموعة تيليغرام المدراء', 'category' => 'telegram',
            'levels' => $managers, 'titles' => $executives],
        ['en' => 'managers@ mail list', 'ar' => 'قائمة البريد managers@', 'category' => 'mail_list', 'list' => 'managers@hcsyria.org',
            'levels' => $managers, 'titles' => $executives],
        ['en' => "Center's cameras", 'ar' => 'كاميرات المركز', 'category' => 'camera',
            'description' => 'Viewing the center\'s cameras (recordings and live).',
            'levels' => $managers, 'titles' => $executives],
        ['en' => 'headoffice@ mail list', 'ar' => 'قائمة البريد headoffice@', 'category' => 'mail_list', 'list' => 'headoffice@hcsyria.org',
            'centers' => ['Head Office']],
        ['en' => 'Bank accounts: authorised signatory', 'ar' => 'الحسابات المصرفية: مفوّض بالتوقيع', 'category' => 'other',
            'titles' => ['^Chief Executive Officer', 'Deputy Chief Executive Officer', 'Senior Financial Manager']],
        ['en' => 'Project budget follow-up sheets', 'ar' => 'جداول متابعة موازنات المشاريع', 'category' => 'shared_drive',
            'titles' => [...$projectLead, ...$finManagers, ...$executives]],
        ['en' => 'Work laptop', 'ar' => 'حاسوب محمول للعمل', 'category' => 'equipment',
            'departments' => $office, 'levels' => $managers, 'titles' => $executives],

        // ── Information Technology ───────────────────────────────────
        ['en' => 'HR system database', 'ar' => 'قاعدة بيانات نظام الموارد البشرية', 'category' => 'database',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'IT team Telegram group', 'ar' => 'مجموعة تيليغرام فريق IT', 'category' => 'telegram',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'it@ mail list', 'ar' => 'قائمة البريد it@', 'category' => 'mail_list', 'list' => 'it@hcsyria.org',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'IT maintenance: printers and ink', 'ar' => 'صيانة IT: الطابعات والأحبار', 'category' => 'it_maintenance',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'Mail and domain admin console (hcsyria.org)', 'ar' => 'لوحة إدارة البريد والنطاق (hcsyria.org)', 'category' => 'other',
            'description' => 'Creating and closing mailboxes, the mail lists\' members.',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'Network, Wi-Fi and router admin', 'ar' => 'إدارة الشبكة والواي فاي والراوترات', 'category' => 'it_maintenance',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'Attendance devices admin (ZKTeco)', 'ar' => 'إدارة أجهزة البصمة (ZKTeco)', 'category' => 'it_maintenance',
            'departments' => ['IT'], 'titles' => ['^IT ']],
        ['en' => 'Servers and backups', 'ar' => 'الخوادم والنسخ الاحتياطية', 'category' => 'database',
            'titles' => ['^IT Manager']],
        ['en' => 'Website admin (hcsyria.org)', 'ar' => 'إدارة الموقع الإلكتروني (hcsyria.org)', 'category' => 'social_media',
            'titles' => ['^IT Manager', '^Communication Manager']],

        // ── Finance ──────────────────────────────────────────────────
        ['en' => 'Accounting system', 'ar' => 'نظام المحاسبة', 'category' => 'database',
            'departments' => ['Fin'], 'titles' => ['Deputy Chief Executive Officer']],
        ['en' => 'financial@ mail list', 'ar' => 'قائمة البريد financial@', 'category' => 'mail_list', 'list' => 'financial@hcsyria.org',
            'departments' => ['Fin']],
        ['en' => 'Finance team Telegram group', 'ar' => 'مجموعة تيليغرام الفريق المالي', 'category' => 'telegram',
            'departments' => ['Fin']],
        ['en' => 'Finance shared drive', 'ar' => 'المجلد المشترك للمالية', 'category' => 'shared_drive',
            'departments' => ['Fin']],
        ['en' => 'Online banking (view)', 'ar' => 'الخدمات المصرفية الإلكترونية (عرض)', 'category' => 'other',
            'titles' => $finManagers],
        ['en' => 'Payroll sheets (confidential)', 'ar' => 'جداول الرواتب (سرّي)', 'category' => 'shared_drive',
            'titles' => $finManagers],
        ['en' => 'Cash box and safe', 'ar' => 'الصندوق والخزنة', 'category' => 'physical_access',
            'titles' => ['^Accountant', 'Senior Accountant', '^Administrator']],

        // ── Human Resources ──────────────────────────────────────────
        ['en' => 'HR team Telegram group', 'ar' => 'مجموعة تيليغرام فريق الموارد البشرية', 'category' => 'telegram',
            'departments' => ['HR']],
        ['en' => 'HR shared drive (personnel files)', 'ar' => 'مجلد الموارد البشرية المشترك (ملفات الموظفين)', 'category' => 'shared_drive',
            'departments' => ['HR']],
        ['en' => 'Personnel archive cabinet', 'ar' => 'خزانة أرشيف الموظفين', 'category' => 'physical_access',
            'departments' => ['HR']],
        ['en' => 'Job boards accounts', 'ar' => 'حسابات مواقع التوظيف', 'category' => 'other',
            'description' => 'Posting vacancies on the job sites and pages the organisation uses.',
            'departments' => ['HR']],

        // ── MEAL ─────────────────────────────────────────────────────
        ['en' => 'meal@ mail list', 'ar' => 'قائمة البريد meal@', 'category' => 'mail_list', 'list' => 'meal@hcsyria.org',
            'departments' => ['MEAL']],
        ['en' => 'MEAL team Telegram group', 'ar' => 'مجموعة تيليغرام فريق المتابعة والتقييم', 'category' => 'telegram',
            'departments' => ['MEAL']],
        ['en' => 'Data collection platform (KoboToolbox)', 'ar' => 'منصة جمع البيانات (KoboToolbox)', 'category' => 'database',
            'departments' => ['MEAL']],
        ['en' => 'Beneficiaries database', 'ar' => 'قاعدة بيانات المستفيدين', 'category' => 'database',
            'departments' => ['MEAL', 'PSS'], 'titles' => [...$projectLead, 'Field Officer', 'Project Officer', 'Distribution Coordinator']],
        ['en' => 'MEAL shared drive (indicators and reports)', 'ar' => 'مجلد المتابعة والتقييم (المؤشرات والتقارير)', 'category' => 'shared_drive',
            'departments' => ['MEAL'], 'titles' => ['^Program Manager', '^Relief Program Manager', 'Projects and Fundraising Manager']],

        // ── Communication ────────────────────────────────────────────
        ['en' => 'Communication team Telegram group', 'ar' => 'مجموعة تيليغرام فريق التواصل', 'category' => 'telegram',
            'departments' => ['Comm']],
        ['en' => 'design@ mail list', 'ar' => 'قائمة البريد design@', 'category' => 'mail_list', 'list' => 'design@hcsyria.org',
            'titles' => ['Designer', '^Communication Manager', 'Associate Communication Manager']],
        ['en' => 'Social media pages (Facebook, Instagram)', 'ar' => 'صفحات التواصل الاجتماعي (فيسبوك، إنستغرام)', 'category' => 'social_media',
            'departments' => ['Comm']],
        ['en' => 'LinkedIn page admin', 'ar' => 'إدارة صفحة لينكدإن', 'category' => 'social_media',
            'titles' => ['linkedIn', '^Communication Manager']],
        ['en' => 'Media archive drive (photos and videos)', 'ar' => 'مجلد أرشيف الإعلام (صور وفيديو)', 'category' => 'shared_drive',
            'departments' => ['Comm'], 'titles' => ['Media Teacher']],
        ['en' => 'Design software licences', 'ar' => 'تراخيص برامج التصميم', 'category' => 'other',
            'titles' => ['Designer', 'Videographer']],
        ['en' => 'Camera and video equipment', 'ar' => 'معدات التصوير والفيديو', 'category' => 'equipment',
            'titles' => ['Photographer', 'Videographer', 'Media Teacher']],

        // ── Fundraising and public relations ─────────────────────────
        ['en' => 'pr@ mail list', 'ar' => 'قائمة البريد pr@', 'category' => 'mail_list', 'list' => 'pr@hcsyria.org',
            'departments' => ['Fund'], 'titles' => ['^Communication Manager']],
        ['en' => 'Donors and partners database', 'ar' => 'قاعدة بيانات المانحين والشركاء', 'category' => 'database',
            'departments' => ['Fund'], 'titles' => $executives],
        ['en' => 'Proposals and donor reports drive', 'ar' => 'مجلد المقترحات وتقارير المانحين', 'category' => 'shared_drive',
            'departments' => ['Fund'], 'titles' => ['^Program Manager', '^Relief Program Manager', ...$executives]],

        // ── Logistics ────────────────────────────────────────────────
        ['en' => 'logistics@ mail list', 'ar' => 'قائمة البريد logistics@', 'category' => 'mail_list', 'list' => 'logistics@hcsyria.org',
            'departments' => ['Logi']],
        ['en' => 'Logistics team Telegram group', 'ar' => 'مجموعة تيليغرام فريق اللوجستيات', 'category' => 'telegram',
            'departments' => ['Logi']],
        ['en' => 'Warehouse keys and stock records', 'ar' => 'مفاتيح المستودع وسجلات المخزون', 'category' => 'physical_access',
            'departments' => ['Logi'], 'titles' => ['Distribution Coordinator']],
        ['en' => 'Procurement and suppliers files', 'ar' => 'ملفات المشتريات والموردين', 'category' => 'shared_drive',
            'departments' => ['Logi'], 'titles' => $finManagers],

        // ── Legal affairs ────────────────────────────────────────────
        ['en' => 'Legal archive (contracts and registrations)', 'ar' => 'الأرشيف القانوني (العقود والتراخيص)', 'category' => 'shared_drive',
            'departments' => ['LegA'], 'titles' => $executives],

        // ── Psychological and spiritual support ──────────────────────
        ['en' => 'Case management records (confidential)', 'ar' => 'سجلات إدارة الحالات (سرّي)', 'category' => 'database',
            'departments' => ['PSS']],
        ['en' => 'PSS supervision Telegram group', 'ar' => 'مجموعة تيليغرام الإشراف للدعم النفسي', 'category' => 'telegram',
            'departments' => ['PSS']],

        // ── Teaching and coaching ────────────────────────────────────
        ['en' => 'Teachers Telegram group', 'ar' => 'مجموعة تيليغرام المدرّسين', 'category' => 'telegram',
            'departments' => ['Tchr', 'Comp']],
        ['en' => 'Training materials drive', 'ar' => 'مجلد المواد التدريبية', 'category' => 'shared_drive',
            'departments' => ['Tchr', 'Comp'], 'titles' => ['Activity Facilitator']],
        ['en' => 'Classrooms and labs', 'ar' => 'القاعات والمختبرات', 'category' => 'physical_access',
            'departments' => ['Tchr']],
        ['en' => 'Projector and session laptop', 'ar' => 'جهاز عرض وحاسوب للجلسات', 'category' => 'equipment',
            'departments' => ['Tchr'], 'titles' => ['Activity Facilitator']],
        ['en' => 'ICDL testing platform', 'ar' => 'منصة امتحانات ICDL', 'category' => 'other',
            'titles' => ['ICDL Trainer']],
        ['en' => 'Robotics kits', 'ar' => 'عدّة الروبوتيك', 'category' => 'equipment',
            'titles' => ['Robotics Trainer']],

        // ── Front desk and field ─────────────────────────────────────
        ['en' => 'Visitors and beneficiaries register', 'ar' => 'سجل الزوار والمستفيدين', 'category' => 'database',
            'departments' => ['FDesk']],
        ['en' => 'Center phone line', 'ar' => 'خط هاتف المركز', 'category' => 'equipment',
            'titles' => ['^Administrator']],

        // ── Center keepers ───────────────────────────────────────────
        ['en' => 'Center keys and alarm code', 'ar' => 'مفاتيح المركز ورمز الإنذار', 'category' => 'physical_access',
            'departments' => ['Kprs'], 'titles' => ['^Administrator', '^Project Manager']],
        ['en' => 'Cleaning and supplies store', 'ar' => 'مستودع مواد التنظيف واللوازم', 'category' => 'physical_access',
            'titles' => ['Center keeper']],

        // ── Programs ─────────────────────────────────────────────────
        ['en' => 'micro.projects@ mail list', 'ar' => 'قائمة البريد micro.projects@', 'category' => 'mail_list', 'list' => 'micro.projects@hcsyria.org',
            'programs' => ['Micro Project', 'Entrepreneurship project'], 'except_departments' => ['Kprs']],
        ['en' => 'micro.aleppo@ mail list', 'ar' => 'قائمة البريد micro.aleppo@', 'category' => 'mail_list', 'list' => 'micro.aleppo@hcsyria.org',
            'programs' => ['Micro Project'], 'city' => 'ALP', 'except_departments' => ['Kprs']],
        ['en' => 'micro.homs@ mail list', 'ar' => 'قائمة البريد micro.homs@', 'category' => 'mail_list', 'list' => 'micro.homs@hcsyria.org',
            'programs' => ['Micro Project'], 'city' => 'HMS', 'except_departments' => ['Kprs']],
        ['en' => 'micro.damascus@ mail list', 'ar' => 'قائمة البريد micro.damascus@', 'category' => 'mail_list', 'list' => 'micro.damascus@hcsyria.org',
            'programs' => ['Micro Project'], 'city' => 'DMS', 'except_departments' => ['Kprs']],
        ['en' => 'micro.latakia@ mail list', 'ar' => 'قائمة البريد micro.latakia@', 'category' => 'mail_list', 'list' => 'micro.latakia@hcsyria.org',
            'programs' => ['Micro Project'], 'city' => 'LAT', 'except_departments' => ['Kprs']],
        ['en' => 'disc.syria@ mail list', 'ar' => 'قائمة البريد disc.syria@', 'category' => 'mail_list', 'list' => 'disc.syria@hcsyria.org',
            'programs' => ['Development Impact and Support Center'], 'except_departments' => ['Kprs']],
        ['en' => 'studyzone.syria@ mail list', 'ar' => 'قائمة البريد studyzone.syria@', 'category' => 'mail_list', 'list' => 'studyzone.syria@hcsyria.org',
            'programs' => ['Study Zone'], 'except_departments' => ['Kprs']],
        ['en' => 'studyzone.managers@ mail list', 'ar' => 'قائمة البريد studyzone.managers@', 'category' => 'mail_list', 'list' => 'studyzone.managers@hcsyria.org',
            'programs' => ['Study Zone'], 'only_titles' => $projectLead],
        ['en' => 'relief.aleppo@ mail list', 'ar' => 'قائمة البريد relief.aleppo@', 'category' => 'mail_list', 'list' => 'relief.aleppo@hcsyria.org',
            'programs' => ['Relief Programs', 'Earthquake Assistance'], 'city' => 'ALP', 'except_departments' => ['Kprs']],
    ],

    // One staff Telegram group per city that has positions.
    'city_groups' => [
        'en' => 'Staff Telegram group: :city',
        'ar' => 'مجموعة تيليغرام الموظفين: :city',
    ],
];
