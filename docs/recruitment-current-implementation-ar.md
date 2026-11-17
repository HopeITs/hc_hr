> **تحديث 18 أيلول 2026 — D52–D55:** Fill Vacancy يحتاج شاغراً متاحاً قائماً؛ اختيار تلقائي للواحد واختيار صريح عند التعدد. أب CreatePosition إداري أو لديه فريق نشط ضمن الشجرة. مدير البرنامج/المشروع مقيّد ببرنامج منصبه وعلاقاته الجغرافية ومراكزه، ولا ينشئ Department Manager. هذه القيود تخصص وصف D49/D51 السابق؛ نطاق الرؤية ومنع HR من Normal Request باقيان. راجع سجل القرارات.


<div dir="rtl" align="right">

<style>
[dir="rtl"] :where(code, pre) {
    direction: ltr;
    text-align: left;
    unicode-bidi: isolate;
}

[dir="rtl"] pre code {
    display: block;
    direction: ltr;
    text-align: left;
}
</style>

# دليل الحالة الحالية لوحدة التوظيف واستخدام الملفات المنفذة

> تحديث 22 أيلول 2026: أُضيفت دورة حياة الحملات وواجهة إدارتها، وأصبح الرابط العام يحفظ المتقدم والتقديم والإجابات ويرفع الملفات إلى التخزين الخاص الآمن فعلياً. لم تعد واجهة التقديم مجرد فحص أولي. ما زالت مراحل مراجعة المتقدمين والـATS والمقابلات والعروض والتحويل إلى موظف غير منفذة تشغيلياً.

> تحديث الصلاحيات والإنشاء: 2026-09-17 — D49/D50 يحلان محل الوصف السابق قبل الدمج. HR للفتح المباشر فقط، والمدير المؤهل غير HR ينشئ طلباً عادياً ضمن شجرته بكل الأعماق. حالة الواجهات الحالية في [07-request-screens-progress.md](recruitment/07-request-screens-progress.md). وصف عدم وجود الواجهات أدناه يخص تسليم 2026-09-16 التاريخي.

## الحالة الحالية بالتحديد:

| الجزء | الحالة الحالية |
|---|---|
| قاعدة بيانات المرحلة الأولى | منفذة عبر migrations |
| قاعدة بيانات المرحلة الثانية | منفذة عبر migrations |
| Models المرحلة الأولى | مكتملة لكل جداول المرحلة الأولى |
| الصلاحيات والسياسات ونطاق الوصول للمرحلة الأولى | منفذة ومختبرة كأساس أمني |
| خدمات الطلب والإرسال وبدء سير الموافقات | منفذة ومختبرة؛ إجراءات قرار الموافق ما زالت لاحقة |
| واجهات Phase 1 وLivewire وRoutes والقوائم | منفذة للطلبات والموافقات المعلقة والحملات وأدوات النشر |
| Models المرحلة الثانية | مكتملة لكل جداول المرحلة الثانية |
| استقبال المتقدمين العام ومنع التكرار والدعوات | منفذ ومختبر |
| رفع CV والملفات الخاصة وفحصها وتحميلها المصرح | منفذ؛ الملفات تدخل quarantine حتى نتيجة الفحص |
| الـATS والمقابلات والعروض والتحويل إلى موظف | غير منفذ تشغيلياً بعد |

إذن: **مخطط قاعدة البيانات وطبقة Models يغطيان المرحلتين. المسار التشغيلي يعمل من إنشاء الطلب حتى تجهيز الحملة والنشر واستقبال طلب المتقدم وملفاته، بينما قرارات الموافقة اللاحقة ومراجعة المتقدمين والـATS وما بعدها ما زالت تحتاج التنفيذ.**

## ما الذي أصبح جاهزاً فعلياً؟

أصبح لدينا الأساس التالي:

