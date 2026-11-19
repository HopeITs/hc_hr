<div dir="rtl" align="right">

# قصص الاجتماع: الفحص والـ Prompts — HC-HR

تاريخ الفحص: 2026-11-19 · الفرع: `feature/attendance-purge-uploaded` · الفحص قراءة كود فقط (لم تُشغَّل الاختبارات).

## القرارات المعتمدة (من الأسئلة)

| البند | القرار |
|---|---|
| البروفايل | تعديل مباشر لكل الأقسام ما عدا العقود والهوية + إشعار HR بوصف التعديل (قبل/بعد) |
| بديل الإجازة | فلتر "متاح" (لا إجازة معتمدة ولا معلّقة ولا عطلة بنفس التواريخ) + المدير يغيّر البديل من نافذة القرار + إشعار لمقدّم الطلب |
| العقد التجريبي | التمديد = تعديل تاريخ النهاية عبر ملحق العقد؛ التثبيت = عقد جديد دائم؛ الإلغاء = إنهاء بسبب |
| Permissions Library | سجل استحقاقات (entitlements) مرتبط بالمنصب + قائمة تحقق للتنفيذ |
| اقتراح البدلاء | يُحسم في Prompt 6 (انظر "أسئلة مفتوحة") |
| مسيرة المتطوع (Onboarding) | السجل ← العقد، ثم **كل الخطوات بالتوازي**: إيميل العمل (حساب الدخول)، البطاقة، التسليم (إلكترونيات + Assets)، جلسة الـ HR، جلسة المدير، إقرار السياسات. المهل من بداية العقد: **البطاقة والإلكترونيات (لابتوب، ماوس، إيميل العمل، توقيع الإيميل...) خلال يومين**، والجلستان **خلال 14 يوماً**؛ المسيرة لا تكتمل إلا بها كلها. **الـ Mug** خارج المسيرة: يُسلَّم بعد انتهاء العقد التجريبي وبدء عقد حقيقي |

---

## 1) الوضع الحالي

### 1. الإجازات

| المطلوب | الحالة | الدليل |
|---|---|---|
| إظهار الرصيد المتبقي عند الطلب | ✅ موجود | `MyLeaveRequestModule::leaveChoices` يعرض `left` لكل نوع؛ `LeaveRequestPricing` يحسب `projected` |
| التنبيه عند التجاوز | ✅ موجود (جزئياً) | `LeaveQuote::EXCEEDS_BALANCE`: **يمنع** في الاعتيادية ويحذّر في باقي الأنواع (قرار D-6). يحتاج تحقق بصري من ظهور التحذير في الحوار |
| اختيار البديل حسب القسم/البرنامج/المدينة | ✅ موجود | `LeaveAlternativeEmployees` (حسب الدرجة grade + المدينة + البرنامج/القسم) + تحقق في `LeaveRequestForm` |
| البديل من "المتاحين" فقط | ❌ غير موجود | لا فلتر إجازة/غياب في `LeaveAlternativeEmployees` |
| المدير يغيّر البديل عند القرار | ❌ غير موجود | نافذة القرار تعرض البديل للقراءة فقط (`all.blade.php:387`)؛ `LeaveRequestForm::submitDecision` لا يكتبه |
| إشعار مقدّم الطلب عند تغيير البديل | ❌ غير موجود | لا Notification للبديل في `app/Notifications` |

### 2. بروفايل المتطوع

| المطلوب | الحالة | الدليل |
|---|---|---|
| تعديل المتطوع لبياناته | 🟡 جزئي | مباشر: `personal-contact` و`skills` فقط (`EmployeeProfile::canEditSection`). التعليم/الدورات/الخبرات/العائلة/الصورة: **طلب بموافقة HR** (`ProfileChangeRequests`) |
| استثناء العقود والهوية | ✅ | "Pay and identity never pass through here" |
| إشعار HR بالتعديل ووصفه | ❌ غير موجود | HR يرى الطلب المعلّق في الـ Inbox فقط (`WorkInbox`)؛ لا إشعار عند التقديم؛ التعديل المباشر لا يُشعِر أحداً (فقط `LogsActivity`) |

### 3. عيد الميلاد

| المطلوب | الحالة | الدليل |
|---|---|---|
| إجازة تلقائية يوم العيد | ✅ | `BirthdayHoliday::datesFor` + عطلة من نوع birthday |
| استثناء Service Agreement | ✅ | `isServiceAgreement` (H-5) |
| عيد ضمن العطلة الأسبوعية: المتطوع يختار يوماً في نفس الأسبوع | ✅ منطق / ❌ إشعار | `choose()` يتحقق من نفس أسبوع ISO ويوم عمل؛ `BirthdayHolidayCard` للواجهة. **لا يوجد إشعار للمتطوع** ليختار |
| إرسال الاختيار للمدير المباشر والـ HR، الموافقة للمدير | 🟡 | `canDecide` للمدير المباشر فقط ✅؛ لا إشعار للمدير ولا HR (يظهر عند المدير في `pendingFor`) |
| علامة "إجازة" على اليوم | يحتاج تحقق | يُحتسب يوم عطلة؛ تأكد أنه يظهر في تقويم الفريق ولوحة الحضور كإجازة |

