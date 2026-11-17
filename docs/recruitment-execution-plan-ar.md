<div dir="rtl" align="right">

# خطة التنفيذ لمشروع التوظيف


هذه النسخة مبنية على تنفيذ المرحلتين بالتوازي خلال 10 أيام عمل.


الخطة تفترض وجود 4 مطورين يعملون بالتوازي: مطوران للمرحلة الأولى ومطوران للمرحلة الثانية. الهدف خلال هذه الفترة هو إنجاز نسخة MVP قوية وقابلة للتجريب، 

##  مهام المرحلة الأولى 
## المهمة 1 - حالات الطلب وأنواع الطلبات وقواعد Workflow

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يوم واحد  


### الهدف

تعريف حالات الطلب، الانتقالات المسموحة، قواعد أنواع الطلبات، وقواعد الموافقات قبل كتابة الكود.

### نطاق التنفيذ

- تعريف أنواع الطلبات.
- تعريف Request States.
- تعريف الأفعال المسموحة في كل حالة.
- تحديد متى يجب عمل Revalidation.
- تحديد تأثير كل Request Type بعد الموافقة.
- تحديد Workflow حسب نوع الطلب والسياق التنظيمي.

### المطلوب تنفيذه

- State Machine Document.
- Request Type Matrix.
- Approval Decision Matrix.
- Workflow Routing Rules.
- Revalidation Rules.

### قواعد العمل

أنواع الطلبات:

- Fill Vacancy.
- Increase Headcount.
- Decrease Headcount.
- Create Position.
- Close Position.
- Close Vacancy إذا تم اعتمادها كنوع طلب.

حالات الطلب:

- Draft.
- Submitted.
- Under Review.
- Changes Requested.
- Resubmitted.
- Approved.
- Rejected.
- Cancelled.
- Applied.
- Failed Application.

حالات Campaign:

- Draft.
- Published.
- Open.
- Closed.
- Expired.
- Disabled.

### الأمان والصلاحيات

- كل انتقال حالة يجب أن يتحقق من السيرفر.
- الموافق يجب أن يكون هو الموافق الحالي.
- يجب التحقق من Role وScope.
- لا يعتمد النظام على إخفاء الأزرار فقط.

### الحالات الاستثنائية

- ضغط موافقة مرتين.
- موافقان يحاولان الموافقة بنفس الوقت.
- الموافق يفقد صلاحياته.
- Workflow config يتغير أثناء وجود طلب active.
- Salary تغير بعد موافقة Finance.
- Vacancy تغيرت أثناء انتظار الموافقة.

### سلامة البيانات

- Approval مرتبط بـ Request Version.
- Workflow Instance مرتبط بـ Workflow Definition Version.
- لا يتم تعديل تاريخ Workflow قديم عند تغيير الإعدادات.
- الانتقالات غير المسموحة ترفض.

### ملاحظات تنفيذية

Workflow الافتراضي:

```text
Requester
    -> Program/Center Review عند الحاجة
    -> Finance Review عند وجود أثر مالي
    -> Province HR Review
    -> HR General / Director عند الطلبات الحساسة
    -> Approved
```

قواعد بديلة:

- إذا كان requester هو Program Manager، يتم تجاوز موافقة Program لتجنب self-approval.
- إذا كان Fill Vacancy ضمن budget معتمد، يمكن تجاوز Finance إذا تم اعتماد هذه السياسة.
- New Position وClose Position وSalary Exception وMajor Headcount Change تحتاج موافقة أعلى.

### معايير القبول

- كل Request Type له حقول وقواعد واضحة.
- كل حالة لها أفعال مسموحة.
- كل مخرجات الموافقة موثقة.
- Revalidation Rules واضحة.
- Business Team يمكنه مراجعة القواعد بدون قراءة الكود.

### أخطاء يجب تجنبها

- عدم استخدام `request.status = approved` بدون State Machine.
- عدم hard-code لسلسلة موافقات واحدة.
- عدم إبقاء موافقات قديمة صالحة بعد تعديل جوهري في الطلب.

### المخرجات والتسليم للمهمة التالية

State Machine وWorkflow Rules جاهزة للاستخدام في الخدمات والواجهة والصلاحيات.

## المهمة 2 - تصميم ERD وحدود الدومين

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يوم واحد  

### الهدف

تجهيز ERD النهائي للمرحلة الأولى وتحديد ما سيتم إعادة استخدامه من النظام الحالي وما سيتم إنشاؤه كجداول جديدة.

### نطاق التنفيذ

- مراجعة جداول HR الحالية.
- تحديد الجداول الحالية التي سيشير إليها موديول التوظيف.
- تحديد الجداول الجديدة المطلوبة للمرحلة الأولى.
- تحديد جداول المرحلة الثانية بشكل مبدئي بدون إجبار تنفيذها في المرحلة الأولى.
- توضيح علاقة Position وHeadcount وVacancy وCampaign وJob Post وApplication Form.

### المطلوب تنفيذه

- ERD نهائي.
- ملاحظات مسؤولية كل جدول.
- العلاقات الأساسية.
- ترتيب الـ migrations.
- فصل واضح بين HR Master Data وRecruitment Workflow Data.

### قواعد العمل

- لا يتم تكرار Positions أو Employees أو Users أو Departments أو Programs أو Centers.
- Recruitment Request لا يعدل HR Master Data إلا بعد الموافقة النهائية.
- Create Position Request يخزن تفاصيل المنصب المقترح حتى تتم الموافقة.
- Increase/Decrease Headcount يخزن التغيير المقترح حتى تتم الموافقة.
- Close Position يختلف عن Close Vacancy.

### الأمان والصلاحيات

- التصميم يجب أن يدعم Role + Scope.
- التصميم يجب أن يدعم Object-level Authorization.
- بيانات Public Recruitment Link يجب أن تكون منفصلة عن بيانات الموافقات الداخلية.

### الحالات الاستثنائية

- المنصب يصبح inactive أثناء انتظار الموافقة.
- الشاغر يغلق أثناء انتظار الموافقة.
- الطلب يشير إلى Position خارج Scope المستخدم.
- يوجد طلب active سابق لنفس Vacancy.
- يوجد عقود فعالة تمنع إغلاق المنصب.

### سلامة البيانات

- استخدام Foreign Keys حيثما أمكن.
- إضافة Indexes للحالات المهمة.
- عدم حذف Workflow History.
- التصميم يجب أن يحافظ على التاريخ.

### ملاحظات تنفيذية

الجداول المقترحة للمرحلة الأولى:

- `recruitment_requests`
- `recruitment_request_versions`
- `recruitment_request_position_details`
- `recruitment_workflow_definitions`
- `recruitment_workflow_definition_versions`
- `recruitment_workflow_steps`
- `recruitment_workflow_instances`
- `recruitment_workflow_instance_steps`
- `recruitment_request_comments`
- `vacancies`
- `hiring_campaigns`
- `job_posts`
- `job_post_versions`
- `application_forms`
- `application_form_versions`
- `public_recruitment_links`

إذا كان الوقت ضيقاً، يمكن تبسيط Workflow Definition، لكن يجب الحفاظ على فكرة Workflow Version وWorkflow Instance حتى لا تتغير الطلبات القديمة عند تعديل إعدادات Workflow لاحقاً.

### معايير القبول

- ERD يميز الجداول الحالية والجديدة بوضوح.
- ERD يدعم كامل المرحلة الأولى.
- ERD قابل للتوسع للمرحلة الثانية.
- ERD يدعم Request Versioning وWorkflow Versioning.
- ERD يفصل Public عن Internal.
- الفريق يوافق على ERD قبل البدء بالمigrations.

### أخطاء يجب تجنبها

- عدم تكرار HR Master Data.
- عدم اعتبار Recruitment Request هو نفسه Vacancy.
- عدم تصميم Workflow لسلسلة موافقات واحدة hard-coded.

### المخرجات والتسليم للمهمة التالية

ERD ومخطط migrations جاهزان لباقي المهام.

## المهمة 3 - إنشاء الجداول والموديلات والقيود

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يوم ونصف  

### الهدف

تنفيذ بنية المرحلة الأولى في قاعدة البيانات مع القيود التي تحمي سلامة البيانات.

### نطاق التنفيذ

- إنشاء migrations.
- إنشاء models والعلاقات.
- إضافة indexes وunique constraints.
- إضافة request number وpublic token uniqueness.
- دعم rollback أثناء التطوير.
- تعريف statuses وrequest types حسب نمط المشروع.

### المطلوب تنفيذه

- Laravel migrations.
- Models.
- Relationships.
- Indexes.
- Constraints.
- Verification للـ migrations.

### قواعد العمل

- Request Version يتبع Request.
- Approval Step يتبع Workflow Instance وRequest Version.
- Vacancy يتبع Position ويمكن أن ينتج من Request.
- Campaign يتبع Vacancy.
- Job Post Version يتبع Job Post/Campaign.
- Application Form Version يتبع Application Form/Campaign.
- Public Link يتبع Campaign.

### الأمان والصلاحيات

- Public token لا يجب أن يكشف ID تسلسلي.
- يفضل تخزين hash للـ token إذا كان مناسباً.
- إضافة indexes تساعد في Scope Authorization.

### الحالات الاستثنائية

- migration يفشل في المنتصف.
- rollback أثناء التطوير.
- token collision.
- request يشير إلى HR data غير فعالة.

### سلامة البيانات

- Foreign Keys حيثما أمكن.
- Unique constraints للـ request number وtoken/hash.
- حماية duplicate active requests بالـ DB والتطبيق عند الإمكان.
- عدم cascade delete لتاريخ workflow.

### معايير القبول

- migrations تعمل بنجاح.
- rollback يعمل أثناء التطوير.
- العلاقات الأساسية موجودة.
- الحقول المهمة مفهرسة.
- request number وpublic token محميان بقيود مناسبة.
- لا توجد إعادة إنشاء لجداول HR الحالية.

### أخطاء يجب تجنبها

- عدم استخدام ID تسلسلي كرابط عام.
- عدم حذف Workflow History.
- عدم ترك Workflow بدون ارتباط بالطلب والنسخة.

### المخرجات والتسليم للمهمة التالية

قاعدة بيانات جاهزة لبناء الطلبات والـ Workflow والـ Campaigns والـ Public Link.

## المهمة 4 - الصلاحيات وScope Resolver

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يوم ونصف  

### الهدف

إنشاء صلاحيات Recruitment مع Backend Scope Checks مبنية على Role + Scope وليس Role فقط.

### نطاق التنفيذ