1. جميع جداول قاعدة البيانات المطلوبة للمرحلتين الأولى والثانية.
2. المفاتيح الخارجية والفهارس والقيود الأساسية بين جداول التوظيف والجداول الحالية في نظام HR.
3. Model لكل جدول من جداول Recruitment وعددها 33 جدولاً، مع العلاقات والـcasts اللازمة للمرحلتين.
4. صلاحيات Recruitment مدمجة مع نظام Spatie Permission الموجود.
5. Policies تمنع الوصول حسب الصلاحية والنطاق التنظيمي معاً.
6. خدمة مركزية لتحديد نطاق المدينة أو البرنامج أو القسم أو المركز أو المنصب.
7. اختبارات آلية للصلاحيات والنطاق وتغطية كل Models والجداول والعلاقات والـcasts والقيم الحساسة.
8. إزالة كل ما يتعلق بحالة حظر طلب التوظيف؛ لا يوجد `blocked_reason` ولا مسار تشغيلي خاص بـ block request.
9. دورة حياة حملة التوظيف مع الإنشاء والمواعيد والفتح والإغلاق والتعطيل والتحديث المجدول للحالة.
10. مولد إعلان الوظيفة، وباني نموذج التقديم ذي النسخ، والرابط العام والدعوات الداخلية.
11. استقبال عام فعلي يحفظ Applicant وApplication والإجابات حسب Form Version، مع idempotency ومنع التكرار وقفل الحملة داخل transaction.
12. رفع CV والوثائق إلى quarantine خاص بأسماء مولدة، وعدم إتاحتها للتنزيل قبل تعليمها كملفات نظيفة.

## ملفات قاعدة البيانات

### `database/migrations/2026_09_15_000001_create_recruitment_request_workflow_tables.php`

ينشئ أساس طلبات التوظيف وسير الموافقات في المرحلة الأولى:

- `recruitment_requests`: الحاوية الرئيسية للطلب، وتشمل النوع والوضع والحالة والمدينة ومعلومات الفتح المباشر من HR.
- `recruitment_request_versions`: نسخة ثابتة من بيانات الطلب عند كل إرسال أو إعادة إرسال.
- `recruitment_request_position_details`: تفاصيل المنصب والعدد والراتب وساعات العمل والمؤهلات وغيرها.
- `recruitment_request_comments`: تعليقات الطلب.
- `recruitment_request_external_confirmations`: توثيق تأكيدات المسار المباشر `direct_hr`.
- `recruitment_workflow_definitions`: تعريفات مسارات الموافقة.
- `recruitment_workflow_definition_versions`: إصدارات تعريفات المسارات.
- `recruitment_workflow_steps`: خطوات كل إصدار، مع approver واحد لكل خطوة.
- `recruitment_workflow_instances`: نسخة سير العمل الفعلية المرتبطة بطلب ونسخة طلب.
- `recruitment_workflow_instance_steps`: الخطوات الفعلية والمستخدم المعين والقرار.
- `recruitment_request_events`: الخط الزمني والتدقيق المخصص لطلب التوظيف بجانب `activity_log` العام.

ملاحظات مهمة:

- `mode` يدعم الوضع العادي و`direct_hr`.
- لا يوجد `blocked_reason`.
- لا يوجد `approver_slot`.
- حقول `currency` و`salary_per` موجودة مع بيانات الراتب.
- لا يوجد `funding_source`.

### `database/migrations/2026_09_15_000002_create_recruitment_vacancy_publishing_tables.php`

ينشئ بقية المرحلة الأولى بعد اعتماد الطلب:

- `vacancies`: دورة حياة الشاغر بشكل مستقل عن سعة `positions`.
- `hiring_campaigns`: حملة التوظيف المرتبطة بالشاغر.
- `job_posts` و`job_post_versions`: إعلان الوظيفة وإصداراته المنشورة.
- `application_forms` و`application_form_versions`: نموذج التقديم وإصداراته.
- `public_recruitment_links`: الروابط العامة الآمنة باستخدام token hash.
- `recruitment_campaign_invites`: الدعوات الخاصة للتوظيف الداخلي أو invitation-only.

### `database/migrations/2026_09_15_000003_add_recruitment_phase_one_late_foreign_keys.php`

يضيف المفاتيح الخارجية التي لا يمكن إضافتها أثناء إنشاء الجداول بسبب الاعتماد الدائري، ومنها الربط بين الطلب والشاغر الحالي، وبين المنشور أو النموذج وإصداره الحالي.

### `database/migrations/2026_09_15_000004_create_recruitment_applicant_processing_tables.php`

ينشئ الجزء الأول من المرحلة الثانية:

- `recruitment_applicants`: هوية المتقدم الأساسية وسياسة الاحتفاظ بالبيانات.
- `recruitment_applications`: طلب التقديم المرتبط بالحملة والشاغر ونسخة النموذج والرابط أو الدعوة.
- `recruitment_application_answers`: إجابات النموذج.
- `recruitment_applicant_files`: سجل ملفات المتقدم وحالة الفحص الأمني؛ ربط الملف الفعلي مع MediaLibrary يتم في طبقة Model عند تنفيذها.
- `recruitment_tags` و`recruitment_application_tags`: وسوم ATS وربطها بالطلبات.
- `recruitment_applicant_notes`: ملاحظات فريق التوظيف.
- `recruitment_application_decisions`: سجل قرارات الفرز والاختيار.

تم استخدام `dateTime('submitted_at')` بدلاً من `timestamp` الإلزامي لتفادي خطأ MySQL: `Invalid default value for 'submitted_at'`.

### `database/migrations/2026_09_15_000005_create_recruitment_interview_offer_tables.php`

ينشئ الجزء الثاني من المرحلة الثانية:

- `recruitment_interviews`: المقابلات المجدولة.
- `recruitment_interview_participants`: أعضاء لجنة المقابلة.
- `recruitment_interview_evaluations`: تقييمات المقابلات.
- `recruitment_job_offers`: العرض الوظيفي وحالته.
- `recruitment_job_offer_versions`: إصدارات العرض وشروط الراتب والعقد.
- `recruitment_hiring_conversions`: عملية تحويل العرض المقبول إلى `employee` و`contract` في نظام HR الحالي.

### `database/migrations/2026_09_15_000006_add_recruitment_offer_late_foreign_key.php`

يضيف الربط المتأخر بين العرض الوظيفي والإصدار الحالي للعرض، لتجنب الاعتماد الدائري أثناء إنشاء الجدولين.

### `database/migrations/2026_09_16_000001_seed_recruitment_permissions.php`

ينشئ صلاحيات `recruitment.*` داخل قاعدة البيانات ويمنحها إلى:

- أدوار الإدارة العليا المعرفة في `administrator_roles`.
- جميع أدوار HR الإقليمية من الشكل `HR-*`.
- بقية الأدوار حسب baseline المعرفة في `config/access-control.php`.

هذا migration يمسح cache الخاص بصلاحيات Spatie قبل التحديث وبعده.

### `database/migrations/2026_09_16_000002_restrict_recruitment_request_creation.php`

ترحيل تصحيحي للبيئات التي شغّلت ترحيل الصلاحيات السابق: يسحب صلاحية
`recruitment.requests.create` من دوري `employee` و`manager` الموجودين مسبقاً،
مع الإبقاء على إمكانية التراجع في `down()` وإعادة الصلاحية لهذين الدورين.
الحماية الفعلية موجودة أيضاً في Policy، لذلك لا يستطيع أي دور غير مسموح له
تجاوز القاعدة عبر منح الصلاحية يدوياً.

## ملفات Models المنفذة حالياً

يوجد الآن Model لكل جدول من جداول Recruitment وعددها 33. تستخدم Models العادية `$guarded = ['id']`، بينما جدول ربط الوسوم يستخدم Pivot Model مخصصاً.

### Models طلب التوظيف وسير الموافقات

- `app/Models/RecruitmentRequest.php`: الطلب الرئيسي مع مقدم الطلب والمدينة والمناصب والشاغر المستهدف والنسخ والتعليقات والتأكيدات وسير العمل والأحداث. يوفر `visibleTo($user)`.
- `app/Models/RecruitmentRequestVersion.php`: نسخة الطلب وتفاصيلها وتعليقاتها وسير العمل والأحداث المرتبطة بها.
- `app/Models/RecruitmentRequestPositionDetail.php`: تفاصيل المنصب والبرنامج والمركز والقسم والمستوى ونوع العقد والمشرف المباشر.
- `app/Models/RecruitmentRequestComment.php`: تعليق الطلب أو نسخة محددة منه، ولا يستخدم `updated_at` لأن التعليق سجل تاريخي.
- `app/Models/RecruitmentRequestExternalConfirmation.php`: توثيق التأكيد الخارجي لمسار `direct_hr` ومن قام بتسجيله.
- `app/Models/RecruitmentWorkflowDefinition.php`: تعريف سير الموافقات وإصداراته وأحدث إصدار.
- `app/Models/RecruitmentWorkflowDefinitionVersion.php`: إصدار التعريف وقواعده وخطواته والنسخ التشغيلية التي استخدمته.
- `app/Models/RecruitmentWorkflowStep.php`: خطوة تعريفية مرتبة وعلاقتها بالخطوات التشغيلية والأحداث.
- `app/Models/RecruitmentWorkflowInstance.php`: سير العمل الفعلي لنسخة طلب معينة مع تعريفه وخطواته وأحداثه.
- `app/Models/RecruitmentWorkflowInstanceStep.php`: الخطوة الفعلية والمستخدم المعين ومن قام بالقرار.
- `app/Models/RecruitmentRequestEvent.php`: أحداث الطلب وروابطها بالنسخة وسير العمل والخطوة والمنفذ، ويستخدم `visibleTo($user)`.