### 4. العقود التجريبية

| المطلوب | الحالة | الدليل |
|---|---|---|
| تمييز العقد التجريبي | ✅ | `Contract::isTrial()` (النوع 3، `config/contracts.php`) |
| إشعار عند بداية الثلث الأخير (متطوع+مدير+HR) | ❌ | `SendContractExpirationWarnings` و`RenewalJourneys` **يستثنيان التجريبي** صراحةً |
| قرار المدير: تمديد / تثبيت / إلغاء + السبب | ❌ | لا شيء |
| HR يستلم القرار كـ Seen | ❌ | يوجد نمط جاهز: `HRSawLeaveRequest` |
| قاعدة قائمة ذات صلة | ✅ | سياسة 3 أيام إجازة في التجريبي (`PROBATION_FREE_DAYS`) |

### 5. Permissions Library

| المطلوب | الحالة |
|---|---|
| صلاحيات مهنية حسب المنصب، تُسند افتراضياً عند التوظيف | ❌ غير موجود |
| المدير/HR يضيف أو يزيل عنصراً | ❌ |
| أساس يمكن البناء عليه | ✅ Spatie + `AccessManagement` + `Position` + `JobLevel` + قوائم البريد (`MailList` مع قواعد) + `OnboardingJourney`/`OffboardingJourney` + `AccessRevoker` |

ملاحظة: عناصر المكتبة (Telegram، كاميرات، قواعد بيانات، صيانة IT) **خارج النظام**، فهي سجل استحقاقات يتتبّعه النظام لا صلاحيات يفرضها.

### 6. Critical Positions

| المطلوب | الحالة |
|---|---|
| وسم منصب حرج | ❌ (لا عمود ولا شارة؛ ورد في ملاحظات اجتماع 17-18/11 كغير موجود) |
| اقتراح بدلاء: CVs قديمة | ❌ المكان فقط الآن. أساس: `RecruitmentApplicant`/`RecruitmentApplication` + `RecruitmentFinalistService` (وصلوا المراحل الأخيرة) |
| اقتراح من داخل المؤسسة بالـ AI | ❌ أساس: `Evaluation` model + `MatchingIndicator` + `config/assistant.php` (مزوّدون خارجيون: Groq/Gemini/...) |

### 7. مسيرة المتطوع (Onboarding)

المسيرة الحالية (`OnboardingJourneys`، جدول `onboarding_journeys`): `record → contract → account → card → orientation`، تبدأ مع أول عقد حقيقي.

| المطلوب | الحالة |
|---|---|
| Orientation من الـ HR | ✅ جلسة واحدة: يحدد HR موعدها ويسجّلها بعد حدوثها، مهلة 14 يوماً، تذكير `OrientationOverdue`، تظهر في الـ Inbox |
| Orientation من المدير (البرامج، آلية العمل) | ❌ الجلسة الوحيدة مالكها HR |
| تسليم الإلكترونيات (إيميل، لابتوب، ماوس) | 🟡 `Checklist`/`ChecklistItem`/`DefaultDeliverable`/`ChecklistWorkflow` (Email، Laptop، Mobile، SIM)؛ **لا بند Mouse**، و**القائمة غير مربوطة بالمسيرة**. الإيميل = إيميل حساب الدخول (خطوة account) |
| تسليم Assets (ID، Mug، Signature) | 🟡 بنود في نفس القائمة (ID Cartoon/Plastic، Mug، Email Signature، Web photo) + تسليم البطاقة المطبوعة في المسيرة؛ غير محسوبة في اكتمال المسيرة |
| إقرار قراءة Policies + Media guideline | ❌ `PolicyDocument` (مزامنة من Drive عبر `policies:sync`) يقرؤه المساعد الذكي فقط؛ لا صفحة قراءة ولا إقرار |

---

## 2) أسئلة مفتوحة (تؤثر على Prompt 6 وبعض التفاصيل)