- تعريف permission names.
- Seed أو config للصلاحيات.
- Route/Action authorization.
- Scope resolver.
- دعم scope مثل project/program/governorate/department/organization-wide.

### المطلوب تنفيذه

- Permission list.
- Policies/Gates أو authorization layer حسب نمط المشروع.
- Scope Resolver Service.
- حالات اختبار للصلاحيات.

### قواعد العمل

- Project Manager يرى ويتصرف ضمن مشروعه فقط.
- Program Manager ضمن نطاق البرنامج.
- HR ضمن نطاق المحافظة/المنطقة.
- Finance ضمن نطاقه المالي.
- HR Director على مستوى المنظمة.

### الأمان والصلاحيات

- كل عملية حساسة تحتاج backend authorization.
- حماية من IDOR/BOLA.
- Export وDownload وPublish وClose وApprove وReject تحتاج صلاحيات واضحة.

### الحالات الاستثنائية

- تغيير ID في URL.
- HR يملك الدور لكن خارج النطاق.
- الموافق يفقد الصلاحية قبل الموافقة.
- User لديه role لكن لا يوجد scope.
- بيانات الهيكل التنظيمي ناقصة.

### سلامة البيانات

- تخزين acting user في workflow/audit.
- عدم الاعتماد فقط على role الحالي لتفسير أحداث قديمة.

### صلاحيات مقترحة

- `recruitment.requests.view`
- `recruitment.requests.create`
- `recruitment.requests.edit`
- `recruitment.requests.submit`
- `recruitment.requests.review`
- `recruitment.requests.approve`
- `recruitment.requests.reject`
- `recruitment.requests.request_changes`
- `recruitment.vacancies.view`
- `recruitment.vacancies.manage`
- `recruitment.campaigns.manage`
- `recruitment.job_posts.manage`
- `recruitment.job_posts.publish`
- `recruitment.forms.manage`
- `recruitment.public_links.manage`
- `recruitment.audit.view`

### معايير القبول

- المستخدم غير المصرح لا يستطيع دخول صفحات Recruitment.
- المستخدم لا يرى طلبات خارج scope.
- المستخدم لا يستطيع الموافقة على step غير مخصص له.
- الواجهة تخفي الأفعال، والـ backend يمنعها أيضاً.

### أخطاء يجب تجنبها

- عدم الاعتماد على role name فقط.
- عدم الاكتفاء بإخفاء الأزرار.
- عدم تطبيق scope على list فقط ونسيانه في detail/actions.

### المخرجات والتسليم للمهمة التالية

طبقة صلاحيات تستخدمها كل مهام الطلبات والموافقات والنشر.

## المهمة 5 - دورة حياة Recruitment Request والـ Versioning

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يومان  

### الهدف

تنفيذ إنشاء الطلب، حفظه كمسودة، تعديله، إرساله، وإنشاء نسخ محفوظة عند الإرسال أو إعادة الإرسال.

### نطاق التنفيذ

- Create Draft.
- Edit Draft.
- Save request details حسب type.
- Create Request Version on Submit.
- Preserve Submitted Versions.
- Allow controlled changes only.
- Revalidate after changes.

### المطلوب تنفيذه

- Request Creation Service.
- Request Update Service.
- Request Submit Service.
- Versioning Logic.
- Validation by Request Type.
- Request list/detail basic UI أو endpoints حسب نمط المشروع.

### قواعد العمل

- Draft يمكن تعديله من requester ضمن scope.
- Submitted request لا يعدل بصمت.
- أي تعديل بعد الإرسال ينشئ نسخة جديدة.
- Approval ينطبق على Request Version محددة.
- تعديل حقول مهمة يبطل الموافقات السابقة حسب القواعد.

### الأمان والصلاحيات

- requester يجب أن يملك صلاحية ونطاق لإنشاء الطلب.
- backend يتجاهل أو يرفض الحقول الداخلية غير المسموحة.
- المستخدم لا يستطيع تعيين approval status أو workflow step أو approver.

### الحالات الاستثنائية

- double-click submit.
- browser retry.
- network timeout بعد submit.
- تعديل draft من تبويبين.
- position يصبح inactive قبل submit.
- salary يتغير بعد موافقة Finance.
- decrease headcount أقل من عدد الموظفين الحالي.

### سلامة البيانات

- snapshot للنسخة عند submit.
- لا حذف للنسخ القديمة.
- transaction عند إنشاء version وworkflow.
- optimistic locking أو version check لتجنب overwrite.

### ملاحظات تنفيذية

تدفق الإرسال:

```text
Draft request saved
    -> Submit
    -> Validate draft
    -> Create request version N
    -> Create workflow instance for version N
    -> Mark request Submitted / Under Review
```

في حالة طلب التعديل:

```text
Changes Requested
    -> requester edits based on last version
    -> resubmit
    -> create version N+1
    -> restart/revalidate workflow
```

### معايير القبول

- يمكن إنشاء draft.
- يمكن تعديل draft.
- يمكن إرسال request صالح.
- يتم رفض البيانات غير الصالحة.
- يتم حفظ النسخة المرسلة.
- resubmission ينشئ نسخة جديدة.
- لا تبقى approval قديمة صالحة بعد تعديل جوهري.

### أخطاء يجب تجنبها

- عدم تعديل submitted version مباشرة.
- عدم قبول status fields من frontend.
- عدم اعتبار الموافقات القديمة صالحة بعد تغييرات مؤثرة.

### المخرجات والتسليم للمهمة التالية

Versioned Recruitment Request جاهز لإنشاء Workflow Instance.

## المهمة 6 - منع الطلبات المكررة وIdempotent Submit

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يوم واحد  

### الهدف

منع إنشاء طلبات active مكررة ومنع تكرار submit الناتج عن race conditions أو retry أو double-click.

### نطاق التنفيذ

- تعريف active request.
- منع duplicate active request لنفس vacancy/position/action.
- idempotency للـ submit.
- transaction/locking عند submit.

### المطلوب تنفيذه

- Duplicate active request validation.
- Idempotent submit behavior.
- Transactional submit operation.
- Safe response عند retry.

### قواعد العمل

- لا يسمح بعدة active requests لنفس Vacancy إذا السياسة تمنع ذلك.
- browser retry لا ينشئ workflow جديد.
- duplicate request يعطي رسالة واضحة بدون تسريب بيانات.
- الطلبات rejected/cancelled قد تسمح بطلب جديد حسب policy.

### الأمان والصلاحيات

- رسالة duplicate لا تكشف طلب خارج scope.
- idempotency key لا يبقى صالحاً للأبد.

### الحالات الاستثنائية

- مستخدمان يرسلان نفس vacancy بنفس الوقت.
- نفس المستخدم يضغط submit مرتين.
- timeout بعد نجاح العملية.
- vacancy تغلق بين validation وsubmit.

### سلامة البيانات

- transaction يشمل duplicate check + version creation + workflow creation.
- row lock على vacancy/position عند الحاجة.
- unique constraint عند الإمكان.

### معايير القبول

- double submit ينتج request/version/workflow واحد.
- simultaneous submit لا ينشئ duplicate active workflow.
- retry يرجع الحالة الصحيحة عند الإمكان.

### أخطاء يجب تجنبها

- عدم الاعتماد على disabled button فقط.
- عدم عمل duplicate check خارج transaction فقط.
- عدم كشف تفاصيل طلبات الآخرين.

### المخرجات والتسليم للمهمة التالية

Submit آمن جاهز للـ Workflow.

## المهمة 7 - Workflow Instance وتحديد الموافقين

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يومان  

### الهدف

تنفيذ Workflow sequential يحفظ نسخة الـ Workflow ويحدد الموافقين حسب Role + Scope.

### نطاق التنفيذ

- Workflow Definition/Version أو equivalent مبسط.
- Workflow Instance عند submit.
- Workflow Instance Steps.
- Approver Resolver.
- Skipped steps مع السبب.
- Current step.

### المطلوب تنفيذه

- Workflow Instance Creation Service.
- Approver Resolver Service.
- Pending Approvals Query.
- Current Step Logic.

### قواعد العمل

- route يعتمد على request type والسياق التنظيمي.
- approver يحدد حسب role + scope.
- skipped steps تحفظ ولا تتجاهل بصمت.
- instance يستخدم workflow version الفعال عند البدء.
- current step يتقدم فقط بعد approval صحيح.

### الأمان والصلاحيات

- current authorized approver فقط يستطيع اتخاذ القرار.
- scope يتم فحصه عند action time.
- إذا لا يوجد approver، يدخل الطلب في blocked/escalation state.

### الحالات الاستثنائية

- لا يوجد Finance user ضمن scope.
- عدة approvers محتملين.
- approver inactive.
- requester هو نفس approver.
- workflow config تغير بعد بدء instance.

### سلامة البيانات

- Workflow instance يشير إلى Request Version.
- Steps تحفظ role/scope المطلوب.
- لا يتم تعديل التاريخ القديم عند تغيير config.

### معايير القبول

- workflow instance ينشأ عند submit.
- steps تنشأ بالترتيب الصحيح.
- current step واضح.
- approver resolution يحترم Role + Scope.
- skipped steps مرئية.

### أخطاء يجب تجنبها

- عدم hard-code لمستخدم محدد.
- عدم استخدام role فقط بدون scope.
- عدم تعديل workflow instance قديم عند تغيير الإعدادات.

### المخرجات والتسليم للمهمة التالية

Workflow جاهز لأفعال approve/reject/request changes.

## المهمة 8 - قرارات الموافقة وإعادة التحقق

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يومان  

### الهدف

تنفيذ approve وreject وrequest changes والتقدم في Workflow مع منع stale أو unauthorized actions.

### نطاق التنفيذ

- Approve current step.
- Reject request.
- Request changes.
- Advance workflow.
- Complete workflow.
- Revalidate before final approval.

### المطلوب تنفيذه

- Approval Action Service.
- Rejection Reasons.
- Request Changes Comments.
- Workflow Advancement.
- Concurrent action protection.
- UI/actions أو endpoints.

### قواعد العمل

- approval مرتبط بالنسخة الحالية.
- reject يحتاج reason.
- request changes يحتاج comment.
- stale approval يفشل.
- final approval يشغل Approved Business Action.

### الأمان والصلاحيات

- permission check.
- current workflow step check.
- approver role check.
- scope check.
- request actionable check.
- منع replay/duplicate approvals.

### الحالات الاستثنائية

- موافقان يتصرفان بنفس الوقت.
- المستخدم فتح صفحة قديمة.
- request cancelled أثناء المراجعة.
- reject بدون سبب.
- workflow step بدون approver.

### سلامة البيانات