### Models الشاغر والنشر

- `app/Models/Vacancy.php`: الشاغر المرتبط بطلب معتمد والمنصب والحملات والتقديمات والعروض، ويستخدم `visibleTo($user)`.
- `app/Models/HiringCampaign.php`: الحملة وربطها بالشاغر والمنشئ ومن أغلقها والإعلان والنموذج والروابط والدعوات والتقديمات.
- `app/Models/JobPost.php`: إعلان الوظيفة وإصداراته والإصدار الحالي والمنشئ، ويستخدم `visibleTo($user)`.
- `app/Models/JobPostVersion.php`: نسخة محتوى الإعلان بالعربية والإنجليزية وبيانات النشر العامة.
- `app/Models/ApplicationForm.php`: نموذج التقديم وإصداراته والإصدار الحالي والمنشئ، ويستخدم `visibleTo($user)`.
- `app/Models/ApplicationFormVersion.php`: حقول نسخة النموذج والطلبات والإجابات والروابط التي استخدمتها.
- `app/Models/PublicRecruitmentLink.php`: رابط التقديم ونسخ الإعلان والنموذج والمنشئ والتقديمات؛ يخفي `token_hash` عند serialization ويستخدم `visibleTo($user)`.
- `app/Models/RecruitmentCampaignInvite.php`: الدعوة الخاصة وحالتها ومنشئها والتقديم الذي استهلكها.

### Models المتقدمين والـATS

- `app/Models/RecruitmentApplicant.php`: المتقدم ومدينته وطلباته وملفاته وملاحظاته وعمليات تحويله، مع إخفاء `identity_key_hash`.
- `app/Models/RecruitmentApplication.php`: التقديم والحملة والشاغر ونسخة النموذج والرابط والدعوة والإجابات والملفات والوسوم والقرارات والمقابلات والعرض، مع إخفاء `submission_key_hash`.
- `app/Models/RecruitmentApplicationAnswer.php`: إجابة حقل مرتبطة بالتقديم ونسخة النموذج، مع cast JSON.
- `app/Models/RecruitmentApplicantFile.php`: سجل ملف المتقدم، وينفذ `HasMedia` ويستخدم مجموعة `document` مفردة على القرص الخاص `local`.
- `app/Models/RecruitmentTag.php`: وسم ATS ونطاقه ومنشئه والتقديمات المرتبطة به.
- `app/Models/RecruitmentApplicationTag.php`: Pivot Model لربط التقديم بالوسم مع المستخدم الذي أضافه.
- `app/Models/RecruitmentApplicantNote.php`: ملاحظة على المتقدم أو تقديم محدد، كسجل تاريخي بلا `updated_at`.
- `app/Models/RecruitmentApplicationDecision.php`: انتقال حالة التقديم وسببه وصاحب القرار، كسجل تاريخي بلا `updated_at`.

### Models المقابلات والعروض والتحويل

- `app/Models/RecruitmentInterview.php`: المقابلة والتقديم والمنشئ والمشاركون والتقييمات.
- `app/Models/RecruitmentInterviewParticipant.php`: عضو لجنة المقابلة ودوره وحالة إشعاره.
- `app/Models/RecruitmentInterviewEvaluation.php`: تقييم عضو اللجنة وتوصيته وتاريخ الإرسال.
- `app/Models/RecruitmentJobOffer.php`: العرض والتقديم والشاغر والمنصب والإصدارات والإصدار الحالي والمنشئ وعملية التحويل.
- `app/Models/RecruitmentJobOfferVersion.php`: نسخة شروط العرض والراتب ونوع العقد والتواريخ والساعات والشروط الإضافية.
- `app/Models/RecruitmentHiringConversion.php`: عملية التحويل إلى `Employee` و`Contract` وربطها بالعرض ونسخته والمتقدم والمنفذ.