1. **AI والخصوصية**: الـ providers خارجية. هل يُسمح بإرسال ملخص مجهّل (مؤهلات/تقييم بلا اسم) أم الترشيح داخلي بالقواعد فقط؟ (القيمة الافتراضية في Prompt 6: قواعد داخلية + طبقة AI اختيارية معطّلة.)
2. **"الثلث الأخير"**: من تاريخ البداية إلى النهاية (مثلاً 90 يوماً → يبدأ اليوم 61). هل يُرسل تذكير ثانٍ إذا لم يقرّر المدير؟ (الافتراضي: كل 7 أيام حتى القرار.)
3. **تقييم "سيئ"** للمنصب الحرج: من أين يُقرأ؟ جدول `Evaluation` الحالي أم لا يوجد نظام أداء بعد (ورد في الملاحظات كغير موجود)؟
4. **نشر التعديلات على البروفايل**: هل يرى HR الوصف في الإشعار فقط، أم نحتاج أيضاً صفحة سجل "تعديلات المتطوعين"؟

---

## 3) ترتيب التنفيذ وتجنّب التعارض بين المطوّرين

سبعة مسارات مستقلة تقريباً. نقاط التماس:
- المسارات 1 و3 و4 و7 تُضيف **Notification classes** جديدة وتسجّلها في `app/Enums/NotificationCategory.php` → **ملف مشترك**: كل مطوّر يضيف سطره فقط، والدمج يدوي بسيط.
- المسار 4 يستعمل `ContractAmendmentService` (لا يعدّله).
- المسار 5 و6 يضيفان عموداً/جدولاً جديداً على `positions`: **استخدموا ملفات migration بأسماء مختلفة وتواريخ مختلفة**.
- المسار 7 يعدّل `OnboardingJourneys` وجدول `onboarding_journeys` (بـ migration جديدة)، والمسار 5 يربط بـ `Onboarding*` فقط: **من يُدمج ثانياً يراجع `steps()` و`settleCompletion()`**.
- كل مسار على فرع خاص: `feature/<name>`، ويُدمج في `main` بعد `composer test` و`./vendor/bin/pint`.

| # | المسار | الفرع المقترح | يلمس |
|---|---|---|---|
| 1 | الإجازات: بديل متاح + تغيير المدير + إشعار | `feature/leave-alternative-availability` | `LeaveAlternativeEmployees`, `LeaveRequestForm`, `AllLeaveRequestModule`, `all.blade.php`, Notification جديد |
| 2 | البروفايل: تعديل مباشر + إشعار HR | `feature/profile-direct-edit-notify` | `EmployeeProfile`, `ProfileChangeRequests`, Notification جديد |
| 3 | عيد الميلاد: إشعارات | `feature/birthday-holiday-notifications` | `BirthdayHoliday`, `BirthdayHolidayCard`, command جديد |
| 4 | العقد التجريبي: ثلث أخير + قرار | `feature/probation-review` | command جديد, service جديد, Livewire جديد |
| 5 | Permissions Library | `feature/permissions-library` | جداول جديدة, Livewire جديد, `Onboarding*` (ربط فقط) |
| 6 | Critical Positions + اقتراح بدلاء | `feature/critical-positions` | `positions` (عمود), org chart, service جديد |
| 7 | مسيرة المتطوع: جلسة المدير + التسليم + إقرار السياسات | `feature/onboarding-journey-extended` | `OnboardingJourneys`, `onboarding_journeys` (أعمدة), `ChecklistWorkflow` (قراءة), `PolicyDocument`, جدول إقرارات جديد, Inbox, Notification جديد |

---

## 4) الـ Prompts

عنصر مشترك يُلصق في أول كل prompt:

```
سياق: مشروع HC-HR (Laravel 13 / Livewire 4 / Flux / Tailwind 4). اقرأ CLAUDE.md أولاً والتزم بها: ثنائية اللغة EN/AR مع RTL (أضف مفاتيح الترجمة للغتين)، الأعمدة المترجمة بنمط {attr}_{locale}، لا ألوان خارج الهوية (استخدم <x-status>, <x-tag>, <x-button>, <x-card>)، لا تكتب dark: مزدوجة، نطاق المدن والأدوار (Roster/Policies/inCity) أمني فلا تتجاوزه. اعمل على فرعك فقط، لا تلمس ملفات خارج نطاق المهمة، ولا تعدّل migration موجودة. اكتب اختبارات Feature لكل سلوك جديد (sqlite :memory:)، ثم شغّل `php artisan test --filter=...` و`./vendor/bin/pint`. في النهاية اذكر بدقة ما اختُبر وما لم يُختبر.
```

### Prompt 1 — الإجازات: البديل المتاح وتغيير المدير