- transaction حول approval + advancement.
- lock للـ workflow step.
- حفظ user/date/decision/comment/version.
- منع completion مرتين لنفس step.

### معايير القبول

- authorized approver يستطيع الموافقة.
- unauthorized user ممنوع.
- reject يحتاج reason.
- request changes يرجع للـ requester.
- duplicate approval ممنوع.
- stale approval ممنوع.
- final approval يكمل workflow ويجهز تطبيق الإجراء.

### أخطاء يجب تجنبها

- عدم تحديث status مباشرة بدون transition validation.
- عدم الموافقة على نسخة قديمة.
- عدم اعتبار notification link كصلاحية.

### المخرجات والتسليم للمهمة التالية

Approved Request جاهز للتطبيق التجاري transactionally.

## المهمة 9 - تطبيق الإجراء بعد الموافقة بشكل Transactional

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يومان  

### الهدف

تطبيق أثر الطلب الموافق عليه على بيانات HR/Recruitment بدون partial states.

### نطاق التنفيذ

- Fill Vacancy.
- Increase Headcount.
- Decrease Headcount.
- Create Position.
- Close Position.
- Close Vacancy.
- Final business validation.
- Transactional operation.

### المطلوب تنفيذه

- Approved Request Application Service.
- Action Handlers by Request Type.
- Transaction.
- Failure state.
- Audit events.

### قواعد العمل

- Fill Vacancy يستخدم أو ينشئ vacancy حسب policy.
- Increase Headcount يحدث capacity وينشئ vacancy.
- Create Position ينشئ position ثم capacity/vacancy.
- Decrease Headcount لا يقلل العدد تحت active employees.
- Close Position يمنع إذا active contracts تمنع ذلك.
- Close Vacancy يغلق الحاجة فقط.

### الأمان والصلاحيات

- فقط workflow completion يشغل هذا الإجراء.
- التأكد أن request approved ولم يتم تطبيقه سابقاً.

### الحالات الاستثنائية

- position تغيرت أثناء approval.
- vacancy أغلقت.
- active employee count تغير قبل decrease.
- position creation نجح وvacancy failed.
- job retry بعد partial failure.

### سلامة البيانات

- transaction لكل DB changes.
- lock للـ position/vacancy عند الحاجة.
- mark applied بعد نجاح كل شيء.
- failed application state عند الفشل.

### معايير القبول

- كل request type يطبق أثره الصحيح.
- invalid final state يوقف العملية بأمان.
- لا يوجد partial position/headcount/vacancy state.
- request لا يطبق مرتين.
- audit يوضح ما تغير.

### أخطاء يجب تجنبها

- عدم إنشاء position خارج transaction ثم vacancy لاحقاً بدون recovery.
- عدم mark request applied قبل اكتمال التغييرات.
- عدم تجاهل final revalidation.

### المخرجات والتسليم للمهمة التالية

Vacancy/Campaign-ready state.

## المهمة 10 - سجل التدقيق والخط الزمني

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يوم واحد  

### الهدف

تسجيل تاريخ واضح للعمليات المهمة في دورة التوظيف.

### نطاق التنفيذ

- تعريف audit events.
- log request lifecycle.
- log workflow decisions.
- log post-approval changes.
- timeline في request details.

### الأحداث التي يجب تسجيلها

- request created.
- request edited.
- request version created.
- request submitted.
- workflow started.
- workflow advanced.
- approval.
- rejection.
- changes requested.
- resubmission.
- request cancelled.
- position/headcount/vacancy changed.
- vacancy created/closed.
- campaign created/published/closed.
- job post changed/published.
- form version published.
- public link created/disabled.

### الأمان والصلاحيات

- timeline تحترم scope.
- internal audit لا يظهر للـ public.
- التفاصيل الحساسة لا تظهر لمستخدم غير مصرح.

### الحالات الاستثنائية

- action succeeded لكن audit failed.
- user يصبح inactive لاحقاً.
- admin action نيابة عن مستخدم.
- failed business action يحتاج audit.

### معايير القبول

- request detail يظهر timeline.
- approvals واضحة.
- version changes واضحة.
- business operation results واضحة.
- unauthorized users لا يرون audit خارج scope.

### أخطاء يجب تجنبها

- عدم اعتبار `updated_at` audit trail.
- عدم عرض audit على public link.
- عدم تجاهل rejected/failed flows.

### المخرجات والتسليم للمهمة التالية

Workflow history واضح للاختبار والمراجعة.

## المهمة 11 - التنبيهات بعد نجاح العملية

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: ثلاثة أرباع يوم  

### الهدف

إرسال التنبيهات للأشخاص الصحيحين بدون أن تكون Notifications هي مصدر الحقيقة.

### نطاق التنفيذ

- notify next approver.
- notify requester عند approval/rejection/request changes.
- notify HR عند approved request.
- queue notifications عند الإمكان.
- تجنب المعلومات الحساسة في الإيميل.

### قواعد العمل

- فشل الإيميل لا يغير حالة الطلب.
- النظام نفسه هو المصدر الحقيقي.
- التنبيه يحتوي رابط آمن داخل النظام.

### الحالات الاستثنائية

- email failure.
- queue down.
- duplicate notification.
- transaction rollback.
- approver changed after notification.

### معايير القبول

- next approver يتلقى notification.
- requester يتلقى النتيجة.
- فشل الإيميل لا يفسد workflow.
- لا توجد بيانات حساسة مكشوفة في الإيميل.

### أخطاء يجب تجنبها

- عدم إرسال notification قبل commit.
- عدم استخدام email كمصدر وحيد للـ pending approvals.
- عدم السماح approve/reject من public email link.

### المخرجات والتسليم للمهمة التالية

Workflow participants على علم، والـ DB تبقى source of truth.

## المهمة 12 - دورة حياة حملة التوظيف

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يوم وربع  

### الهدف

إنشاء وإدارة Hiring Campaign مرتبطة بـ Vacancy مع حالات واضحة.

### نطاق التنفيذ

- create campaign from vacancy.
- start/end dates.
- campaign statuses.
- server-side availability checks.
- close/disable/expire.

### قواعد العمل

- campaign ينشأ فقط لـ valid vacancy.
- لا يصبح public قبل publish/open.
- يمكن إغلاقه يدوياً.
- ينتهي حسب end date.
- closed/expired/disabled لا يقبل submissions.

### الأمان والصلاحيات

- HR authorized فقط يستطيع create/publish/close.
- campaign actions تحتاج scope.
- public endpoint يتحقق من campaign state عند submit.

### الحالات الاستثنائية

- campaign closes أثناء تعبئة applicant للفورم.
- HR يغلق campaign بنفس لحظة submit.
- vacancy closes قبل publish.
- end date في الماضي.
- campaign disabled بسبب spam/security.

### معايير القبول

- HR can create campaign.
- HR can set dates.
- HR can publish/open/close.
- closed/expired campaign blocks public submit.
- campaign actions audited.

### أخطاء يجب تجنبها

- عدم الاعتماد على frontend date check.
- عدم قبول submit فقط لأن الصفحة فتحت قبل الإغلاق.
- عدم حذف campaign history.

### المخرجات والتسليم للمهمة التالية

Campaign جاهز للـ Job Post وApplication Form وPublic Link.

## المهمة 13 - نسخة إعلان الوظيفة ونشره

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يوم وربع  

### الهدف

توليد ونشر Job Post بدون تعديل master Position وبدون تغير المنشورات القديمة تلقائياً.

### نطاق التنفيذ

- generate draft from vacancy/position/request.
- edit job post.
- create published version/snapshot.
- publish/unpublish.
- public-safe serializer.

### قواعد العمل

- HR يستطيع تعديل draft.
- تعديل Job Post لا يعدل Position.
- المنشور published يحفظ snapshot.
- تغير Position لاحقاً لا يغير المنشور القديم.
- public page تعرض published version فقط.

### الأمان والصلاحيات

- HR authorized فقط يستطيع publish/edit.
- public post لا يعرض internal data.
- salary/internal notes لا تظهر إلا إذا marked public.

### الحالات الاستثنائية

- Position changes after publish.
- HR يحرر وHR آخر ينشر.
- missing public fields.
- post unpublished while link active.
- public user tries draft.

### معايير القبول

- HR can generate draft.
- HR can edit draft.
- HR can publish version.
- public view uses snapshot.
- edit post لا يغير Position.
- draft/unpublished لا يظهر للpublic.

### أخطاء يجب تجنبها

- عدم جعل Job Post public live view من positions.
- عدم كشف internal fields.
- عدم overwrite للـ published history.

### المخرجات والتسليم للمهمة التالية

Published Job Post Snapshot جاهز للـ public link.

## المهمة 14 - إدارة نسخ نموذج التقديم

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يوم وربع  

### الهدف

إنشاء Application Form Versions بحيث تبقى الطلبات المستقبلية مرتبطة بالنسخة التي تم التقديم عليها.

### نطاق التنفيذ

- create form draft.
- define fields.
- publish form version.
- link published form version to campaign.
- public form rendering.

### قواعد العمل

- draft form قابل للتعديل.
- published version immutable أو يتم تغييره بنشر نسخة جديدة.
- application submissions تشير إلى form version.
- public form يجمع minimum necessary data.

### الأمان والصلاحيات

- public form schema لا يكشف internal field names الحساسة.
- applicant لا يستطيع إرسال internal fields.
- server يستخدم whitelist.

### الحالات الاستثنائية

- HR يعدل الفورم بعد فتح الحملة.
- applicant فتح V1 وHR نشر V2 قبل submit.
- public user يضيف fields إضافية.

### معايير القبول

- HR can create/edit draft.
- HR can publish version.
- public schema shows allowed fields only.
- form version preserved.
- server validates submission against version.

### أخطاء يجب تجنبها

- عدم تعديل published version in place.
- عدم قبول posted fields بشكل أعمى.
- عدم بناء form ضخم غير ضروري.

### المخرجات والتسليم للمهمة التالية

Published Form Version جاهزة للـ public link.

## المهمة 15 - رابط التوظيف العام وحدود نقطة الوصول العامة

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: يوم ونصف  

### الهدف

إنشاء public recruitment link آمن يعرض فقط البيانات العامة المنشورة ويطبق قواعد الأمان من السيرفر.

### نطاق التنفيذ

- generate opaque token.
- store token securely.
- support link mode: public open link أو restricted invitation-only link.
- public GET endpoint.
- public POST submission shell.
- campaign state checks.
- rate limiting/anti-abuse.
- generic errors.

### قواعد العمل

