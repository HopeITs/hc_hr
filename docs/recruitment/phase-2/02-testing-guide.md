<div dir="rtl" align="right">

<style>
[dir="rtl"] :where(code, pre) { direction: ltr; text-align: left; unicode-bidi: isolate; }
[dir="rtl"] pre code { display: block; direction: ltr; text-align: left; }
</style>

# دليل اختبار المهمتين 1 و2 من المرحلة الثانية

ثلاث طرق، من الأسرع إلى الأقرب للاستخدام الحقيقي:
- **أ.** اختبارات آلية (PHPUnit) تغطي كل حالة قابلة للاختبار.
- **ب.** فحص تكامل على قاعدة MySQL الحقيقية، دون أن يترك أثراً.
- **ج.** اختبار يدوي من المتصفح، حالة بحالة.

> **PHP:** المشروع يعمل على PHP 8.5. الأمر `php` في الـ PATH قد يكون 8.3، فاستخدم المسار الكامل:
> `C:/laragon/bin/php/php-8.5.11/php.exe`. في الأمثلة أدناه نكتبه `php85`.

---

## أ. الاختبارات الآلية (PHPUnit)

`pdo_sqlite` معطّل في `php.ini` لـ PHP 8.5، فنفعّله للتشغيل فقط بخيار `-d` دون تعديل الإعدادات:

```bash
# اختباراتنا: 32 اختباراً تغطي المهمتين 1 و2
php85 -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/Recruitment/Phase2ApplicantIntakeTest.php

# اختبارات الفريق 1 التي تغطي جزءاً من المهمة 2 (الرابط العام، الملفات، حدود الطلبات)
php85 -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/Recruitment/PublicRecruitmentLinkTest.php tests/Feature/Recruitment/RecruitmentApplicantFileSecurityTest.php

# كل اختبارات التوظيف
php85 -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit tests/Feature/Recruitment

# اختبار واحد بالاسم
php85 -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --filter test_a_shared_phone_under_another_email_is_flagged_not_merged tests/Feature/Recruitment/Phase2ApplicantIntakeTest.php
```

**النتيجة المتوقعة:** `OK (34 tests …)` لاختباراتنا. اختبارات الفريق 1 المعروف فشلها موثّقة في [00-execution-status.md](00-execution-status.md).

---

## ب. فحص التكامل على MySQL الحقيقية (بلا أثر)

46 فحصاً على قاعدة `hr_db` الفعلية، عبر نموذج الفريق 1 الحقيقي مع رفع PDF فعلي. كل شيء داخل transaction يُلغى في النهاية، والملفات المرفوعة تُحذف:

```bash
php85 artisan tinker --execute="require 'docs/recruitment/phase-2/phase2-integration-check.php';"
```

**المتوقع:** `RESULT: 47 passed, 0 failed` و`Cleaned 4 uploaded test file(s).`

---

## ج. الاختبار اليدوي من المتصفح

### التحضير

1. سجّل الدخول بمستخدم **HR** (`HR-SYR` أو HR المحافظة). أدوار HR تملك صلاحيات الروابط (الفريق 1) وصلاحيات المتقدمين (نحن).
2. افتح **نافذة خاصة (Incognito)** لدور المتقدم، لأن المتقدم لا يملك حساباً.

### الحصول على رابط تقديم: طريقتان

**الطريقة 1، الأسرع: أمر العرض.** ينشئ حملة تجريبية كاملة ويطبع الرابط. تبقى في قاعدة التطوير عمداً لتجربها:

```bash
php85 artisan recruitment:demo-application-link --invite-only --invite-email=me@example.test
# أو بدون --invite-only لحملة عامة
```

يطبع رابط النموذج الحقيقي `http://hc-hr.test/careers/{token}`. (نموذجنا التجريبي `/careers-test` حُذف في 29 أيلول 2026.)

**الطريقة 2، الواجهة الحقيقية (الحملة 5):**
1. افتح `https://hc-hr.test/en/recruitment/campaigns/5/publishing`.
2. في قسم **الرابط العام**: اختر "للمدعوين فقط" واضغط **توليد**. **انسخ الرابط فوراً**، فهو يُعرض مرة واحدة ويُحفظ مشفّراً.
3. في جدول الدعوات أسفل القسم: أضف اسماً وإيميلاً.
4. افتح الرابط في النافذة الخاصة.

**صفحات مفيدة لـ HR:**

| الصفحة | الرابط |
|---|---|
| الحملات | `https://hc-hr.test/en/recruitment/campaigns` |
| صفحة النشر (الرابط + دعوات الفريق 1) | `https://hc-hr.test/en/recruitment/campaigns/5/publishing` |
| جدولنا للدعوات (بحث + فلتر + إلغاء) | `https://hc-hr.test/en/recruitment/campaigns/5/invites` |
| سجل النشاط (التدقيق) | `https://hc-hr.test/en/activity-log` |

### التحقق من القاعدة بعد كل تجربة