```
[الصق السياق المشترك]

المهمة: تحسين "المتطوع البديل" في طلب الإجازة. الفرع: feature/leave-alternative-availability.

الموجود: app/Services/LeaveAlternativeEmployees.php (مصدر الحقيقة للقائمة والتحقق)، LeaveRequestForm (التحقق Rule::in)، AllLeaveRequestModule + resources/views/livewire/employee/leave/all.blade.php (نافذة القرار تعرض البديل للقراءة فقط)، LeaveNotificationService، Notifications: NewLeaveRequest/ActionLeaveRequest.

المطلوب:
1) فلتر "متاح" داخل LeaveAlternativeEmployees: استبعد من لديه إجازة معتمدة أو معلّقة تتقاطع مع فترة الطلب، أو عطلة/إجازة ميلاد معتمدة (استعمل BirthdayHoliday/Holiday وTeamCoverage إن لزم، دون تكرار المنطق). غيّر التوقيع بتمرير الفترة (اختيارياً) بحيث تبقى المناداة الحالية تعمل. فترة الطلب متغيّرة في الواجهة: أعد جلب القائمة عند تغيّر التواريخ (wire:model.live) وأعد التحقق عند الإرسال بنفس الفترة (لا يُقبل id غير ظاهر في القائمة).
2) المدير (من يملك policy decide) يستطيع تغيير البديل من نافذة القرار: حقل اختيار من نفس القائمة (نفس القاعدة والفلتر)، يُكتب داخل submitDecision تحت نفس القفل/المعاملة، ويُسجَّل في activity log (القديم والجديد).
3) عند تغيير البديل: Notification جديد (LeaveAlternativeChanged) لمقدّم الطلب مع اسم البديل الجديد والقديم؛ سجّله في NotificationCategory، ولا يُرسل إذا لم يتغيّر. أرسله بعد الـ commit.
4) تحقق بصرياً (preview) أن تحذير تجاوز الرصيد يظهر في حوار الطلب لغير الاعتيادية ولا يظهر بلا داعٍ، وأصلح فقط إن وجدت خللاً حقيقياً.
اختبارات: توسيع LeaveAlternativeEmployeesTest ومسار القرار (LeaveRequestWorkflowTest كنمط)، بما فيها: متعارض يُستبعد، مدير من مدينة أخرى مرفوض، عدم إشعار عند عدم التغيير.
```

### Prompt 2 — البروفايل: تعديل مباشر وإشعار HR

```
[الصق السياق المشترك]

المهمة: تمكين المتطوع من تعديل كل بياناته عدا العقود والهوية، مع إشعار HR بوصف التعديل. الفرع: feature/profile-direct-edit-notify.

الموجود: app/Livewire/Employee/EmployeeProfile.php (SECTIONS: personal-contact وskills مباشر؛ work-contact لـ HR)، app/Services/ProfileChangeRequests.php (التعليم/الدورات/الخبرات/العائلة/الصورة بموافقة HR)، ProfileChangeDecided، WorkInbox، حقول Employee المشفّرة (اقرأ عبر الـ casts).

قرار المنتج: تعديل مباشر + إشعار، بلا موافقة. أي قسم هوية أو عقد أو راتب يبقى محظوراً (راجع تعليق "P (identity...)" أعلى EmployeeProfile لتحديد ما يُعدّ هوية بالضبط قبل التوسيع، ولا توسّع حقلاً حساساً دون ذكره لي).

المطلوب:
1) ProfileChangeRequests: أضف مساراً مباشراً لأقسام التعليم/الدورات/الخبرات/العائلة/الصورة يكتب في جداولها في معاملة واحدة (أعد استعمال apply()؛ الصورة تُعالج عبر SecureImageUploadService كما الآن، ولا تُعاد ترميزها). ما كان معلّقاً (pending) من الطلبات القديمة يبقى قابلاً للبتّ أو يُحوَّل بأمر artisan واحد موثّق، اختر الأبسط واشرح.
2) وسّع الأقسام المباشرة لتشمل بقية البيانات غير المحظورة التي يعرضها البروفايل، بنفس قواعد التحقق (PersonalInfoForm).
3) كل حفظ فعلي (isDirty) يولّد وصفاً مقروءاً (الحقل: قبل ← بعد) دون كشف قيم حساسة مشفّرة كاملة في الإشعار (اعرض اسم الحقل وأن القيمة تغيّرت إن كان حساساً). أرسل Notification (ProfileUpdatedByEmployee) لـ HR نطاق مدينة الموظف (استعمل نفس نطاق EmployeePolicy::update ومن يحمل دور HR لتلك المدينة، لا كل الـ HR) مع رابط البروفايل. سجّله في NotificationCategory. دمج عدة تعديلات خلال الحوار الواحد في إشعار واحد.
4) لا تحذف جدول/نموذج ProfileChangeRequest الآن.
اختبارات: مسار مباشر لكل قسم، عدم قدرة متطوع على تعديل متطوع آخر، منع حقول الهوية/العقد، وصول الإشعار لـ HR الصحيح فقط، عدم إرسال شيء عند عدم وجود تغيير (راجع EmployeeProfileD1Test وProfileChangeRequestsTest كنمط، وحدّثهما بما يتوافق مع القرار الجديد).
```

### Prompt 3 — عيد الميلاد: إشعارات الاختيار والموافقة