- public link يتبع campaign.
- token يمثل campaign/public access وليس candidate.
- link يمكن disabled.
- draft/unpublished/closed/expired لا تقبل submit.
- public user يرى فقط published job post + form version.
- يمكن أن يكون الرابط مفتوحاً للجميع، أو مقيّداً بالدعوة فقط.
- في نمط الدعوة الداخلية، HR يضيف أسماء وإيميلات الأشخاص المسموح لهم بالتقديم مسبقاً.
- في نمط الدعوة الداخلية، لا يستطيع التقديم إلا الشخص الذي يدخل إيميل موجود مسبقاً في قائمة الدعوات الخاصة بالحملة.

### الأمان والصلاحيات

- عدم كشف IDs تسلسلية.
- عدم كشف campaign/vacancy/position/workflow/internal users.
- عدم قبول internal fields.
- explicit whitelist.
- rate limiting.
- generic invalid token response.
- يفضل token hash storage عند الإمكان.
- في نمط الدعوة الداخلية، يجب عدم كشف قائمة الإيميلات المدعوة للـ public.
- فشل مطابقة الإيميل يجب أن يعطي رسالة عامة بدون كشف كل الإيميلات المسموحة.

### الحالات الاستثنائية

- token guessing.
- scraping.
- bot spam.
- repeated submission.
- oversized payload.
- public user posts `campaign_id`, `status`, `score`, `salary`.
- campaign closes between page load and submit.
- شخص غير مدعو يحصل على الرابط ويحاول التقديم.
- شخص مدعو يدخل إيميل مختلف عن الإيميل المسجل مسبقاً.
- HR يضيف نفس الإيميل أكثر من مرة لنفس الحملة.
- HR يعدل أو يلغي دعوة بعد إرسال الرابط.
- شخص مدعو يرسل الطلب مرتين بنفس الإيميل.

### ملاحظات تنفيذية

Endpoints عامة مقترحة:

```text
GET  /careers/{token}
POST /careers/{token}/applications
```

لا تستخدم endpoints عامة مثل:

```text
/api/hiring-campaigns
/api/positions
/api/candidates
```

### معايير القبول

- link يستخدم token غير قابل للتخمين.
- invalid token يعطي generic response.
- public endpoint يعرض public data فقط.
- closed/expired/disabled campaign يمنع submit.
- internal fields ترفض أو تتجاهل.
- rate limiting موجود.
- الرابط يدعم نمطين: open public وrestricted invitation-only.
- في نمط invitation-only، لا يتم قبول submit إلا إذا كان الإيميل موجوداً مسبقاً ضمن قائمة المدعوين للحملة.

### أخطاء يجب تجنبها

- عدم استخدام `/careers/125`.
- عدم الثقة بالحقول المخفية.
- عدم كشف internal IDs.
- عدم الاعتماد على وقت فتح الصفحة للسماح بالsubmit.
- عدم اعتبار إرسال الرابط وحده كافياً للتحقق من هوية الشخص في نمط الدعوة؛ يجب التحقق من الإيميل المدخل مقابل قائمة الدعوات.

### المخرجات والتسليم للمهمة التالية

Public Recruitment Link آمن وجاهز لاختبار المرحلة الأولى.

## المهمة 16 - بنية استقبال الملفات الآمنة

المسؤول: مطور المرحلة الأولى 1  
الجهد التقديري: ثلاثة أرباع يوم  

### الهدف

تجهيز بنية آمنة لرفع CV حتى لو اكتمل التنفيذ الكامل في المرحلة الثانية.

### نطاق التنفيذ

- allowed file rules.
- validation structure.
- private storage.
- download authorization placeholder.
- malware scan/quarantine strategy.

### قواعد العمل

- CV required فقط إذا تم تفعيل submit فعلي في Phase 1.
- إذا CV required، لا يعتبر submit نهائي بدون CV مخزن بأمان.

### الأمان والصلاحيات

- allow-list file types.
- validate extension + MIME.
- validate file signature/content عند الإمكان.
- file size/count limits.
- generated filename.
- private storage.
- authorized downloads.
- عدم الثقة بـ Content-Type من المتصفح.
- عدم السماح executable files.

### الحالات الاستثنائية

- `.php` renamed to `.pdf`.
- huge file.
- many files.
- storage fails after DB save.
- scan fails.
- download خارج scope.

### معايير القبول

- unsafe files blocked.
- files not publicly executable/accessed.
- generated filename used.
- download requires authorization.
- scan status placeholder موجود إذا لم يوجد antivirus.

### أخطاء يجب تجنبها

- عدم التخزين في public webroot.
- عدم استخدام applicant filename كاسم تخزين.
- عدم الثقة بالـ Content-Type.
- عدم السماح بتحميل CV بدون scope check.

### المخرجات والتسليم للمهمة التالية

أساس آمن لإدارة ملفات المتقدمين في المرحلة الثانية.

## المهمة 17 - تكامل واجهات المرحلة الأولى

المسؤول: مطور المرحلة الأولى 2  
الجهد التقديري: يوم ونصف  

### الهدف

ربط واجهات المرحلة الأولى بحيث يستطيع المستخدم إكمال flow من request إلى publication.

### نطاق التنفيذ

- Recruitment navigation.
- My Requests.
- Create/Edit Request.
- Request Details/Timeline.
- Pending Approvals.
- Vacancy/Campaign Management.
- Job Post Editor.
- Application Form Editor.
- Public Link Management.
- Public Job/Application Page.

### قواعد العمل

- الأفعال في الواجهة تعكس current state.
- المستخدم لا يرى أفعال مستحيلة.
- backend يظل يتحقق من كل action.
- request details تعرض current status وnext step.

### الأمان والصلاحيات

- عدم الاعتماد على hidden buttons.
- detail pages تطبق scope.
- public page تستخدم public-safe serializer.
- internal pages require authentication.

### الحالات الاستثنائية

- status يتغير بعد فتح الصفحة.
- action من stale page.
- public link expires while page open.
- bookmarked URL بدون صلاحية.

### معايير القبول

- create/submit request يعمل.
- approver يراجع ويتصرف.
- HR يدير campaign/job post/form/link.
- public page opens by token.
- stale/unauthorized actions blocked.

### أخطاء يجب تجنبها

- عدم تنفيذ business rules في UI فقط.
- عدم كشف internal IDs في public page.
- عدم إدخال Phase 2 screens كاملة في Phase 1.

### المخرجات والتسليم للمهمة التالية

تدفق المرحلة الأولى قابل للاستخدام وجاهز للاختبار.

## المهمة 18 - اختبار التكامل والأمان وقواعد العمل

المسؤول: مطور المرحلة الأولى 1 ومطور المرحلة الأولى 2  
الجهد التقديري: يومان إجمالاً  

### الهدف

اختبار flow كامل للمرحلة الأولى مع التركيز على الصلاحيات، الحالات، concurrency، والـ public boundary.

### نطاق التنفيذ

- End-to-end testing.
- Permission testing.
- State transition testing.
- Public endpoint testing.
- Duplicate/concurrency testing.
- Audit verification.
- Notification verification.
- Bug fixing.

### سيناريوهات يجب اختبارها

- Fill Vacancy normal path.
- Create Position normal path.
- Increase Headcount.
- Decrease Headcount blocked by active employees.
- Close Position blocked by active contracts.
- Close Vacancy.
- Rejection with reason.
- Request changes and resubmission.
- Skipped program review.
- Finance skipped when policy allows.

### اختبارات الأمان

- HR خارج scope لا يرى request.
- Finance خارج scope لا يوافق.
- User لا يوافق على wrong step.
- public token لا يكشف internal fields.
- invalid token generic response.
- closed campaign blocks submit.
- publish بدون permission ممنوع.

### اختبارات التزامن والحالات الاستثنائية

- double submit.
- two users submit same vacancy.
- two approvers act same time.
- request edited after approval step.
- position changes before final approval.
- campaign closes while public submit.
- email/queue failure لا يغير workflow state.

### معايير القبول

- critical paths تعمل.
- security boundary tests تمر.
- concurrency tests تمر أو لها controls موثقة.
- لا توجد critical/high bugs.
- Phase 1 جاهزة للرفع online.

### أخطاء يجب تجنبها

- عدم تخطي security tests لأن الواجهة تبدو صحيحة.
- عدم نشر public endpoint بدون campaign state checks.
- عدم ترك data integrity bug للـ feedback phase.

### المخرجات والتسليم للمهمة التالية

نسخة المرحلة الأولى المرشحة للإطلاق.

## المهمة 19 - النشر وإدارة الملاحظات

المسؤول: مطور المرحلة الأولى 2 مع دعم مطور المرحلة الأولى 1  
الجهد التقديري: يوم واحد  

### الهدف

رفع المرحلة الأولى online للتجريب وإنشاء آلية feedback بدون تعطيل Phase 2.

### نطاق التنفيذ

- environment configuration.
- deploy Phase 1.
- smoke test.
- feedback tracking board/list.
- assign feedback owner.
- categorize feedback.

### قواعد العمل

- feedback يصنف إلى bug/improvement/question/Phase 2 idea/policy decision.
- critical bugs تعالج بسرعة.
- non-critical improvements تذهب للـ backlog.

### الأمان والصلاحيات

- HTTPS.
- auth pages require login.
- debug mode off.
- public endpoint لا يكشف stack traces.

### الحالات الاستثنائية

- env variables missing.
- queue not running.
- mail not configured.
- wrong public base URL.
- file storage permissions wrong.

### معايير القبول

- Phase 1 online.
- smoke test passes.
- public link opens.
- feedback board exists.
- feedback owner assigned.

### أخطاء يجب تجنبها

- عدم إيقاف كل الفريق بسبب feedback بسيط.
- عدم deploy مع debug enabled.
- عدم تنفيذ feedback مباشرة بدون triage.


## مهام المرحلة الثانية

## مهمة المرحلة الثانية 1 - نموذج بيانات المتقدمين وطلبات التقديم

المسؤول: مطور المرحلة الثانية 1  
الجهد التقديري: يومان  

### الهدف

إنشاء أساس بيانات واضح يفرق بين الشخص المتقدم Candidate/Application Profile وبين طلب التقديم Application المرتبط بحملة توظيف محددة.

### نطاق التنفيذ

- إنشاء جداول أو موديلات Candidate/Applicant.
- إنشاء Application لكل تقديم على Campaign.
- إنشاء أو تجهيز نموذج Campaign Invite / Invited Applicant لتخزين أسماء وإيميلات الأشخاص المدعوين للتقديم الداخلي.
- ربط Application بالـ Campaign والـ Vacancy والـ Application Form Version.
- حفظ إجابات المتقدم حسب نسخة الفورم المستخدمة.
- تعريف Application Status Machine.
- تجهيز حقول تساعد على كشف المتقدم السابق أو التكرار المحتمل.

