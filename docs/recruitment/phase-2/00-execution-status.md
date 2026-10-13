<div dir="rtl" align="right">

<style>
[dir="rtl"] :where(code, pre) { direction: ltr; text-align: left; unicode-bidi: isolate; }
[dir="rtl"] pre code { display: block; direction: ltr; text-align: left; }
</style>

# المرحلة الثانية — خريطة التنفيذ (المهمتان 1 و2)

**آخر تحديث:** 26 أيلول 2026 · **الفرع:** `rms-phase2-rafi` · **الدمج:** `rms-main` مدموج فوق `61882446` · **البيئة:** PHP 8.5.11 · Laravel 13.33 · Livewire 4

## القاعدة المتبعة في هذا الدمج

**ملفات الفريق 1 (المرحلة الأولى) لم تُعدَّل ولم تُحذف.** كل منطق المرحلة الثانية أُضيف إضافةً:
- Observers على الموديلات، تعمل على **كل** مسار يكتب هذه السجلات، بما فيه نموذج الفريق 1.
- Migrations جديدة.
- ملفات خاصة بنا.

**تم التحقق:** من أصل 253 ملفاً غيّرها الفريق 1 في `rms-main`، هناك 249 مطابقة حرفياً لنسختهم ولم يُحذف أي ملف. الأربعة الباقية ملفات مشتركة **فيها إضافات فقط، ولم يُحذف أو يُعدَّل أي سطر لهم**: `routes/web.php`، و`routes/recruitment.php`، و`AppServiceProvider`، و`config/access-control.php`.

**صفحة النشر `/recruitment/campaigns/{id}/publishing` بقيت كما هي تماماً بقرار المستخدم:** جدول الدعوات فيها هو جدول الفريق 1. قواعدنا تنطبق عليه عبر الـ Observers. جدولنا (`InviteTable`) موجود على صفحتنا المستقلة `/recruitment/campaigns/{id}/invites`.

**الرموز:** ✅ منفّذ ومختبر · 🟠 خطأ عند الفريق 1 مُبلَّغ عنه (لم نعدّل ملفاتهم) · ⛔ غير مطبّق · ⚪ مؤجل لمهمة لاحقة
**من:** ف1 = الفريق 1 · ف2 = فريقنا

---

## 1. كيف يعمل التكامل

```
المتقدم ← /careers/{token}  (نموذج الفريق 1: wizard + رفع CV)
        ← PublicRecruitmentApplicationService (ف1، بدون تعديل)
            ├─ RecruitmentApplicant يُحفظ ──► RecruitmentApplicantObserver (ف2)
            │                                   phone_match_key + علامة "متقدم محتمل التكرار"
            ├─ RecruitmentApplication يُنشأ ─► RecruitmentApplicationObserver (ف2)
            │                                   سجل تدقيق "recruitment_application_submitted"
            └─ الدعوة تُعلَّم used

HR ← /recruitment/campaigns/{id}/publishing  (صفحة الفريق 1)
     ├─ مدير الرابط (ف1): توليد رابط عام أو للمدعوين + إضافة/إلغاء دعوات
     │     └─ أي تعديل على دعوة ──► RecruitmentCampaignInviteObserver (ف2)
     │                                 الدعوة المستخدمة لا تُحيى ولا تُلغى
     (الصفحة كما هي عند الفريق 1، بلا أي إضافة منا)

HR ← /recruitment/campaigns/{id}/invites  (صفحة الفريق 2 المستقلة)
     └─ InviteTable: بحث + فلتر حالة + إلغاء بتأكيد
```

---

## 2. التحقق

| الاختبار | النتيجة |
|---|---|
| `tests/Feature/Recruitment/Phase2ApplicantIntakeTest.php` (PHPUnit، **34 اختباراً / 132 تحققاً**) | ✅ كلها تنجح — دليل الاختبار: [02-testing-guide.md](02-testing-guide.md) |
| `docs/recruitment/phase-2/phase2-integration-check.php` (MySQL حقيقية، 47 فحصاً، rollback) | ✅ كلها تنجح |
| اختبارات التوظيف الكاملة (259) | 7 لا تنجح، **كلها خارج ملفاتنا** (القسم 5) |