```
[الصق السياق المشترك]

المهمة: إكمال إشعارات إجازة عيد الميلاد. الفرع: feature/birthday-holiday-notifications.

الموجود: app/Services/BirthdayHoliday.php (المنطق كامل: occurrencesFor, choose, decide, pendingFor, استثناء Service Agreement)، BirthdayHolidayChoice، Livewire BirthdayHolidayCard، نمط الأوامر المجدولة في routes/console.php ونمط NotificationDeliveryLedger لمنع التكرار. لا تغيّر قواعد الاحتساب.

المطلوب:
1) أمر مجدول يومي (birthday:notify-conflicts) يجد المتطوعين الذين سيقع عيد ميلادهم ضمن العطلة الأسبوعية/عطلة رسمية خلال الأيام القادمة (مثلاً 14 يوماً قابلة للضبط في config) ولم يختاروا بعد، ويرسل لهم Notification (BirthdayChoiceNeeded) يطلب اختيار يوم من نفس الأسبوع، برابط البطاقة. مرة واحدة لكل (موظف، سنة، عطلة) عبر NotificationDeliveryLedger. لا يُرسل لعقد Service Agreement.
2) عند choose(): Notification للمدير المباشر (BirthdayChoiceSubmitted) للموافقة/الرفض ونسخة معلوماتية لـ HR نطاق المدينة (Seen، دون صلاحية قرار). القرار يبقى للمدير المباشر فقط (canDecide).
3) عند decide(): Notification للمتطوع بالنتيجة (مع السبب إن وُجد حقل مناسب أو بدونه).
4) تحقق أن اليوم العادي (غير المتعارض) يظهر كـ "إجازة" في تقويم الفريق ولوحة اليوم (Team\Calendar, CalendarFeed, Presence) وأصلح إن لم يظهر.
اختبارات: توسيع BirthdayHolidayTest: عدم تكرار الإشعار، Service Agreement لا يتلقى، نطاق المستلمين، رفض من غير المدير المباشر.
```

### Prompt 4 — العقود التجريبية: الثلث الأخير وقرار المدير

```
[الصق السياق المشترك]

المهمة: مراجعة العقد التجريبي عند الثلث الأخير. الفرع: feature/probation-review.

الموجود: Contract::isTrial() (النوع 3 في config/contracts.php، مدة 3 أشهر)، RenewalJourneys وSendContractExpirationWarnings يستثنيان التجريبي عمداً (لا تغيّرهما)، ContractAmendmentService (ملحق العقد: draft→approve→apply) لتمديد النهاية، ContractObserver، RenewalJourney كنمط لرحلة بخطوات، HRSawLeaveRequest كنمط إشعار "Seen" لـ HR، TerminationReason لأسباب الإنهاء، NotificationDeliveryLedger.

قرارات المنتج: الثلث الأخير = من (البداية + ثلثا المدة) إلى النهاية. التمديد = تعديل تاريخ النهاية (عبر ملحق العقد الحالي، لا عقد تجريبي جديد). التثبيت = عقد جديد دائم يبدأ بعد النهاية (استعمل مسار التجديد/الإنشاء الموجود وتحقق من سعة المنصب وأرصدة الإجازة كما في RenewalJourneys). الإلغاء = إنهاء العقد بسبب إلزامي.

المطلوب:
1) جدول probation_reviews (contract_id فريد، started_at، decision: extend|confirm|cancel، reason (نص، إلزامي لكل قرار)، decided_by/at، extended_until، hr_seen_at) + Model + migration بتاريخ جديد.
2) أمر يومي (probation:start-reviews) يبدأ مراجعة لكل عقد تجريبي فعّال دخل الثلث الأخير، idempotent، ويرسل Notification (ProbationFinalThird) للمتطوع والمدير المباشر وHR نطاق المدينة؛ تذكير للمدير كل 7 أيام حتى القرار (عبر الـ ledger). سجّله في routes/console.php (قبل 21:25 تفادياً لتضارب أوامر العقود، وبعد/قبل التجديد التلقائي حسب ما يناسب—اشرح اختيارك).
3) واجهة قرار للمدير المباشر فقط (وHR كاحتياط حسب policy): Livewire بثلاثة خيارات والسبب إلزامي؛ التنفيذ عبر الخدمات القائمة داخل معاملة واحدة وبشكل idempotent؛ عند التمديد: أقصى مدة وفق config (اسأل/اضبط افتراضياً 3 أشهر) وسجّل ملحقاً معتمداً.
4) بعد القرار: Notification (ProbationDecided) لـ HR كـ Seen بالقرار والسبب، وللمتطوع بالنتيجة.
5) أضف الصف في الـ Inbox (WorkInbox) لقرارات المدير المعلّقة، وبطاقة في صفحة العقد.
6) لا ينتقل العقد التجريبي إلى تجديد تلقائي بأي حال.
اختبارات: الدخول في الثلث الأخير بالتاريخ الصحيح، عدم التكرار، كل قرار من الثلاثة، إلزامية السبب، رفض غير المدير، رفض قرار ثانٍ.
```