### المطلوب تنفيذه

- Applicant/Candidate model.
- Application model.
- Campaign invited applicants model أو جدول دعوات يحتوي الاسم، الإيميل، الحملة، حالة الدعوة، وتاريخ الإرسال.
- Application answers model أو JSON structure آمن حسب قرار التصميم.
- Relationships مع Campaign وForm Version وVacancy.
- Status constants أو enum.
- Indexes للبحث حسب campaign/status/email/phone.

### قواعد العمل

- Candidate هو الشخص أو الملف الشخصي.
- Application هو تقديم هذا الشخص على Campaign محددة.
- نفس Candidate يمكنه التقديم على أكثر من Campaign.
- Duplicate Application لنفس الشخص على نفس Campaign يمكن منعه أو تعليمه حسب السياسة.
- لا يتم دمج Candidates تلقائياً اعتماداً على الاسم فقط.
- أي بيانات قادمة من public form يجب أن تحفظ ضمن application/form version الصحيح.
- في حملات الدعوة الداخلية، HR يضيف الاسم والإيميل مسبقاً، والـ Application يجب أن ترتبط بالدعوة أو تتحقق منها.
- نفس الإيميل لا يجب أن يتكرر أكثر من مرة كدعوة فعالة لنفس الحملة.

### الأمان والصلاحيات

- بيانات المتقدمين PII ولا تظهر إلا لمستخدم داخلي مصرح وضمن Scope.
- Public user لا يستطيع تحديد `application_status` أو `campaign_id` أو `score` أو أي حقول داخلية.
- أي عرض داخلي للمتقدمين يجب أن يستخدم Scope Authorization.
- قائمة المدعوين تعتبر بيانات داخلية ولا تظهر للعامة.
- HR المصرح فقط يستطيع إضافة أو تعديل أو إلغاء دعوات المتقدمين الداخليين.

### الحالات الاستثنائية

- نفس الشخص يقدم على نفس Campaign مرتين.
- نفس الشخص يقدم على حملتين مختلفتين.
- شخصان لهما نفس الاسم.
- email مختلف لكن phone نفسه.
- phone مختلف لكن email نفسه.
- Application Form تغيرت بعد فتح المتقدم للفورم.
- Campaign أغلقت قبل الحفظ النهائي.
- HR أضاف إيميل خاطئ.
- HR ألغى الدعوة بعد إرسال الرابط.
- الشخص المدعو يحاول التقديم بإيميل آخر.
- شخص غير مدعو حصل على الرابط ويحاول رفع CV.

### سلامة البيانات

- Application يجب أن يشير إلى Form Version محددة.
- لا يتم حفظ answers بدون Application.
- لا يتم حفظ Application بدون Campaign صالح.
- Status transitions يجب أن تكون محددة.
- لا حذف مباشر للمتقدمين أو التطبيقات بدون سياسة retention/anonymization.
- Invite يجب أن ترتبط بـ Campaign محددة.
- يجب حفظ normalized email لتجنب التكرار بسبب اختلاف حالة الأحرف أو المسافات.
- Application الناتجة من دعوة داخلية يجب أن تحفظ invite_id أو مرجع واضح للدعوة عند الإمكان.

### ملاحظات تنفيذية

Application Status المقترح:

```text
Submitted
Under Review
Shortlisted
Rejected
Interview Scheduled
Interviewed
Selected
Offer Sent
Offer Accepted
Offer Rejected
Hired
Withdrawn
Archived
```

هذه الحالات يمكن تبسيطها في البداية، لكن يجب ألا تكون مجرد نص حر غير مضبوط.

### معايير القبول

- يمكن إنشاء Applicant/Application من submission صحيح.
- Application ترتبط بـ Campaign وForm Version.
- نفس الشخص يمكن تمييزه كمتقدم سابق دون دمج تلقائي خطير.
- Status محفوظ بطريقة قابلة للتحكم.
- العلاقات الأساسية تعمل من Applicant إلى Applications ومن Application إلى Campaign.
- يمكن لـ HR إضافة قائمة مدعوين للحملة تحتوي الاسم والإيميل.
- في حملات الدعوة الداخلية، يمكن ربط Application بالدعوة التي سمحت بالتقديم.

### أخطاء يجب تجنبها

- عدم اعتبار Candidate وApplication نفس الشيء.
- عدم الدمج التلقائي اعتماداً على الاسم فقط.
- عدم حفظ بيانات public form بدون ربطها بنسخة الفورم.
- عدم السماح للـ public بإرسال status أو score أو campaign_id.
- عدم قبول إيميل غير موجود في قائمة المدعوين عند تفعيل invitation-only mode.
- عدم كشف قائمة المدعوين على الرابط العام.

### المخرجات والتسليم للمهمة التالية

بنية بيانات جاهزة لاستقبال public submissions وحفظها بأمان.

## مهمة المرحلة الثانية 2 - إرسال طلب التقديم من الرابط العام ومنع التكرار

المسؤول: مطور المرحلة الثانية 1  
الجهد التقديري: يومان  

### الهدف

إكمال عملية submit من الرابط العام بحيث يتم حفظ Applicant وApplication والإجابات بشكل آمن، مع منع التكرار الناتج عن retry أو double submit.

يشمل ذلك أيضاً نمط التقديم الداخلي المقيّد، حيث يضيف HR أسماء وإيميلات الأشخاص مسبقاً، ثم يرسل لهم الرابط، ولا يستطيع رفع CV أو التقديم إلا من يدخل إيميلاً موجوداً في قائمة الدعوات الخاصة بالحملة.

### نطاق التنفيذ

- Public submit endpoint.
- Validation حسب Application Form Version.
- Invitation-only email validation عند تفعيل نمط الدعوة الداخلية.
- إنشاء Applicant أو ربطه بملف محتمل.
- إنشاء Application.
- حفظ answers.
- Idempotency handling.
- Duplicate application control.
- Generic public responses.

### المطلوب تنفيذه

- Submission service.
- Server-side field whitelist.
- Invite lookup/validation service للحملات المقيّدة.
- Idempotency key أو submission fingerprint strategy.
- Duplicate check لنفس Campaign.
- Transaction لحفظ applicant/application/answers.
- Controlled error responses.

### قواعد العمل

- السيرفر يستنتج Campaign من token وليس من input المستخدم.
- السيرفر يستنتج Form Version المنشورة من Campaign.
- لا يقبل submit إذا Campaign مغلقة أو منتهية أو disabled.
- نفس submission لا ينتج أكثر من Application.
- إذا السياسة تمنع التقديم المتكرر على نفس Campaign، يجب منع duplicate application.
- إذا كانت الحملة invitation-only، يجب أن يدخل المتقدم إيميلاً مطابقاً لإيميل موجود مسبقاً في قائمة المدعوين لهذه الحملة.
- في نمط invitation-only، لا يتم قبول CV أو إجابات form قبل نجاح مطابقة الإيميل مع الدعوة.
- إذا كانت الدعوة ملغاة أو مستخدمة سابقاً حسب السياسة، يجب منع submission جديد بنفس الدعوة.

### الأمان والصلاحيات

- Public endpoint لا يحتاج login لكنه يعتبر security boundary.
- Rate limiting مطلوب.
- Anti-automation بسيط مثل honeypot أو throttling حسب الحاجة.
- لا يتم قبول internal fields.
- الأخطاء العامة لا تكشف internal IDs أو تفاصيل النظام.
- لا يجب أن تكشف رسالة الخطأ إن كان الإيميل موجوداً في قائمة الدعوات أم لا بطريقة تساعد على enumeration.
- يجب تطبيع الإيميل قبل المقارنة، مثل trim وlowercase.

### الحالات الاستثنائية

- المستخدم يضغط submit مرتين.
- المتصفح يعيد الطلب بعد timeout.
- Campaign تغلق أثناء submit.
- المتقدم يرسل payload كبير.
- المتقدم يرسل fields إضافية مثل `score` أو `status`.
- submission يصل بدون idempotency key.
- answers لا تطابق form version.
- شخص غير مدعو يدخل الرابط ويحاول التقديم.
- شخص مدعو يدخل إيميل فيه مسافات أو اختلاف capital letters.
- شخص مدعو يدخل إيميل مختلف.
- HR ألغى الدعوة بعد إرسال الرابط.
- شخص مدعو يحاول رفع CV أكثر من مرة.

### سلامة البيانات

- Transaction تشمل applicant/application/answers.
- Application لا تحفظ بدون valid Campaign.
- Answers لا تحفظ بدون Application.
- إذا فشل جزء من العملية، لا يبقى partial application.
- في نمط invitation-only، Application لا تحفظ بدون invite صالح.
- يجب منع أكثر من Application فعالة لنفس invite إذا كانت السياسة تسمح بتقديم واحد فقط.

### ملاحظات تنفيذية

ترتيب آمن:

```text
Resolve public token
Check campaign state
Resolve published form version
Validate whitelisted fields
If invitation-only: validate entered email against campaign invite
Start transaction
  Find or create applicant candidate record
  Check duplicate application
  Link application to invite when applicable
  Create application
  Save answers
  Create audit/submission log
Commit
Dispatch notifications/jobs after commit
```

### معايير القبول

- المتقدم يستطيع إرسال form صحيح.
- submit على campaign مغلقة يفشل.
- double submit لا ينشئ application مكررة.
- internal fields ترفض أو تتجاهل بشكل آمن.
- application ترتبط بالنسخة الصحيحة من الفورم.
- في نمط invitation-only، لا يستطيع التقديم إلا صاحب إيميل موجود مسبقاً في قائمة الدعوات.
- في نمط invitation-only، يتم ربط الطلب بالدعوة أو تسجيل مرجع واضح لها.

### أخطاء يجب تجنبها

- عدم الثقة بالـ frontend validation.
- عدم قبول `campaign_id` من public request.
- عدم إنشاء application قبل التحقق من campaign state.
- عدم إظهار stack trace أو internal reason للـ public.
- عدم قبول CV upload في نمط invitation-only قبل التحقق من الإيميل.
- عدم كشف قائمة الإيميلات المدعوة أو تأكيد وجود إيميل محدد برسالة تفصيلية للـ public.

### المخرجات والتسليم للمهمة التالية

Public application submission آمن وجاهز لربط CV والملفات.

## مهمة المرحلة الثانية 3 - تخزين ملفات CV والتحميل المصرح

المسؤول: مطور المرحلة الثانية 2  
الجهد التقديري: يومان  

