<div dir="rtl" align="right">

# خطة المهام 8 · 9 · 10 — الموافقات والتطبيق والتدقيق

**21 أيلول 2026.** دراسة وتخطيط قبل التنفيذ. الكتابة تبدأ ساعة تسليم المهمة 7.

---

## 1. الحالة قبل البدء

| | |
|---|---|
| مبنيّ | المسودة · التعديل · الإرسال · النسخ · منع التكرار · طبقة الصلاحيات |
| ينتظر | **المهمة 7** — إنشاء الـ Workflow Instance وتحديد الموافقين (عند زميلي) |
| الحاجز الفعلي | `submit()` ينقل الطلب إلى `Submitted` و**لا ينشئ Instance**، فالانتقال `T4` لا يقع والطلب لا يصل أحداً |
| اختبارات التوظيف | 70 تنجح · 549 تأكيداً |

---

## 2. عقد التكامل مع المهمة 7

**أربعة أشياء أحتاجها من زميلي.** بدونها لا تبدأ المهمة 8:

| # | المطلوب | لماذا |
|---|---|---|
| 1 | `startFor(RecruitmentRequest, RecruitmentRequestVersion): RecruitmentWorkflowInstance` | تُستدعى من داخل معاملة الإرسال؛ تُنشئ الـ Instance وخطواته وتحلّ موافقيها |
| 2 | من يكتب `T4` (`submitted → under_review`) | **يُحسم عند مراجعة كوده** — يجب ألا يكتبها الاثنان |
| 3 | من يكتب حدث `WorkflowStarted` | **يُحسم عند مراجعة كوده** — حدث واحد لا اثنان |
| 4 | `restartFrom(RecruitmentRequest, RecruitmentRequestVersion, int $stepOrder)` | لإعادة البدء بعد `resubmit` من النقطة التي يحددها `RV-1` |

**وتنبيه له:** القرار `D56` يجعل `CloseVacancy` و`ClosePosition` **خطوتين لا ثلاثاً** — تعريف الـ Workflow لهذين النوعين يجب ألا يحوي خطوة مالية.

---

## 3. ما تحسمه الوثائق سلفاً — يُبنى بلا نقاش

من [03-workflow-rules.md](03-workflow-rules.md) قسم 4:

```
الفحوصات الستة، بهذا الترتيب، كلها على الخادم:
  1 الصلاحية          recruitment-requests.approve / .reject / .request-changes
  2 الخطوة النشطة      الخطوة المستهدفة هي فعلاً النشطة
  3 الموافِق المعيَّن   المستخدم هو المؤهل لهذه الخطوة تحديداً
  4 النطاق            يُقيَّم وقت الفعل لا وقت الإنشاء
  5 قابلية الانتقال    حالة الطلب تسمح بالفعل حسب T1–T18
  6 حداثة النسخة       النسخة المرسلة تطابق الحالية

فشل أي فحص يرفض الفعل دون تعديل أي بيانات.
```

| القاعدة | المصدر |
|---|---|
| `reject` يحتاج سبباً · `request_changes` يحتاج تعليقاً | قسم 4 |
| معاملة واحدة تغلّف: القفل + القرار + التقدّم + حالة الطلب | قسم التزامن |
| `lockForUpdate` على صف الخطوة — نمط `ContractObserver` | قسم التزامن |
| الخطوة المكتملة لا تُكتب مرتين؛ الموافِق الثاني يفشل عند الفحص 2 | قسم التزامن |
| **معاملتان منفصلتان** بين الموافقة والتطبيق | `D44` |
| `RV-1` يحدد نقطة إعادة البدء بتصنيف الحقول الثلاثي | `RV-1` |
| `RV-2`–`RV-6` إعادة التحقق قبل الموافقة النهائية | قسم 6 |
| فشل التحقق في `direct_hr` يرفض لا يُرجع | `D39` |
| تجاوز الإداريين يجيب الفحص 1 وحده | `D10` |

---

## 4. أربع فجوات في الـ schema — وكيف عولجت

الجداول بُنيت قبل هذه القرارات، ففيها نواقص. **لا migration جديد** التزاماً بقاعدة عدم تعديل البنية:

| # | الفجوة | الحل المعتمد |
|---|---|---|
| 1 | لا عمود `status` على `recruitment_workflow_instance_steps` | «الخطوة النشطة» تُشتقّ من `instances.current_step_order` مقابل `workflow_steps.step_order`، مع `decision IS NULL` |
| 2 | لا طريقة لتمييز خطوة متجاوَزة — `ApprovalDecision` بلا قيمة Skipped | `decision` يبقى `null` ويُكتب `decision_reason = 'skip:<reason>'` + حدث `StepSkipped` (**`D57`**) |
| 3 | لا عمود لسبب التجاوز `WorkflowSkipReason` | نفس الحل أعلاه |
| 4 | `events.workflow_step_id` يشير إلى **خطوة التعريف** لا خطوة التنفيذ | خطوة التنفيذ تُستنتج من (`workflow_instance_id` + `workflow_step_id`) معاً |

> الفجوة 2 عيبها معلن: حقل نصي يحمل معنى مُرمَّزاً. قُبل لأن البديل migration على جدول يبنيه زميلي بالتوازي.

---

## 5. المهمة 8 — قرارات الموافقة

### الملفات

```
app/Services/RecruitmentApprovalService.php        ← جديد
app/Livewire/Recruitment/Requests/Details.php      ← أفعال القرار
resources/views/.../details.blade.php              ← أزرار + حقل السبب
app/Policies/RecruitmentRequestPolicy.php          ← الفحص 1 موجود؛ 2–6 في الخدمة
tests/Feature/Recruitment/RecruitmentApprovalTest.php ← جديد
```

### واجهة الخدمة

```php
approve(User $actor, RecruitmentWorkflowInstanceStep $step, int $versionNo, ?string $comment): RecruitmentRequest
reject(User $actor, RecruitmentWorkflowInstanceStep $step, int $versionNo, string $reason): RecruitmentRequest
requestChanges(User $actor, RecruitmentWorkflowInstanceStep $step, int $versionNo, string $comment): RecruitmentRequest
```

`$versionNo` هو الفحص 6: الشاشة ترسل رقم النسخة التي عرضتها، والخدمة ترفض إن تغيّرت.

### تسلسل `approve`

```text
DB::transaction:
    قفل صف الخطوة  lockForUpdate
    الفحوصات 2 · 3 · 4 · 5 · 6          ← الفحص 1 في الـ Policy قبل الدخول
    كتابة القرار: acted_by_user_id · decision · decision_reason · acted_at
    حدث StepApproved
    هل توجد خطوة تالية؟
        نعم →  current_step_order++ · حل موافِقها · حدث WorkflowAdvanced · الطلب under_review
        لا  →  RV-2..RV-6 على البيانات الحيّة
               نجحت → الـ Instance Completed · الطلب approved · حدث WorkflowAdvanced
               فشلت → Normal: changes_requested · DirectHr: rejected (D39)
── commit ──
إن صار الطلب approved →  تشغيل المهمة 9 فوراً (D59)
```

### التزامن

موافقان متزامنان: الأول يقفل الصف، والثاني ينتظر ثم يجد `current_step_order` قد تقدّم فيفشل عند الفحص 2. **لا كتابة مزدوجة.**

### معايير القبول ← اختبارات

| المعيار | الاختبار |
|---|---|
| الموافِق المخوَّل يوافق | `test_the_assigned_approver_can_approve` |
| غير المخوَّل ممنوع | `test_another_user_cannot_decide_someone_elses_step` |
| `reject` بلا سبب يُرفض | `test_rejecting_requires_a_reason` |
| `request_changes` يُرجع للمُقدِّم | `test_requesting_changes_returns_it_to_the_requester` |
| تعليق `request_changes` إلزامي وغير فارغ (`D60`) | `test_requesting_changes_requires_the_changes_to_be_stated` |
| السوابق المرفوضة تظهر للموافِق (`D61`) | `test_a_previous_rejection_on_the_position_is_shown` |
| منع التكرار | `test_deciding_a_decided_step_is_refused` |
| منع القديم | `test_a_decision_on_a_stale_version_is_refused` |
| الموافقة النهائية تُكمل وتُجهّز | `test_the_final_approval_completes_the_workflow` |
| `HR-SYR` لا يتجاوز الفحوصات 2–4 | موجود في `RecruitmentAuthorizationTest` |