### Prompt 5 — Permissions Library

```
[الصق السياق المشترك]

المهمة: مكتبة الاستحقاقات المهنية (Permissions Library). الفرع: feature/permissions-library.

المفهوم: قائمة عناصر مهنية (وصول لقاعدة بيانات X، عضوية مجموعة تيليغرام Y، قائمة بريد من it.hcsyria.org، صلاحية كاميرات، صيانة IT: طابعات/أحبار...) معظمها **خارج النظام**؛ النظام لا يفرضها بل يسجّلها ويتتبّع من يستحقها ومن نُفّذت له. هي ليست Spatie permissions.

الموجود: Position/JobLevel، MailList (قوائم بريد مع قواعد add_rules وMailListSync)، OnboardingJourney/OffboardingJourney/OnboardingChecklist، AccessRevoker، ChecklistItem/EmployeeItem (مهام التسليم)، config/access-control.php (لا تضف عناصر المكتبة إلى Spatie).

المطلوب:
1) جداول: entitlements (الاسم EN/AR بنمط {attr}_{locale}، الفئة: database|telegram|mail_list|camera|it_maintenance|other، الرابط/المعرّف الخارجي اختياري، مرتبط اختيارياً بـ mail_list_id)، position_entitlements (الافتراضي لكل منصب، مع إمكانية الربط بـ job level للمدراء كافتراضي: وصول كاميرات المركز)، employee_entitlements (الاستحقاق لشخص: source=default|manual، state=required|granted|revoked، granted_by/at، revoked_by/at، ملاحظة).
2) عند بدء عقد فعّال جديد (استمع في الموضع المناسب دون كسر ContractObserver ولا فحص السعة؛ ولا يعمل مع runningInConsole استيراد إكسل بلا ضجيج): أنشئ employee_entitlements بحالة required من افتراضيات المنصب. عند تغيير المنصب: أضف الجديد واعرض غير المنطبق للمراجعة (لا تُلغِ تلقائياً).
3) صفحة إدارة المكتبة (HR + super-admin): CRUD للعناصر وربطها بالمناصب. صفحة/لوحة في بروفايل الموظف لـ HR والمدير المباشر (نطاقه الحالي): قائمة الاستحقاقات مع إضافة/إزالة عنصر وتأشير "نُفّذ". صلاحيات جديدة بأسماء واضحة (entitlements.manage-library, entitlements.assign) وسجّلها في AccessPermissionCatalog وmigration seed على نمط grant_*_permission القائمة؛ المدير ضمن نطاق مرؤوسيه فقط.
4) ربط الخروج: عند انتهاء/إنهاء آخر عقد، أدرج ما لم يُسحب في قائمة OffboardingJourney (إن كان ذلك يناسب بنيتها دون كسر) أو اعرضه لـ HR.
5) بيانات أولية (seeder/migration اختيارية) لمنصب IT Specialist وللمدراء كما في المثال (قواعد بيانات، مجموعات تيليغرام، قائمة it، كاميرات، صيانة IT) مع كون الأسماء قابلة للتعديل.
اختبارات: افتراضيات التعيين، عدم تكرار عند تجديد العقد، إضافة/إزالة يدوية، عزل النطاق (HR مدينة أخرى/مدير غير مباشر مرفوض)، عدم منح أي Spatie permission.
```

### Prompt 6 — Critical Positions واقتراح البدلاء