### الهدف

تنفيذ رفع ملفات CV بطريقة آمنة، مع تخزين خاص وصلاحيات تحميل واضحة.

### نطاق التنفيذ

- CV upload validation.
- Private storage.
- File metadata.
- Generated storage filename.
- Scan/quarantine status.
- Authorized download endpoint.
- Download audit.

### المطلوب تنفيذه

- File validation rules.
- File metadata table/model إذا لم تكن موجودة.
- Private disk/storage configuration.
- Download controller/action with authorization.
- Scan status fields: pending/clean/blocked/failed عند الحاجة.

### قواعد العمل

- CV قد يكون required حسب Campaign/Form configuration.
- إذا كان CV required وفشل التخزين، لا يعتبر application مكتمل.
- HR المصرح فقط يستطيع تحميل CV.
- Download يجب أن يسجل audit event.

### الأمان والصلاحيات

- Allow-list للامتدادات والأنواع.
- MIME validation.
- Signature/content validation عند الإمكان.
- Size limit.
- Count limit.
- عدم استخدام اسم الملف الأصلي للتخزين.
- التخزين يكون private وليس public webroot.
- لا يسمح بتنفيذ الملفات المرفوعة.
- تحميل الملف يحتاج Role + Scope.

### الحالات الاستثنائية

- ملف `.php` باسم `cv.pdf`.
- ملف PDF مزيف.
- ملف ضخم جداً.
- أكثر من ملف فوق الحد المسموح.
- storage failure بعد إنشاء application.
- scan failure.
- HR خارج scope يحاول تحميل CV.

### سلامة البيانات

- File metadata ترتبط بـ Application أو Applicant.
- إذا فشل التخزين، يجب rollback أو mark application incomplete حسب السياسة.
- backup strategy لاحقاً يجب أن تشمل DB وprivate storage.

### ملاحظات تنفيذية

إذا لم يتوفر antivirus في هذه المرحلة:

- احفظ الملف كـ untrusted/private.
- أضف scan_status placeholder.
- لا تجعله متاحاً public.
- اسمح بالتحميل فقط لمستخدم داخلي مصرح.

### معايير القبول

- الملفات غير الآمنة ترفض.
- CV لا يمكن الوصول إليه عبر رابط عام مباشر.
- HR المصرح يستطيع تحميل CV.
- تحميل CV خارج scope مرفوض.
- download audit event يسجل.

### أخطاء يجب تجنبها

- عدم حفظ الملفات في public folder.
- عدم الثقة بـ Content-Type من المتصفح.
- عدم استخدام applicant filename كاسم storage.
- عدم إتاحة download endpoint بدون authorization.

### المخرجات والتسليم للمهمة التالية

ملفات المتقدمين محفوظة بأمان وجاهزة للعرض داخل Applicant Profile.

## مهمة المرحلة الثانية 4 - قائمة المتقدمين والملف الشخصي والملاحظات

المسؤول: مطور المرحلة الثانية 2  
الجهد التقديري: ثلاثة أيام  

### الهدف

بناء واجهة داخلية تسمح لـ HR بمراجعة المتقدمين، فتح ملف المتقدم، مشاهدة الطلبات السابقة، وإضافة ملاحظات داخلية.

### نطاق التنفيذ

- Applicant list.
- Application list by campaign/vacancy.
- Applicant profile.
- Application history.
- CV preview/download link حسب الصلاحية.
- Internal notes.
- Previous applicant indicator.
- CV archive without vacancy عند الحاجة.

### المطلوب تنفيذه

- Screens أو Livewire components للقائمة والملف.
- Filters أساسية حسب campaign/status/date/source.
- Notes model/action.
- Previous applicant detection display.
- Scope-aware queries.

### قواعد العمل

- HR يرى فقط المتقدمين ضمن نطاقه.
- الملاحظات داخلية ولا تظهر للمتقدم.
- المتقدم السابق يظهر كتنبيه للمراجعة، وليس كقرار تلقائي.
- CV archive بدون vacancy يحتاج note وسبب.

### الأمان والصلاحيات

- Object-level authorization لكل Applicant وApplication.
- CV download يحتاج صلاحية منفصلة أو تحقق واضح.
- Notes قد تحتوي معلومات حساسة، لذلك لا تظهر خارج scope.
- منع IDOR عند فتح applicant profile من URL.

### الحالات الاستثنائية

- Applicant لديه أكثر من application.
- Applicant قدم سابقاً على Campaign مغلقة.
- Applicant لا يملك CV بسبب failure أو form مختلف.
- HR يحاول فتح applicant من Scope آخر.
- ملاحظات متزامنة من أكثر من HR.

### سلامة البيانات

- Notes تحفظ actor/date/body/application_id أو applicant_id.
- لا حذف للملاحظات الحساسة بدون audit.
- لا تعديل application status من شاشة notes بدون action واضح.

### ملاحظات تنفيذية

يفضل أن تعرض القائمة:

- اسم المتقدم.
- Campaign/Vacancy.
- تاريخ التقديم.
- Status.
- Source.
- Tags مختصرة لاحقاً.
- Previous applicant indicator.
- CV availability.

### معايير القبول

- HR يستطيع تصفح المتقدمين ضمن Scope.
- HR يستطيع فتح Applicant Profile.
- CV يظهر/يتحمل فقط للمصرح.
- الملاحظات تحفظ وتظهر داخلياً.
- المتقدم السابق يتم تمييزه.

### أخطاء يجب تجنبها

- عدم عرض كل المتقدمين لكل HR بدون Scope.
- عدم جعل notes public.
- عدم تعديل statuses بشكل غير مضبوط من القائمة.
- عدم الاعتماد على frontend فقط لإخفاء CV.

### المخرجات والتسليم للمهمة التالية

واجهة مراجعة المتقدمين جاهزة لإضافة ATS filters وtags وshortlist.

## مهمة المرحلة الثانية 5 - فلاتر ATS والوسوم ومؤشرات المطابقة

المسؤول: مطور المرحلة الثانية 1  
الجهد التقديري: أربعة أيام  

### الهدف

توفير أدوات تساعد HR على فرز المتقدمين بسرعة عبر filters وtags ومؤشرات مطابقة explainable بدون تحويل النظام إلى قرار توظيف آلي غير مفهوم.

### نطاق التنفيذ

- Applicant filters.
- Tags/labels.
- Matching indicators.
- Basic scoring أو priority indicators إذا تم الاتفاق.
- Saved filters لاحقاً إذا توفر وقت.

### المطلوب تنفيذه

- Filter query layer.
- Tag model/actions.
- Matching rules service.
- UI indicators.
- Scope-safe filtering.

### قواعد العمل

- ATS يساعد HR ولا يتخذ القرار النهائي.
- كل indicator يجب أن يكون قابلاً للفهم.
- tags داخلية ولا تظهر للمتقدم.
- filters لا تتجاوز Scope.
- matching criteria يجب أن يعتمد على بيانات موجودة ومسموح استخدامها.

### الأمان والصلاحيات

- لا تعرض بيانات خارج scope عند استخدام filters.
- لا تعرض حقول حساسة في indicators إلا للمصرح.
- Tagging bulk action يحتاج permission.
- Export filtered applicants يحتاج permission منفصل لاحقاً.

### الحالات الاستثنائية

- Candidate بدون CV.
- Candidate answers ناقصة.
- Filter ينتج عدد كبير جداً.
- Tag محذوف أو renamed.
- HR يضيف tag مسيء أو غير مناسب.
- تغير form version يجعل بعض الحقول غير موجودة لكل applicants.

### سلامة البيانات

- Tags يجب أن تحفظ actor/date.
- Matching indicators لا يجب أن تعدل application status مباشرة.
- إذا تم تخزين score، يجب حفظ version أو criteria المستخدمة.

### ملاحظات تنفيذية

ابدأ بـ explainable indicators مثل:

- المدينة مطابقة.
- الخبرة المطلوبة متوفرة.
- المؤهل مطابق.
- لديه خبرة سابقة في المجال.
- قدم سابقاً.
- CV موجود أو ناقص.

### معايير القبول

- HR يستطيع فلترة المتقدمين حسب الحملة والحالة والمدينة والبيانات الأساسية.
- HR يستطيع إضافة وإزالة tags.
- indicators تظهر سبب المطابقة بشكل مفهوم.
- filters لا تكشف out-of-scope applicants.
- ATS لا يغير status تلقائياً بدون action.

### أخطاء يجب تجنبها

- عدم بناء black-box score غير مفهوم.
- عدم اتخاذ قرار رفض تلقائي اعتماداً على indicator.
- عدم استخدام filters تتجاوز الصلاحيات.
- عدم ربط indicators بحقول قد تكون غير موجودة بكل form versions بدون معالجة.

### المخرجات والتسليم للمهمة التالية

قائمة متقدمين قابلة للفرز وجاهزة للـ Shortlist وقرارات القبول/الرفض.

## مهمة المرحلة الثانية 6 - القائمة المختصرة وقرارات القبول والرفض وإيميل الاعتذار

المسؤول: مطور المرحلة الثانية 2  
الجهد التقديري: ثلاثة أيام  

### الهدف

تمكين HR أو المدير من اختيار المرشحين للـ Shortlist، تسجيل قرار القبول أو الرفض مع السبب، وإرسال إيميل اعتذار موحد عند الحاجة.

### نطاق التنفيذ

- Shortlist action.
- Accept/reject decision.
- Decision reason.
- Decision history.
- Bulk rejection email.
- Permission checks for HR/manager.

### المطلوب تنفيذه

- Decision action service.
- Shortlist status transition.
- Rejection reason list/config.
- Bulk email action.
- Audit events.
- UI buttons/actions.

### قواعد العمل

- المرشح ينتقل إلى Shortlisted فقط بقرار من مستخدم مصرح.
- الرفض يحتاج سبب أو ملاحظة حسب السياسة.
- Bulk rejection يرسل فقط للمتقدمين المحددين والذين حالتهم تسمح بذلك.
- إرسال الإيميل لا يغير القرار إذا فشل الإرسال.
- يمكن أن يكون القرار من HR أو manager حسب workflow.

### الأمان والصلاحيات

- Bulk actions تحتاج permission واضح.
- لا يجوز رفض متقدم خارج scope.
- الإيميل يجب ألا يحتوي معلومات داخلية أو أسباب حساسة.
- يجب منع CSRF في internal actions.

### الحالات الاستثنائية

- اختيار مرشح مرفوض سابقاً.
- إرسال اعتذار مرتين لنفس المرشح.
- فشل email queue.
- bulk selection يحتوي applicants من scopes مختلفة.
- مستخدم يحاول تغيير status مباشرة من request معدل.