اكتمال Models لا يعني أن Phase 2 مكتملة؛ أصبح استقبال التقديم والملفات قابلاً للاستخدام، لكن بقية عمليات المراجعة والـATS والمقابلات والعروض والتحويل ما زالت تحتاج خدمات وواجهات فوق هذه العلاقات.

## خدمات وواجهات الحملات واستقبال المتقدمين

- `app/Services/HiringCampaignService.php`: ينشئ الحملة من شاغر متاح، ويمنع أكثر من حملة فعالة للشاغر، ويدير المواعيد والفتح والإغلاق والتعطيل والتحديث المجدول.
- `app/Console/Commands/SyncRecruitmentCampaignStates.php`: يفتح الحملات الجاهزة وينهي الحملات التي تجاوزت موعدها، ويعمل كل دقيقة من `routes/console.php`.
- `app/Livewire/Recruitment/Campaigns/Index.php`: قائمة الحملات ضمن النطاق، وإنشاء الحملة وتعديل مواعيدها وفتحها وإغلاقها وتعطيلها.
- `resources/views/livewire/recruitment/campaigns/index.blade.php`: واجهة إدارة الحملات ثنائية اللغة، ومنها الانتقال إلى مركز النشر.
- `app/Services/PublicRecruitmentApplicationService.php`: حد الأمان الفعلي لإرسال الطلب العام؛ يستنتج الحملة ونسخة النموذج من الرابط، ويقفل الحملة، ويمنع الحقول الداخلية والتكرار، ويتحقق من الدعوة، ويحفظ المتقدم والتقديم والإجابات والملفات.
- `app/Enums/RecruitmentApplicationStatus.php`: الحالات المضبوطة لتقديم المتقدم من `submitted` حتى `hired` أو `withdrawn` أو `archived`.
- `app/Services/RecruitmentApplicantFileService.php`: يفحص محتوى الملف وامتداده وحجمه، ثم يحفظه باسم مولد على قرص quarantine خاص، ويدعم نقله إلى التخزين النظيف بعد نتيجة الفحص.
- `app/Http/Controllers/PublicCareerController.php`: يعرض النموذج العام ويستقبل الإرسال الحقيقي ويرجع رسائل عامة لا تكشف سبب الرفض الداخلي.
- `resources/views/recruitment/careers/show.blade.php`: نموذج wizard عام مع تحقق لكل خطوة ورفع ملفات فعلي.
- `resources/views/recruitment/careers/submitted.blade.php`: صفحة نجاح آمنة بعد حفظ الطلب.
- `resources/views/recruitment/careers/submission-error.blade.php`: رد موحد عند تعذر قبول التقديم دون كشف صلاحية token أو حالة داخلية.

## النطاق والصلاحيات

### `app/Enums/RecruitmentScopeType.php`

يوحد أنواع النطاق المقبولة:

- المنظمة.
- المدينة أو المحافظة.
- البرنامج أو المشروع.
- القسم.
- المركز.
- المنصب.
- المستخدم.

كما يحول الأسماء البديلة مثل `area` و`region` و`project` إلى النوع الموحد المناسب.

### `app/Services/RecruitmentScopeResolver.php`

هو المصدر المركزي لتحديد ما يستطيع المستخدم رؤيته أو التعامل معه. يستخدم:

- الدور النشط من `User::myRole()`.
- المدينة النشطة من `User::activeCityId()` لأدوار `HR-*`.
- الموظف والعقود الفعالة والمناصب الحالية للمديرين والمالية.
- المدينة والبرنامج والقسم والمركز والمنصب المسجلين على الطلب.
- المستخدم المعين فعلياً على خطوة الموافقة.

القواعد الأمنية الأساسية:

1. الصلاحية وحدها لا تعطي وصولاً لكل السجلات.
2. امتلاك الطلب يسمح برؤية الطلب الشخصي لكنه لا يعطي حق الموافقة.
3. قرار الموافقة أو الرفض يتطلب أن يكون المستخدم هو assignee الحالي، وأن يطابق دوره ونطاقه المخزنين.
4. النطاق المفقود أو غير المعروف يفشل بشكل آمن ويرفض الإجراء.
5. HQ والأدوار التنظيمية العامة يمكن أن تحصل على نطاق كامل حسب الإعدادات.

### `config/recruitment.php`

يحدد:

- الأدوار ذات النطاق الكامل على مستوى المنظمة.
- الدرجات المؤهلة 1 و3 و5. المسار العادي للمدراء غير HR حسب Policy وD49/D50، والفتح المباشر منفصل.
- خريطة إسناد المحافظات إلى HR وصلاحيتي الأشخاص للموافقة المالية وتجاوز المدير عند قمة الهيكل.

يجب تحديث هذا الملف بحذر عند تغيير أسماء الأدوار الفعلية في النظام.

## Policies المنفذة

- `RecruitmentRequestPolicy`: عرض وإنشاء وتعديل وإرسال ومراجعة وتصدير الطلب.
- `RecruitmentWorkflowInstanceStepPolicy`: عرض خطوة الموافقة وتنفيذ approve أو reject أو request changes.
- `VacancyPolicy`: عرض وإدارة وإغلاق الشاغر.
- `HiringCampaignPolicy`: عرض وإدارة ونشر وإغلاق الحملة.
- `JobPostPolicy`: عرض وإدارة ونشر إعلان الوظيفة.
- `ApplicationFormPolicy`: عرض وإدارة ونشر نموذج التقديم.
- `PublicRecruitmentLinkPolicy`: إنشاء وإدارة روابط التقديم.
- `RecruitmentRequestEventPolicy`: عرض وتصدير سجل أحداث الطلب.

تم تسجيل هذه Policies في `app/Providers/AppServiceProvider.php` عبر `Gate::policy(...)`، وبذلك تستخدم تلقائياً مع `Gate::authorize()` وmiddleware من نوع `can`.

## ملفات نظام الصلاحيات التي تم تعديلها

### `app/Support/AccessPermissionCatalog.php`

تم توسيعه ليدعم display key بسيطاً مع prefix يحتوي نقاطاً، مثل:

<div dir="ltr" align="left">

```php
[
    'prefix' => 'recruitment.requests',
    'actions' => ['view', 'create', 'edit'],
]
```

</div>

هذا يحافظ على توافق شاشة إدارة الصلاحيات الحالية ويتيح أسماء مثل `recruitment.requests.view`.

### `config/access-control.php`

أضيفت:

- لا أدوار توظيف جديدة؛ أُلغيت الأدوار `hr-director` و`program-manager` و`project-manager` و`finance` أثناء الدمج.
- 21 صلاحية Recruitment؛ أسماء من مقطعين مثل `recruitment-requests.manage` وفق D4، مع صلاحيتي أشخاص لا تمنحان لأي دور.
- baselines للأدوار المختلفة.

إنشاء الطلب العادي محصور بالدور النشط `manager` أو `general-manager` مع درجة مؤهلة 1 أو 3 أو 5 ومنصب فعّال. **HR لا يستخدم المسار العادي إطلاقاً**؛ Direct Opening يبقى مستقلاً وفق D15. نطاق الإنشاء هو descendants بأي عمق للمنصب القائم، ومنصب المدير أو descendants للأب المقترح في CreatePosition. المحافظة والبرنامج لا يمنحان صلاحية إنشاء (`D49/D50`).

### `database/seeders/RolesPermissionsSeeder.php`

أصبح ينشئ أدوار النظام من `config/access-control.php` باستخدام `findOrCreate` بدلاً من إنشاء قائمة ثابتة قد تفشل عند وجود الدور مسبقاً.

### ملفات الترجمة

- `resources/lang/ar/access.php`
- `resources/lang/en/access.php`

أضيفت أسماء مجموعات Recruitment وأسماء الأفعال مثل التعديل والإرسال والموافقة والرفض وطلب التعديلات والنشر والإغلاق، لتظهر في شاشة إدارة الصلاحيات الحالية بالعربية والإنجليزية.

## ملفات التوثيق والاختبارات