```bash
# آخر التقديمات
php85 artisan tinker --execute="App\Models\RecruitmentApplication::with('applicant')->latest('id')->take(5)->get(['id','applicant_id','campaign_id','form_version_id','campaign_invite_id','email_normalized','status'])->each(fn(\$a)=>print_r(\$a->toArray()));"

# المتقدمون وعلامة التكرار بالهاتف
php85 artisan tinker --execute="App\Models\RecruitmentApplicant::latest('id')->take(5)->get(['id','email_normalized','phone_normalized','phone_match_key','possible_duplicate_of_applicant_id'])->each(fn(\$a)=>print_r(\$a->toArray()));"

# حالة الدعوات لحملة (غيّر 5)
php85 artisan tinker --execute="App\Models\RecruitmentCampaignInvite::where('campaign_id',5)->get(['id','email_normalized','status','used_at'])->each(fn(\$i)=>print_r(\$i->toArray()));"

# سجل التدقيق للتقديمات
php85 artisan tinker --execute="DB::table('activity_log')->where('description','recruitment_application_submitted')->latest('id')->take(3)->get(['id','subject_id','properties','created_at'])->each(fn(\$r)=>print_r((array)\$r));"
```

### الحالات: الخطوات والنتيجة المتوقعة

| # | الحالة (من الخطة) | الخطوات | المتوقع |
|---|---|---|---|
| 1 | تقديم صحيح | املأ النموذج وارفع CV (PDF) | صفحة "تم الإرسال". تقديم جديد `status=submitted` مرتبط بالحملة والنسخة، وسطر في سجل التدقيق |
| 2 | ضغط submit مرتين أو إعادة التحميل | اضغط الإرسال مرتين بسرعة، أو أعد تحميل صفحة النجاح وأكّد الإرسال | تقديم **واحد** فقط |
| 3 | نفس الإيميل مرة ثانية | قدّم مجدداً بنفس الإيميل بأحرف كبيرة ومسافات | رسالة خطأ عامة، ولا تقديم ثانٍ |
| 4 | نفس الشخص على حملتين | قدّم بنفس الإيميل على حملتين | متقدم **واحد** بتقديمين |
| 5 | **إيميل مختلف ونفس الهاتف** | قدّم مرتين بإيميلين مختلفين ونفس الهاتف بصيغتين (`0944123456` و`+963 944 123 456`) | متقدمان منفصلان (لا دمج)، والثاني `possible_duplicate_of_applicant_id` = الأول |
| 6 | نفس الإيميل وهاتف مختلف | نفس الإيميل برقم جديد على حملة أخرى | نفس المتقدم، والهاتف تحدّث |
| 7 | شخصان بنفس الاسم | اسم واحد وإيميلان وهاتفان مختلفان | متقدمان منفصلان بلا علامة |
| 8 | غير مدعو (حملة للمدعوين) | قدّم بإيميل غير مضاف | رسالة عامة **لا تذكر** قائمة المدعوين، ولا يُخزَّن CV |
| 9 | مدعو بمسافات وأحرف كبيرة | أضف `Invited@Example.com` وقدّم بـ `  INVITED@example.com ` | يُقبل ويُربط بالدعوة، والدعوة `used` |
| 10 | المدعو يقدّم مرة ثانية | نفس الإيميل بعد القبول | رفض عام |
| 11 | HR ألغى الدعوة | ألغِ الدعوة ثم قدّم بإيميلها | رفض عام |
| 12 | HR أضاف إيميلاً خاطئاً | ألغِ الخاطئ وأضف الصحيح | الخاطئ يُرفض والصحيح يُقبل |
| 13 | تعديل اسم مدعو | أعد إضافة نفس الإيميل باسم آخر | نفس الدعوة، والاسم تغيّر |
| 14 | إحياء أو إلغاء دعوة **مستخدمة** | في صفحة النشر: أعد إضافة إيميل دعوة `used`، أو ألغها | خطأ تحت حقل الإيميل، والدعوة تبقى `used` مع تاريخها |
| 15 | حملة مغلقة أو منتهية أو معطلة | أغلق الحملة من صفحتها، ثم أرسل من نافذة كانت مفتوحة | رفض عام، ولا تقديم |
| 16 | رابط معطّل أو منتهٍ | عطّل الرابط من صفحة النشر، ثم افتحه | صفحة "غير متاح" (404) |
| 17 | قائمة المدعوين لا تظهر للعامة | افتح الرابط وابحث في الصفحة (Ctrl+U) عن إيميل مدعو | غير موجود |
| 18 | حقول داخلية | من أدوات المطوّر أضف حقلاً مخفياً `status=hired` أو `campaign_id=1` وأرسل | رفض عام |
| 19 | الـ honeypot | املأ الحقل المخفي `website` من أدوات المطوّر | رفض عام |
| 20 | حدود المعدل | أرسل أكثر من 5 مرات في دقيقة من نفس الجهاز لنفس الرابط | `429 Too Many Requests` |
| 21 | ملف غير آمن | ارفع ملف `.php` بعد تغيير اسمه إلى `.pdf` | رفض، ولا ملف مخزّن |
| 22 | PII ضمن النطاق | افتح `/invites` لحملة بمستخدم HR من محافظة أخرى، أو بمدير | 403 |

</div>