```
[الصق السياق المشترك]

المهمة: المناصب الحرجة واقتراح بدلاء. الفرع: feature/critical-positions.

الموجود: Position (guarded=['id'])، org chart، Evaluation model، RecruitmentApplicant/Application وRecruitmentFinalistService (من وصل لمراحل المقابلات الأخيرة)، RecruitmentMatchingIndicators وApp\Support\Recruitment\MatchingIndicator، RecruitmentCvAnalysisService، config/assistant.php (مزوّدو AI خارجيون)، PolicyLibrary كمثال استدعاء AI.

الخصوصية: لا تُرسل بيانات متطوعين إلى مزوّد AI خارجي في هذه المهمة. الترشيح الداخلي بقواعد حتمية. طبقة AI تُبنى خلف واجهة (interface) وعلم config معطّل افتراضياً ولا تُستدعى.

المطلوب:
1) عمود positions.is_critical (+ وسم وفلتر في org chart وقائمة المناصب وشارة في الصفحة، عبر <x-tag>، صلاحية تعديل الوسم لـ HR فقط).
2) خدمة CriticalPositionAlerts: منصب حرج يصبح "يحتاج بديلاً" إذا شغر، أو انتهى عقد شاغله، أو كان تقييم شاغله الأخير سيئاً (عتبة في config؛ اقرأ من Evaluation وإن لم يتوفر عمود مناسب فاترك الشرط معطّلاً ووثّق ذلك). إشعار HR والمدير المعني.
3) لوحة "بدلاء مقترحون" لكل منصب حرج بمصدرين:
   أ) CVs قديمة: واجهة مصدر (CandidateSource) مع تنفيذ يقرأ المتقدمين الذين بلغوا المراحل الأخيرة ولم يُوظَّفوا، مع التزام كامل بسياسات الخصوصية/الاحتفاظ القائمة (RecruitmentPrivacy*، صلاحيات التوظيف، ما يُعرض). حيث لا توجد بيانات اعرض حالة فارغة واضحة "بانتظار رفع السير الذاتية".
   ب) داخلي: ترتيب متطوعين فعّالين بحسب قواعد: مطابقة المؤهلات/المسمّى/الدرجة الوظيفية مع متطلبات المنصب، آخر تقييم، سنوات الخبرة في المؤسسة، عدم كونه في منصب حرج آخر، إمكانية الانتقال بين المدن حسب النطاق. أخرج درجة وأسباب نصية لكل مقترح (قابلة للتفسير). نفذ المحرك كخدمة بدون AI.
4) من يرى اللوحة: HR (نطاق مدينته) وHR Syria والمدير العام وsuper-admin فقط؛ لا يراها المدير المباشر ولا المرشّح نفسه.
5) لا تُنشئ ترقية/عقد تلقائياً؛ المخرج اقتراح فقط، مع زر "فتح ملف المرشح".
اختبارات: الوسم والصلاحية، شروط "يحتاج بديلاً"، ترتيب المرشحين الداخليين وتفسيره، حالة فارغة للـ CVs، عدم ظهور اللوحة لأدوار غير مخوّلة، وعدم استدعاء أي HTTP خارجي (Http::fake مع assertNothingSent).
```

### Prompt 7 — مسيرة المتطوع: جلسة المدير والتسليم وإقرار السياسات