```bash
C:/laragon/bin/php/php-8.5.11/php.exe -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/Recruitment
php artisan tinker --execute="require 'docs/recruitment/phase-2/phase2-integration-check.php';"
```

(`pdo_sqlite` معطّل في `php.ini` لـ PHP 8.5، فيُمرَّر بـ `-d` للتشغيل فقط.)

---

## 3. المهمة 1 — نموذج بيانات المتقدمين

| المتطلب | الحالة | من | أين |
|---|---|---|---|
| Candidate/Applicant · Application · Answers | ✅ | ف1 | جداول وموديلات + خدمة الإرسال |
| Campaign Invite (الاسم، الإيميل، الحملة، الحالة) | ✅ | ف1 + ف2 | شاشتهم + `InviteTable` + `RecruitmentCampaignInviteService` |
| **تاريخ الإرسال** (`sent_at`) | ⛔ | — | لا يوجد إرسال إيميل للدعوات |
| ربط Application بـ Campaign/Vacancy/Form Version | ✅ | ف1 | من الرابط لا من المستخدم |
| Status enum | ✅ | ف1 | `RecruitmentApplicationStatus` (نصي). العمود `VARCHAR` بعد Migration `2026_09_25_000001` (ف2) |
| Status transitions | ⚪ المهمة 6 | | |
| كشف المتقدم السابق: الإيميل | ✅ | ف1 + ف2 | HMAC للإيميل (ف1)، ووحّدنا خدمتنا عليه (`2026_09_24_000002`) |
| **كشف التكرار: إيميل مختلف ونفس الهاتف** | ✅ | **ف2** | `RecruitmentApplicantObserver` + `RecruitmentDuplicateApplicantDetector`. **تعليم لا دمج**، و`+963 944…` = `0944…` |
| Indexes | ✅ | ف1 + ف2 | + `ra_phone_match_idx` |
| PII ضمن Scope، HR فقط | ✅ | ف2 | 3 Policies + صلاحيات `recruitment-applicants/applications` للـ HR فقط |
| لا status/campaign_id/score من العامة | ✅ | ف1 | |
| قائمة المدعوين داخلية | ✅ | ف1 | |
| HR يضيف/يعدّل/يلغي | ✅ | ف1 + ف2 | التعديل = إعادة إضافة الإيميل بالاسم الصحيح (تصميم ف1) |
| الحالات الاستثنائية الـ 11 | ✅ | | كلها مغطاة باختبارات PHPUnit |
| سلامة البيانات | ✅ / ⚪ | | سياسة retention: المهمة 11 |

## 4. المهمة 2 — الإرسال من الرابط العام

كل بنود المهمة 2 منفّذة في كود الفريق 1 ومختبرة عبر نقطتهم الحقيقية `POST /careers/{token}/applications`:
- endpoint عام، ومفتاح idempotency، وhoneypot، وحدود معدل الطلبات؛
- whitelist للحقول، و`transaction`؛
- رفض الحملة المغلقة أو المنتهية، ولو لم يعمل الـ scheduler؛
- مطابقة الدعوة **قبل** تخزين الـ CV (اختبار يعدّ الملفات)؛
- رسالة خطأ عامة لا تكشف قائمة المدعوين.

| البند | الحالة | من |
|---|---|---|
| **Create audit/submission log** | ✅ | **ف2**، عبر `RecruitmentApplicationObserver` (معرّفات فقط بلا PII، وفشله لا يُسقط التقديم) |
| **Dispatch notifications after commit** | ⛔ | لا إيميل تأكيد، وموافقة "نسخة من إجاباتي" (`response_copy`) لا تُرسل |

---