### `docs/recruitment-authorization.md`

مرجع إنجليزي للمطور يشرح القاعدتين المطلوبتين دائماً:

1. فحص permission للدور النشط.
2. فحص policy والنطاق للسجل نفسه.

كما يقدم أمثلة لاستخدام `visibleTo()` و`Gate::authorize()`.

### `tests/Feature/Recruitment/RecruitmentAuthorizationTest.php`

يغطي حالياً:

- وجود جميع الصلاحيات والـbaselines.
- حصر HR الإقليمي ضمن مدينته.
- وصول HQ إلى النطاق التنظيمي الكامل.
- اشتقاق نطاق مدير البرنامج من العقد الفعال.
- منع الدور غير المعروف من رؤية طلبات الآخرين حتى لو حصل على permission مباشرة.
- اشتراط permission والدور النشط وassignee والنطاق عند الموافقة.
- منع HR والموظف والحساب التقني من إنشاء الطلب العادي، وقبول المدير المؤهل ضمن شجرته حسب D49/D50.
- الرفض الآمن عند نقص بيانات النطاق.
- انتقال نطاق الطلب إلى الشاغر والحملة والإعلان والنموذج والرابط وسجل الأحداث.

آخر نتيجة تشغيل:

<div dir="ltr" align="left">

```text
Tests: 9 passed (52 assertions)
```

</div>

### `tests/Feature/Recruitment/RecruitmentModelLayerTest.php`

يتحقق من:

- وجود Model مطابق لكل واحد من جداول Recruitment وعددها 33.
- تطابق اسم الجدول الذي يستخدمه كل Model.
- أنواع العلاقات والـModels المرتبطة في كامل graph.
- casts الخاصة بـJSON والتواريخ والعدادات.
- إخفاء hashes الحساسة عند serialization.
- دعم ملفات المتقدمين لـMediaLibrary.

آخر نتيجة تشغيل منفردة:

<div dir="ltr" align="left">

```text
Tests: 3 passed (198 assertions)
```

</div>

نتيجة تشغيل مجلد اختبارات Recruitment كاملاً بعد إكمال Models:

<div dir="ltr" align="left">

```text
Tests: 12 passed (250 assertions)
```

</div>

## كيفية استخدام الأساس الحالي أثناء التطوير

### تطبيق migrations

على قاعدة تطوير عادية:

<div dir="ltr" align="left">

```bash
php artisan migrate
```

</div>

إذا كانت migrations السابقة قد نجحت جزئياً وفشل migration الخاص بالتقديم بسبب
`submitted_at`، فإن تشغيل الأمر نفسه مرة أخرى سيكمل من migration غير المنفذ بعد
استخدام النوع المصحح. وسيطبق أيضاً migration تقييد إنشاء الطلبات على الأدوار
الموجودة مسبقاً.

لا تستخدم `migrate:fresh` على قاعدة مشتركة أو إنتاجية لأنه يحذف الجداول والبيانات.

### إنشاء أو تحديث الأدوار في بيئة جديدة

<div dir="ltr" align="left">

```bash
php artisan db:seed --class=RolesPermissionsSeeder
php artisan migrate
```

</div>

Migration الصلاحيات ينشئ صلاحيات Recruitment ويمنحها للأدوار المناسبة. وعند تعديل الصلاحيات يفضل مسح cache الخاص بها:

<div dir="ltr" align="left">

```bash
php artisan permission:cache-reset
```

</div>

### جلب السجلات في الصفحات والقوائم

يجب ألا تبدأ صفحة Recruitment باستعلام غير مقيد. الاستخدام الصحيح:

<div dir="ltr" align="left">

```php
$requests = RecruitmentRequest::query()
    ->visibleTo(auth()->user())
    ->latest()
    ->paginate();
```

</div>

ينطبق `visibleTo()` أيضاً على الشواغر والحملات والإعلانات والنماذج والروابط والأحداث.

### التحقق قبل أي تعديل أو قرار

<div dir="ltr" align="left">

```php
Gate::authorize('view', $recruitmentRequest);
Gate::authorize('update', $recruitmentRequest);
Gate::authorize('approve', $workflowInstanceStep);
```

</div>

عند حفظ طلب عادي جديد يجب التحقق من المنصب المستهدف أو الأب الإداري المقترح، وليس تمرير المحافظة أو الاكتفاء بفحص فتح الصفحة:

<div dir="ltr" align="left">

```php
Gate::authorize('create', RecruitmentRequest::class);
Gate::authorize('createForPosition', [RecruitmentRequest::class, $targetPosition]);
// CreatePosition بدلاً من createForPosition:
Gate::authorize('createUnderPosition', [RecruitmentRequest::class, $parentPosition]);
```

</div>

### ربط الواجهات المستقبلية بالنظام الحالي

عند إنشاء Livewire components أو routes لاحقاً يجب الالتزام بالآتي:

1. وضع routes داخل مجموعة locale الموجودة في المشروع.
2. حماية دخول الصفحة بـpermission middleware المناسب.
3. استخدام Policy لكل سجل وكل mutation، وعدم الاعتماد على إخفاء الزر في الواجهة.
4. استخدام `visibleTo()` في القوائم والعدادات والتصدير والبحث والـselectors.
5. استخدام الجداول الحالية مثل `users` و`employees` و`positions` و`contracts` وعدم إنشاء نسخ مكررة منها.
6. استخدام `Position::scopeInCity()` عند اختيار أو التحقق من منصب مرتبط بمدينة.
7. عند تنفيذ التحويل النهائي، إنشاء `Employee` و`Contract` من خلال خدمات النظام الحالية مع احترام `ContractObserver` وسعة المنصب.
8. حفظ ملفات المتقدمين لاحقاً باستخدام MediaLibrary وربطها بسجل ملف المتقدم، مع عدم تعديل الملف الأصلي المخزن.
9. تسجيل أحداث دورة طلب التوظيف في `recruitment_request_events`، واستخدام `activity_log` للنشاط العام عندما يلزم.

## ما الذي ما زال مطلوباً؟

### لإكمال Phase 1

- إجراءات approve وreject وrequest changes مع transactions وتسجيل الأحداث.
- إنشاء الشاغر فقط بعد اكتمال الموافقة والتحقق من `positions.max_emp` و`positions.exist_emp`.
- استكمال أي تكامل متبقٍ لمسار `direct_hr` والتأكيدات الخارجية مع قرارات الموافقة.
- تنبيهات عمليات الموافقة وأخطاء تعيين الموافق، ثم regression كامل لمسار الطلب حتى الشاغر.
- اختبارات تكامل كاملة للحالات والانتقالات والتزامن.

### لإكمال Phase 2

- خدمات ATS والفلترة والوسوم والفرز والقائمة المختصرة.
- قائمة المتقدمين والملف الشخصي والملاحظات ومؤشر المتقدم السابق.
- جدولة المقابلات والمشاركين ونماذج التقييم.
- إنشاء وإرسال وإصدار نسخ العرض الوظيفي وقبول أو رفض العرض.
- خدمة conversion آمنة وidempotent تنشئ الموظف والعقد داخل نظام HR الحالي.
- Policies وصلاحيات وواجهات Livewire للعرض الداخلي لبقية عمليات المرحلة الثانية.
- التنبيهات واختبارات end-to-end للمسار الكامل بعد الاستقبال.

## قرار الجاهزية الحالي

- **جاهزية تصميم قاعدة البيانات:** تغطي Phase 1 وPhase 2.
- **جاهزية تنفيذ migrations:** جاهزة للتجربة على قاعدة تطوير ونسخة احتياطية بعد نجاح الاختبارات الخاصة بالبيئة.
- **جاهزية Phase 1 للمستخدم النهائي:** أجزاء الطلب والنشر منفذة، لكن المرحلة لا تكتمل قبل إجراءات قرار الموافقات وإنشاء الشاغر النهائي واختبار المسار كاملاً.
- **جاهزية Phase 2 للمستخدم النهائي:** استقبال الطلب والملفات منفذ، لكن العمل الداخلي من مراجعة المتقدم حتى التوظيف غير جاهز بعد.
- **جاهزية الوحدة كاملة للإنتاج:** غير جاهزة بعد.

الخطوة التالية المنطقية هي إكمال إجراءات الموافقات وإنشاء الشاغر في Phase 1، ثم بناء قائمة المتقدمين والملف الشخصي والـATS فوق بيانات التقديم الحقيقية التي أصبحت محفوظة.


</div>