```
[الصق السياق المشترك]

المهمة: إكمال مسيرة المتطوع الجديد (Onboarding). الفرع: feature/onboarding-journey-extended.

المخطط المعتمد:
  السجل → العقد
  ثم بعد العقد، كل الخطوات بالتوازي (لا تنتظر أيٌّ منها الأخرى)، والمهلة من بداية العقد:
    إيميل العمل = حساب الدخول (موجود، النظام)          خلال يومين
    | الإلكترونيات: لابتوب، ماوس، توقيع الإيميل... (المسلِّم)  خلال يومين
    | البطاقة رقمية + مطبوعة (موجودة، HR)                خلال يومين
    | جلسة الـ HR (موجودة)                               خلال 14 يوماً
    | جلسة المدير (جديدة)                                خلال 14 يوماً
    | إقرار السياسات (جديد، المتطوع)                     بلا مهلة محددة
  → اكتمال المسيرة (لا تكتمل إلا بكل الخطوات).
  الـ Mug خارج المسيرة: يصبح مستحقاً عند بدء أول عقد غير تجريبي بعد عقد تجريبي
  (أي بعد تثبيت المسار 4)، ولا يؤخر اكتمال المسيرة.
  في steps(): لا تجعل أي خطوة بعد العقد blocked_by خطوة أخرى غير العقد.

الموجود: app/Services/OnboardingJourneys.php (STEPS، steps()، scheduleOrientation/completeOrientation/markCardHandedOver/reopen، change() بقفل ومعاملة وسجل activity "onboarding"، settleCompletion())، App\Models\OnboardingJourney، جدول onboarding_journeys، OrientationOverdue، App\Services\Inbox\OnboardingInboxSource وJourneyInbox، قائمة التسليم: Checklist وChecklistItem وDefaultDeliverable (مسلِّم لكل بند لكل مدينة) وChecklistWorkflow (الانتقالات الوحيدة المسموحة)، PolicyDocument (source drive|panel، content، source_url، source_modified_at) وpolicies:sync، علاقة المدير المباشر كما في Contract::scopeVisibleTo (المنصب الذي يتبع له منصب المتطوع).

المطلوب:
1) جلسة المدير: أعمدة جديدة على onboarding_journeys (migration جديدة): manager_orientation_scheduled_for/by وmanager_orientation_completed_at/by. نفس قواعد جلسة الـ HR: الموعد ثم التسجيل بعد حدوثها فقط، idempotent تحت نفس change()، مستحقة بنفس orientation_due_on (14 يوماً). يملكها المدير المباشر (ومن يحمل EmployeePolicy::update لـ HR المدينة كاحتياط). بنود الجلسة (البرامج، آلية العمل) تُعرض كنص إرشادي. تذكير عند التأخير على نمط OrientationOverdue (Notification جديد ManagerOrientationOverdue، سجّله في NotificationCategory بسطرك فقط)، وصف في Inbox المدير. reopen() يشمل الخطوة.
2) التسليم: صنّف بنود ChecklistItem بعمود جديد onboarding_group (electronics | card | after_probation | other | exit) عبر migration بيانات idempotent بحسب الاسم: Email وEmail Signature وLaptop وMouse وMobile وSIM = electronics؛ ID Cartoon وID Plastic = card؛ Mug = after_probation؛ بنود الإرجاع (order 3) = exit؛ الباقي other. أضف بند "Mouse" (نفس الـ migration + config/samples/sampleData.php) دون المساس بالبنود الموجودة. خطوتا electronics وcard في steps() تُقرآن ولا تُخزَّنان: مكتملة عندما تكون بنود مجموعتها في DefaultDeliverable لمدينة المتطوع مسلَّمة له (delivered_status = delivered)، وخطوة card تتطلب أيضاً card_handed_over_at كما الآن. بنود other تُعرض ضمن التسليم بلا مهلة. لا تغيّر ChecklistWorkflow. اعرض البنود الناقصة ومن يسلّمها.
2ب) المهل: ثابت ACCOUNT_DAYS = ELECTRONICS_DAYS = CARD_DAYS = 2 (من بداية العقد) بجانب ORIENTATION_DAYS = 14، تُحسب من contract.start_date ولا تُخزَّن. خطوة متأخرة تظهر late في المسيرة وفي الـ Inbox لصاحبها (النظام/HR للحساب، المسلِّم للإلكترونيات، HR للبطاقة)، وتذكير واحد عند التأخير (عبر NotificationDeliveryLedger).
2ج) الـ Mug: ليس خطوة في المسيرة ولا يدخل في اكتمالها. يصبح مستحقاً عند بدء أول عقد غير تجريبي (Contract::isTrial() = false) للمتطوع بعد عقد تجريبي؛ عندها يظهر في Inbox مسلِّمه وفي صفحة المتطوع. المتطوع الذي لم يكن له عقد تجريبي أصلاً: يُستحق مع أول عقد (اذكر هذا الافتراض في التقرير).
3) إقرار السياسات: عمود policy_documents.required_for_onboarding (افتراضي false) وجدول policy_acknowledgements (employee_id، policy_document_id، acknowledged_at، document_modified_at لقطة النسخة، فريد على الزوج). صفحة للمتطوع من حسابه تعرض الوثائق المطلوبة (المحتوى + رابط المصدر) وزر "قرأت وأوافق" لكل وثيقة؛ HR يرى الحالة في المسيرة. واجهة لـ HR لتحديد الوثائق المطلوبة (منها Media guideline). الخطوة مكتملة عند إقرار كل الوثائق المطلوبة؛ إن لم توجد وثائق مطلوبة فالخطوة "مشكلة" توضّح ذلك لا "منجزة".
4) settleCompletion(): الاكتمال = الحساب + الإلكترونيات + البطاقة + جلسة HR + جلسة المدير + الإقرار (+ بنود other المسلَّمة؛ الـ Mug مستثنى). لأن خطوتي التسليم والإقرار تُقرآن من جداولهما، أعد حساب الاكتمال أيضاً بعد markDelivered وبعد الإقرار (دون أن يكتب ChecklistWorkflow في المسيرة مباشرة: نادِ خدمة المسيرة).
5) واجهة المسيرة الحالية في صفحة المتطوع: أضف الخطوات الثلاث بنفس المكوّنات (<x-status> للحالة، لا ألوان) ومع المالك والمهلة.
6) المسيرات القائمة: المكتملة تبقى مكتملة (لا تُعاد فتحها)؛ الجارية تكتسب الخطوات الجديدة. الموظفون بلا مسيرة لا يتأثرون.
اختبارات: كل خطوة جديدة (تحديد، تسجيل قبل الموعد مرفوض، idempotent، reopen)، مدير غير مباشر أو HR مدينة أخرى مرفوض، التسليم يكتمل فقط بكل بنود المدينة، مهلة اليومين (الحساب/الإلكترونيات/البطاقة) و14 يوماً للجلستين تظهر late في موعدها، الـ Mug لا يظهر أثناء العقد التجريبي ويظهر مع أول عقد غير تجريبي ولا يؤخر الاكتمال، الإقرار يخص المتطوع نفسه فقط، الاكتمال يتبع كل الخطوات وينعكس عند reopen، المسيرة المكتملة سابقاً لا تُفتح، وعدم تكرار تذكير التأخير. (OnboardingJourneysTest وما يتعلق بالـ Inbox كنمط.)
```

</div>