---

## 6. المهمة 9 — تطبيق الأثر

> **مسؤولية زميلي في الخطة.** أخططها هنا لأن `D59` تجعلها تُستدعى من داخل مساري مباشرة — **يجب التنسيق قبل أن يبنيها أحدنا.**

```
app/Services/RecruitmentRequestApplicationService.php
app/Services/Recruitment/Handlers/{FillVacancy,IncreaseHeadcount,DecreaseHeadcount,
                                   CreatePosition,ClosePosition,CloseVacancy}Handler.php
```

| النوع | الأثر | القفل |
|---|---|---|
| `FillVacancy` | يربط بشاغر متاح أو **يُنشئ واحداً** (`D54`) | `positions` + `vacancies` |
| `IncreaseHeadcount` | `max_emp += n` ثم شاغر جديد | `positions` |
| `DecreaseHeadcount` | `max_emp -= n` وإغلاق الشواغر الزائدة — **لا ينزل تحت `exist_emp`** (`RV-4`) | `positions` |
| `CreatePosition` | `Position` ثم شواغر بعدد `max_emp` — **معاملة واحدة** | — |
| `ClosePosition` | `is_active = false` وإغلاق كل شواغره — يُمنع مع عقود فعّالة (`RV-5`) | `positions` |
| `CloseVacancy` | إغلاق الشاغر فقط؛ لا مساس بـ`max_emp` ولا `is_active` | `vacancies` |

**الحماية:** التطبيق يفحص أن الحالة `approved` أو `failed_application` — **لا `applied`** — فلا يُطبَّق مرتين. والحالة تُكتب `applied` بعد نجاح كل شيء لا قبله.

---

## 7. المهمة 10 — التدقيق والخط الزمني

**5 من 23 نوع حدث تُكتب اليوم:**

```
✅ RequestCreated · RequestEdited · VersionCreated · RequestSubmitted · RequestCancelled
```

**تُضاف مع المهمة 8:** `WorkflowStarted` (أو من زميلي) · `WorkflowAdvanced` · `StepApproved` · `StepRejected` · `ChangesRequested` · `StepSkipped`

**تُضاف مع المهمة 9:** `RequestApplied` · `RequestApplicationFailed` · `PositionChanged` · `HeadcountChanged` · `VacancyCreated` · `VacancyClosed`

**تبقى للمهام 12–15:** `CampaignStateChanged` · `JobPostPublished` · `FormVersionPublished` · `PublicLinkChanged` · `DirectHrOpened` · `ExternalConfirmationRecorded`

### قواعد ملزمة

- **الدور والنطاق وقت القرار يُحفظان كقيمة ثابتة** ولا يُشتقّان لاحقاً من حالة المستخدم — تفسير حدث قديم بدور اليوم يُفسد السجل
- الخط الزمني يمر بـ`scopeRequestEvents` فيحترم النطاق (`D19`: من يرى الطلب يرى خطه كاملاً)
- **فشل كتابة الحدث لا يُرجع العملية** — النمط القائم في `recordEvent`
- `updated_at` ليس سجل تدقيق
- لا يظهر أي تدقيق على المسار العام

---

## 8. الترتيب والاعتماديات

```
المهمة 7 (زميلي)
     │
     ▼
المهمة 8 ── أحداثها ──► المهمة 10 (الشطر الأول)
     │
     ▼ D59: استدعاء فوري بعد commit
المهمة 9 ── أحداثها ──► المهمة 10 (الشطر الثاني)
```

المهمة 10 **ليست مرحلة مستقلة**: أحداثها تُكتب مع الكود الذي يولّدها. المتبقي لها وحدها هو **صقل عرض الخط الزمني** وتغطية الحالات الفاشلة والمرفوضة.

---

## 9. ما زال مفتوحاً