## 5. أخطاء عند الفريق 1: مُبلَّغ عنها، لم نعدّل ملفاتهم

| # | الخطأ | الأثر | الإصلاح المقترح (في ملفاتهم) |
|---|---|---|---|
| 1 | `HiringCampaign::INVITE_ONLY = 'invite_only'` بينما مدير الرابط والتحقق يستخدمان `'invitation_only'` | 🔴 **حملة مُنشأة "للمدعوين فقط" تُعرض كعامة، وتصبح عامة عند توليد رابطها** | `INVITE_ONLY = RecruitmentCampaignLinkMode::InvitationOnly` + مفتاح الترجمة `modes.invitation_only` + تحويل البيانات |
| 2 | الوضع `internal` بلا أي فرض | 🟠 يصبح عاماً عند توليد الرابط | قرار: ماذا يعني "داخل المؤسسة"؟ |
| 3 | `addInvite` (updateOrCreate) يُحيي دعوة مستخدمة ويمسح `used_at`، و`cancelInvite` يلغيها | 🟠 | ✅ **محمي الآن دون تعديلهم** عبر `RecruitmentCampaignInviteObserver`، ويظهر الخطأ تحت حقل الإيميل في صفحتهم |
| 4 | زر "إلغاء" ظاهر لدعوات مستخدمة أو ملغاة | 🟢 | إخفاؤه في `manager.blade.php` |
| 5 | `RecruitmentAuthorizationTest` يثبّت عدد الصلاحيات 21/19 | يفشل بسبب صلاحياتنا الخمس | تغيير الأرقام إلى **26 و24** (و`super-admin`/`HR-SYR` إلى 24)، والمدراء بلا تغيير |
| 6 | 4 اختبارات تفشل على `rms-main` نفسه | قائمة مسبقاً | `RecruitmentFieldLockTest` (ترتيب)، و3 في `RecruitmentRequestScreensTest` تتوقع `ModelNotFoundException` (Livewire 4 يعطي 404) |
| 7 | 22 فعل Livewire غير مصنّف (`LivewireMutationCoverageTest`) | قائمة مسبقاً | أفعالنا **مصنّفة كلها** |

---

## 6. ملفات الفريق 2 في هذا الدمج

**جديدة:**
- **Observers:**
  - `app/Observers/RecruitmentApplicantObserver.php`
  - `app/Observers/RecruitmentApplicationObserver.php`
  - `app/Observers/RecruitmentCampaignInviteObserver.php`
- **خدمة:** `app/Services/RecruitmentDuplicateApplicantDetector.php`
- **Migrations:** `2026_09_24_000001` (أعمدة التكرار)، و`2026_09_24_000002` (HMAC)، و`2026_09_25_000001` (الحالة نصية)
- **ترجمات:** `resources/lang/{en,ar}/recruitment-applicants.php`، وهي ملفاتنا المستقلة حتى لا تتعارض مع `recruitment.php` مرة أخرى
- **اختبارات:** `tests/Feature/Recruitment/Phase2ApplicantIntakeTest.php`

**معدّلة (ملفاتنا):**
- `RecruitmentCampaignInviteService`، و`RecruitmentApplicantIdentityService`
- `InviteTable` (polling)
- **حُذف (29 أيلول 2026):** نموذج الاختبار `/careers-test/{token}` (`Apply`، و`RecruitmentApplicationSubmissionService`، و`routes/recruitment-public.php`). منطق المهمتين 1 و2 صار كله في مسار الفريق 1 الحقيقي `POST /careers/{token}/applications`، و`Phase2ApplicantIntakeTest` يختبره هناك
- Migration `2026_09_23` (تعمل على MySQL فقط)

**مشتركة (إضافات فقط):**
- `AppServiceProvider`: تسجيل الـ Policies والـ Observers.
- `config/access-control.php`: تصنيف أفعالنا.
- `routes/recruitment.php`: مسارنا بجانب مساراتهم.

</div>