### سلامة البيانات

- decision history يحفظ actor/date/reason/from_status/to_status.
- status transition validated.
- bulk action يجب أن تكون transaction أو partial-safe مع log واضح.

### ملاحظات تنفيذية

لا تجعل إرسال الإيميل هو القرار. القرار يحفظ في DB، والإيميل مجرد notification.

### معايير القبول

- HR/manager يستطيع عمل Shortlist حسب الصلاحية.
- الرفض يحتاج سبب.
- decision history يظهر في applicant/application profile.
- bulk rejection email يعمل للمحدد فقط.
- unauthorized bulk action مرفوض.

### أخطاء يجب تجنبها

- عدم تغيير status بدون history.
- عدم إرسال أسباب داخلية للمرشح.
- عدم السماح bulk action خارج scope.
- عدم اعتبار email failure سبباً لتخريب status.

### المخرجات والتسليم للمهمة التالية

مرشحون مختارون للمرحلة التالية وجاهزون للمقابلات.

## مهمة المرحلة الثانية 7 - جدولة المقابلات والتنبيهات

المسؤول: مطور المرحلة الثانية 1  
الجهد التقديري: أربعة أيام  

### الهدف

إدارة مواعيد المقابلات للمرشحين المختارين، مع تحديد نوع المقابلة والمشاركين وإرسال التنبيهات.

### نطاق التنفيذ

- Interview records.
- Interview type/reason.
- Date/time/location أو online link.
- Participants.
- Candidate notification/reminder.
- Calendar/list view.
- Reschedule/cancel basics.

### المطلوب تنفيذه

- Interview model.
- Interview participant model إذا لزم.
- Scheduling service.
- Reminder notification jobs.
- Interview list/calendar UI.
- Status transitions للمقابلة.

### قواعد العمل

- المقابلة ترتبط بـ Application.
- المرشح يمكن أن يملك أكثر من مقابلة.
- نوع المقابلة يوضح سببها: HR, technical, final.
- لا ترسل reminder لمقابلة cancelled.
- تغيير موعد المقابلة يجب أن يسجل audit.

### الأمان والصلاحيات

- HR المصرح فقط يستطيع إنشاء/تعديل المقابلات.
- المشاركون يرون المقابلات التي تخصهم أو ضمن scope.
- رابط meeting إن وجد لا يظهر لغير المصرح.
- الإيميل للمرشح لا يحتوي تقييمات داخلية.

### الحالات الاستثنائية

- موعد في الماضي.
- participant غير فعال.
- candidate rejected ثم محاولة جدولة مقابلة.
- reschedule بعد إرسال reminder.
- candidate يعتذر أو لا يحضر.
- timezone أو تنسيق التاريخ.

### سلامة البيانات

- Interview status محفوظ.
- Reschedule يحفظ history.
- Notifications after commit.
- لا حذف للمقابلات القديمة؛ استخدم cancelled.

### ملاحظات تنفيذية

Interview Status مقترح:

```text
Scheduled
Rescheduled
Cancelled
Completed
No Show
```

### معايير القبول

- HR يستطيع جدولة مقابلة لمرشح shortlisted.
- يمكن إضافة participants.
- candidate notification/reminder يرسل.
- المقابلات تظهر في list/calendar.
- multiple interviews مدعومة.

### أخطاء يجب تجنبها

- عدم جدولة مقابلة لمرشح rejected إلا إذا أعيد فتح قراره.
- عدم إرسال reminder قبل commit.
- عدم حذف مقابلة بدلاً من cancelled.
- عدم كشف تقييمات داخلية في تنبيه المرشح.

### المخرجات والتسليم للمهمة التالية

مقابلات مجدولة وجاهزة لتسجيل تقييمات المشاركين.

## مهمة المرحلة الثانية 8 - تقييم المقابلات

المسؤول: مطور المرحلة الثانية 2  
الجهد التقديري: ثلاثة أيام  

### الهدف

تمكين المشاركين في المقابلة من تسجيل آرائهم، الإيجابيات، السلبيات، الملاحظات، والتوصية النهائية الخاصة بهم.

### نطاق التنفيذ

- Evaluation form.
- Pros.
- Cons.
- Notes.
- Recommendation.
- Multiple reviewer inputs.
- Evaluation history.

### المطلوب تنفيذه

- Interview evaluation model.
- Evaluation UI.
- Authorization for participant/reviewer.
- Aggregated view for HR.
- Audit events.

### قواعد العمل

- المشارك المصرح فقط يستطيع تقييم المقابلة.
- كل reviewer له رأيه الخاص.
- توصية reviewer ليست final hiring decision.
- يمكن إغلاق التقييم بعد completion حسب السياسة.

### الأمان والصلاحيات

- التقييمات داخلية ولا تظهر للمرشح.
- reviewer لا يرى تقييمات خارج نطاقه إلا إذا له صلاحية HR.
- منع تعديل تقييم شخص آخر.
- الحقول الحساسة لا تظهر لمستخدم غير مصرح.

### الحالات الاستثنائية

- reviewer يحاول التقييم قبل موعد المقابلة.
- reviewer غير مشارك يحاول التقييم.
- مقابلة cancelled.
- أكثر من reviewer يكتبون بنفس الوقت.
- HR يحتاج تعديل خطأ بتقييم سابق.

### سلامة البيانات

- Evaluation ترتبط بـ Interview وReviewer.
- تعديل evaluation يسجل updated_by/history أو audit.
- لا حذف للتقييمات بدون audit.

### ملاحظات تنفيذية

Recommendation values ممكن تكون:

```text
Strong Yes
Yes
Maybe
No
Strong No
```

يمكن تعريبها في الواجهة لاحقاً.

### معايير القبول

- participant يستطيع إضافة evaluation.
- HR المصرح يرى evaluations.
- لا يمكن لمستخدم تعديل تقييم شخص آخر بدون صلاحية.
- pros/cons/notes/recommendation محفوظة.
- final decision يبقى منفصل عن reviewer recommendation.

### أخطاء يجب تجنبها

- عدم خلط recommendation الفردية مع القرار النهائي.
- عدم عرض تقييمات للمرشح.
- عدم السماح بتقييم مقابلة ملغاة.
- عدم فقدان history عند تعديل تقييم.

### المخرجات والتسليم للمهمة التالية

تقييمات المقابلات جاهزة لاختيار المرشح النهائي وإنشاء Job Offer.

## مهمة المرحلة الثانية 9 - سير عمل عرض العمل

المسؤول: مطور المرحلة الثانية 1  
الجهد التقديري: أربعة أيام  

### الهدف

إنشاء وإدارة Job Offer للمرشح المختار، مع تتبع حالة العرض والتعامل مع القبول أو الرفض أو انتهاء الصلاحية.

### نطاق التنفيذ

- Create offer from selected application.
- Salary.
- Start date.
- Probation period.
- Contract details.
- Offer status.
- Offer version/history.
- Accepted/rejected/expired behavior.

### المطلوب تنفيذه

- Job offer model.
- Offer creation service.
- Offer status transitions.
- Offer view/document أو shareable internal view.
- Revised offer handling.
- Audit events.

### قواعد العمل

- Offer ينشأ فقط لمرشح selected.
- لا يمكن وجود أكثر من active offer لنفس application إلا بسياسة واضحة.
- rejected offer لا يغلق vacancy تلقائياً.
- يمكن إرسال revised offer أو اختيار مرشح آخر.
- accepted offer يشغل تحويل المرشح إلى employee/contract.

### الأمان والصلاحيات

- إنشاء offer يحتاج permission واضح.
- Salary وcontract details بيانات حساسة.
- لا تعرض offer لمستخدم خارج scope.
- إذا كان هناك public acceptance لاحقاً، يحتاج token منفصل وآمن، وليس application ID.

### الحالات الاستثنائية

- المرشح selected ثم تم رفضه قبل إرسال offer.
- HR يعدل salary بعد إنشاء offer.
- offer expires.
- candidate rejects offer.
- candidate accepts offer مرتين أو الرابط يعاد استخدامه.
- vacancy امتلأت قبل قبول offer.

### سلامة البيانات

- Offer status transitions مضبوطة.
- Offer history محفوظ.
- Accepted offer لا يتحول مرتين.
- salary/start date/contract details تحفظ كsnapshot.

### ملاحظات تنفيذية

Offer Status مقترح:

```text
Draft
Sent
Accepted
Rejected
Expired
Cancelled
Revised
```

### معايير القبول

- HR يستطيع إنشاء offer لمرشح selected.
- offer يحتوي salary/start date/probation/contract details.
- status tracking يعمل.
- rejected/expired offer لا يغلق vacancy تلقائياً.
- accepted offer يجهز عملية التحويل.

### أخطاء يجب تجنبها

- عدم إنشاء offer لمرشح غير selected.
- عدم تعديل offer sent بدون version/history.
- عدم كشف salary لغير المصرح.
- عدم تحويل offer accepted أكثر من مرة.

### المخرجات والتسليم للمهمة التالية

Offer مقبول يمكن تحويله إلى Employee وContract داخل نظام HR.

## مهمة المرحلة الثانية 10 - تحويل العرض المقبول إلى موظف وعقد

المسؤول: مطور المرحلة الثانية 2  
الجهد التقديري: أربعة أيام  

### الهدف

عند قبول العرض، يتم إنشاء أو ربط Employee داخل نظام HR الحالي وإنشاء Contract بناءً على بيانات العرض المقبول.

### نطاق التنفيذ

- Create/link employee.
- Create contract.
- Link application/offer to employee.
- Update vacancy/campaign status.
- Audit conversion.
- Prevent double conversion.

### المطلوب تنفيذه

- Hiring conversion service.
- Employee creation/linking logic.
- Contract creation logic using existing HR contract tables.
- Vacancy fulfillment logic.
- Transactional conversion.

### قواعد العمل

- Accepted offer فقط يمكن تحويله.
- التحويل ينفذ مرة واحدة فقط.
- Contract يأخذ بياناته من accepted offer snapshot.
- Vacancy تغلق أو تنقص حسب العدد المطلوب والمقبولين.
- إذا vacancy تحتاج أكثر من شخص، لا تغلق حتى يكتمل العدد حسب policy.

### الأمان والصلاحيات

- Conversion عملية حساسة تحتاج permission واضح وربما approval حسب السياسة.
- لا يمكن لمستخدم خارج scope إنشاء employee/contract.
- audit required.

### الحالات الاستثنائية