**لا يوجد سؤال إداري.** أُغلق `Q17` و`Q18` و`Q10`، فلم يبقَ لهذه المهام حاجز من جهة القرار.

**يبقى قرار تقني واحد، مؤجَّل بقصد:**

| # | القرار | لماذا مؤجَّل |
|---|---|---|
| 1 | من يكتب `T4` (`submitted → under_review`) — `startFor` أم المستدعي؟ | زميلي **أنهى المهمة 7 فعلاً**. نراجع تنفيذه أولاً ونرى ما تتولاه `startFor` بالفعل، ثم نقرر على أساس الموجود لا على أساس التخطيط — تفادياً لتعديل شغل منجز أو تكراره |
| 2 | من يكتب حدث `WorkflowStarted` | نفس السبب |

> الخطر الذي يعالجه هذا التأجيل: لو كتبها الاثنان صار الانتقال مزدوجاً والحدث مكرراً في الخط الزمني. **يُحسم بالمراجعة لا بالافتراض.**

---

## 10. القرارات المعتمدة لهذه المهام

| # | القرار |
|---|---|
| `D56` | `CloseVacancy` و`ClosePosition` يتجاوزان المراجعة المالية — يُغلق `Q17` |
| `D57` | الخطوة المتجاوَزة تُميَّز بعلامة في `decision_reason` — بلا migration |
| `D58` | Instance جديد لكل نسخة؛ القائم يُختم `Cancelled` عند `request_changes` |
| `D59` | التطبيق يُشغَّل فوراً بعد commit الموافقة، لا عبر queue |
| `D60` | `reject` و`request_changes` فعلان مستقلان؛ تعليق طلب التعديل يحدد **ما المطلوب تعديله** |
| `D61` | الطلبات المرفوضة السابقة على نفس المنصب تُعرض للموافِق — **عرض لا منع** |

---

## 11. ما نُفّذ فعلاً — 22 أيلول 2026

المهام **8 و 9 و 10 مكتملة**. الاختبارات: **132 تنجح · 789 تأكيداً**.

### ما خالف الخطة، ولماذا

| # | الخطة قالت | المنفّذ | السبب |
|---|---|---|---|
| 1 | `increase_headcount` ينتج **شاغراً لكل وحدة**، و`create_position` ينتج **شواغر بعدد `max_emp`** | شاغر واحد بـ `needed_count` يساوي العدد | `vacancies.created_from_request_id` عليه **قيد فريد** (`vac_request_uq`) — عدة شواغر للطلب الواحد مستحيلة في قاعدة البيانات. انظر `D63`. |
| 2 | `D59`: الأثر يُطبّق **فوراً** بعد الموافقة | فوراً لأربعة أنواع؛ والنوعان ذوا `effective_date` ينتظران تاريخهما | قرار صاحب المنتج: `approved` ≠ `executed`. انظر `D64`. |
| 3 | المهمة 9 **مسؤولية زميلي** | نفّذتُها | بطلب صريح منه ومؤكّد مرتين. |

### الملفات

```
app/Services/RecruitmentApprovalService.php                  المهمة 8
app/Services/RecruitmentRequestStateMachine.php              المهمة 8
app/Services/RecruitmentRequestRevalidator.php               المهمة 8
app/Services/RecruitmentRequestChangeClassifier.php          8 + 10
app/Services/RecruitmentRequestApplicationService.php        المهمة 9
app/Services/Recruitment/Handlers/*.php                      المهمة 9 — ستة معالجات + trait
app/Console/Commands/ApplyDueRecruitmentRequests.php         المهمة 9 — مجدول يومياً 00:30
app/Notifications/RecruitmentRequestAwaitingExecution.php    المهمة 9
```

### ما زال يحتاج تأكيداً

| الحقل | صُنّف | لماذا يحتاج تأكيداً |
|---|---|---|
| `direct_supervisor_position_id` | جوهري | جدول `RV-1` لا يسمّيه، لكنه يحدد موقع المنصب في الهيكل |
| `effective_date` | جوهري | بعد `D64` صار يحدد متى يتغيّر الهيكل فعلاً |

</div>