- employee موجود سابقاً بنفس email/phone.
- contract creation fails بعد employee creation.
- offer accepted لكن vacancy closed.
- position inactive.
- capacity ممتلئة.
- retry بعد timeout.

### سلامة البيانات

- transaction تشمل employee/link + contract + application/offer/vacancy updates.
- row locks عند الحاجة على offer/vacancy/position.
- idempotency لمنع conversion مرتين.
- لا يتم إنشاء contract بدون employee.

### ملاحظات تنفيذية

ترتيب آمن:

```text
Begin transaction
  Lock offer
  Verify accepted and not converted
  Lock vacancy/position
  Revalidate capacity and position state
  Create/link employee
  Create contract
  Update application/offer/vacancy
  Audit conversion
Commit
```

### معايير القبول

- accepted offer يحول إلى employee/link.
- contract ينشأ داخل HR system الحالي.
- vacancy status يحدث بشكل صحيح.
- conversion لا يعمل مرتين.
- failure لا يترك employee بدون contract إلا بسياسة recovery واضحة.

### أخطاء يجب تجنبها

- عدم إنشاء employee ثم فشل contract بدون transaction/recovery.
- عدم استخدام live mutable offer بدلاً من accepted snapshot.
- عدم إغلاق vacancy قبل التأكد من capacity/hiring need.
- عدم السماح conversion من offer غير accepted.

### المخرجات والتسليم للمهمة التالية

مرشح مقبول أصبح موظفاً بعقد داخل نظام HR، ودورة التوظيف اكتملت.

## مهمة المرحلة الثانية 11 - الخصوصية والاحتفاظ والتصدير والمراقبة

المسؤول: مطور المرحلة الثانية 1 ومطور المرحلة الثانية 2  
الجهد التقديري: ثلاثة أيام  

### الهدف

إضافة الضوابط الأساسية لحوكمة بيانات المتقدمين: الخصوصية، الاحتفاظ، التصدير، المراقبة، وتدقيق العمليات الحساسة.

### نطاق التنفيذ

- Privacy notice version placeholder.
- Consent capture إذا مطلوب.
- Retention policy fields/placeholders.
- Export permission.
- Sensitive download/export audit.
- Monitoring events.
- Basic admin/reporting controls.

### المطلوب تنفيذه

- Privacy notice version field.
- Consent timestamp/version إذا مطلوب.
- Export action with permission.
- Audit for export/download/bulk actions.
- Monitoring/log events for failures and abuse.
- Retention/anonymization placeholders.

### قواعد العمل

- لا يجب الاحتفاظ ببيانات المتقدمين للأبد بدون policy.
- يمكن الاحتفاظ بإحصائيات مجمعة بدون PII.
- export all candidates عملية حساسة.
- deletion/anonymization يجب أن يكون بسياسة واضحة وليس حذف عشوائي.

### الأمان والصلاحيات

- Export يحتاج permission منفصل.
- Export يجب أن يحترم scope.
- Sensitive actions تسجل audit.
- يمكن لاحقاً طلب re-authentication للعمليات الحساسة.

### الحالات الاستثنائية

- HR يحاول export خارج scope.
- export كبير جداً.
- download متكرر بشكل غير طبيعي.
- candidate يطلب حذف/سحب بياناته.
- retention period انتهى.
- monitoring يكشف spam أو abuse على public endpoint.

### سلامة البيانات

- لا تحذف candidate بطريقة تكسر application history.
- anonymization يجب أن يحافظ على التقارير المجمعة عند الحاجة.
- export log يحفظ actor/date/scope/filter/count.

### ملاحظات تنفيذية

Monitoring events مهمة:

- failed submissions.
- upload failures.
- virus/scan failures.
- rate limit hits.
- unauthorized access attempts.
- large exports.
- repeated downloads.
- public endpoint errors.

### معايير القبول

- privacy notice version يمكن حفظها.
- export يحتاج صلاحية.
- downloads/exports تسجل audit.
- monitoring points موجودة للأحداث المهمة.
- retention/anonymization policy placeholders جاهزة.

### أخطاء يجب تجنبها

- عدم إعطاء export لكل HR تلقائياً.
- عدم حذف PII بطريقة تكسر التاريخ.
- عدم الاحتفاظ بCVs للأبد بدون policy.
- عدم تجاهل public abuse monitoring.


## 12. مصفوفة الأمان والحالات الاستثنائية

| المجال | الخطر | الإجراء المطلوب |
|---|---|---|
| الرابط العام | تخمين أو تعداد الروابط | Token عشوائي غير قابل للتخمين، رسائل عامة، Rate Limiting |
| رابط الدعوة الداخلي | شخص غير مدعو يحصل على الرابط ويحاول التقديم | Invitation-only mode، التحقق من الإيميل المدخل مقابل قائمة المدعوين، وعدم كشف قائمة الإيميلات |
| البيانات العامة | تسريب بيانات داخلية | مخرجات Public-safe، بدون IDs داخلية أو تعليقات أو Workflow |
| Workflow الطلب | موافقات قديمة على بيانات تغيرت | Request Versions وربط الموافقة بالنسخة |
| الموافقات | موافقة غير مصرح بها | التحقق من Role + Scope + Current Step |
| الطلبات المكررة | Race Condition | Transactions وLocking وConstraints ومنع التكرار |
| ما بعد الموافقة | تحديث جزئي غير مكتمل | عملية Transactional مع Final Revalidation |
| إعلان الوظيفة | تغير تاريخي غير مقصود | Published Snapshot / Version |
| نموذج التقديم | تغير معنى الإجابات القديمة | Form Versioning |
| التقديم العام | حقول معدلة أو مزورة | Whitelist وحقول موثوقة مشتقة من السيرفر |
| رفع الملفات | ملفات خبيثة أو قابلة للتنفيذ | Allow-list وSignature Validation وتخزين Private |
| بيانات المتقدمين | تسريب خارج النطاق | Object-level Authorization |
| التنبيهات | عدم اتساق مع Workflow | الإرسال بعد Commit والـ DB هي مصدر الحقيقة |
| التصدير والتحميل | تسريب بيانات | صلاحيات واضحة وAudit |
| الاحتفاظ بالبيانات | الاحتفاظ ببيانات شخصية للأبد | سياسة Retention/Anonymization |
| التزامن | تكرار العمليات | Row Locks ومنع التكرار وUnique Constraints |
| المراقبة | إساءة استخدام أو فشل بدون ملاحظة | تسجيل فشل التقديم، Rate Limit Hits، وفشل الرفع |

## جدول التنفيذ المتوازي لفريق من 4 مطورين

| اليوم | التاريخ | مطور المرحلة الأولى 1 | مطور المرحلة الأولى 2 | مطور المرحلة الثانية 1 | مطور المرحلة الثانية 2 | التكامل / الملاحظات |
|---:|---|---|---|---|---|---|
| 1 | 2026-09-14 | ERD  + تحديد الجداول الأساسية + علاقات HR الموجودة | المهمة 1: حالات الطلب، أنواع الطلبات، Workflow Rules + Permission/Scope Matrix | تصميم Applicant/Application + Invited Applicants model وعلاقته بـ Campaign/Form | تصميم Applicant screens + ATS/Shortlist flow مبدئي | نهاية اليوم يجب تثبيت الـ domain المشترك بين الفريقين |
| 2 | 2026-09-15 | Migrations وModels للطلبات، النسخ، Vacancy، Campaign | Permission/Scope Resolver + بداية واجهات Request/Create/Details | Migrations/Models للـ Applicant/Application/CV metadata skeleton | UI shell لقائمة المتقدمين والملف الشخصي | اتفاق نهائي على مفاتيح الربط: Campaign, Form Version, Public Link |
| 3 | 2026-09-16 | Request Draft/Edit/Submit + Request Versioning + Duplicate Submit Protection | Workflow Instance + Approver Resolution + Pending Approvals UI | Public Submission service skeleton ينتظر Public Link النهائي | Applicant List/Profile UI مربوطة ببيانات تجريبية/seed | تكامل submit مع workflow، وتجهيز نقطة ربط public submission |
| 4 | 2026-09-17 | Approved Business Actions: Create Vacancy / Update Headcount / Create Position MVP | Approval Actions: approve/reject/request changes + timeline الأساسي | Applicant/Application save + duplicate/idempotency + invitation email validation | Previous applicant indicator + Notes MVP | نهاية اليوم يجب أن يعمل request-to-approval-to-vacancy path |
| 5 | 2026-09-18 | Hiring Campaign + Campaign state checks | Job Post draft/publish + Application Form version + Public Link token + invitation-only mode | ربط Public Submission مع Campaign/Form/Public Link + Invite list | Applicant UI يقرأ بيانات حقيقية من submission | نهاية الأسبوع الأول: Phase 1 core + بداية Phase 2 submission path |
| عطلة | 2026-09-19 | لا يوجد عمل | لا يوجد عمل | لا يوجد عمل | لا يوجد عمل | عطلة |
| عطلة | 2026-09-20 | لا يوجد عمل | لا يوجد عمل | لا يوجد عمل | لا يوجد عمل | عطلة |
| 6 | 2026-09-21 | إصلاحات Phase 1 + Audit للأفعال الأساسية | Public Link/Public Page hardening + Campaign close/expire checks | CV Upload Security + Private Storage + Authorized Download | Applicant Profile + CV display/download حسب الصلاحية | بداية Phase 2 الفعلية على بيانات حقيقية |
| 7 | 2026-09-22 | دعم تكامل Public Submit + معالجة edge cases | واجهات HR للـ Campaign/Job Post/Form polish | Shortlist/Decision backend + rejection reason | ATS Filters + Tags + Matching Indicators MVP | HR يستطيع مراجعة المتقدمين وتصنيفهم |
| 8 | 2026-09-23 | Phase 1 regression + Permission fixes | Notifications + timeline polish + feedback setup | Bulk rejection email MVP + Applicant status transitions | Shortlist UI + Interview model/UI skeleton | انتقال من Applicant Review إلى Shortlist والقرار |
| 9 | 2026-09-24 | دعم Interview/Offer integration مع Vacancy/Application | End-to-end Phase 1 QA + deployment preparation | Interview Scheduling + reminders MVP + Job Offer backend MVP | Interview Evaluation MVP + Job Offer UI MVP | المقابلات والعروض تكون نسخة أولية قابلة للتجريب |
| 10 | 2026-09-25 | Final backend fixes + migration/seed check | End-to-end QA + staging/online deployment support | Accepted Offer to Employee/Contract skeleton + fixes | Phase 2 QA + feedback board + demo data | تسليم MVP مترابط للمرحلتين على staging/online |

</div>













