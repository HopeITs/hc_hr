# B5-D decision sheet: incomplete volunteer records (employee draft)

**Decided:** 2026-10-20 (HR's final answers, recorded by Rafi) · **Status:** decided, awaiting technical-lead review · Full analysis: [employee-lifecycle.md](employee-lifecycle.md) §8

**What a "draft" is here:** an **incomplete volunteer record**. HR saves what it has, and the record waits as "Incomplete" until HR completes it. A draft is not staff yet: not counted, no login account, no ID card, not in the directory. It is different from a **Draft contract** (a position reservation) and from an **inactive employee** (someone who worked and left).

## English

| # | Question | Decision | How it works today |
|---|---|---|---|
| Q1 | Name fields needed to save a draft | **All 8 name fields**: first, last, father, mother; Arabic + English | Same: the first save needs all 8 |
| Q2 | Username in a draft | **Required, as today; not used in the ID-card address** | Required and unique; also forms the work email and today's card address |
| Q3 | Nationality in a draft | **Optional, as today** | Optional; the national ID must be 11 digits only when nationality is Syrian |
| Q4 | When a volunteer counts as active | **From the first real contract.** Before that: "Ready, awaiting contract", not counted. | Active immediately at the first save |
| Q5 | Weekly deactivation job | **Never touches drafts. Completed volunteers waiting for a contract are listed for HR review after 60 days; no automatic deactivation.** | Weekly: any active person with no contract is deactivated, their ID card disabled and login revoked |
| Q6 | Reserving a position from a draft | **Allowed; the reservation is deleted if the draft is deleted** | Allowed after the first save; takes no seat until it becomes a real contract |
| Q7 | Deleting incomplete records | **HR for that city, after a confirmation. Untouched for 90 days → listed for HR review; never deleted automatically.** | No drafts exist; deleting an employee is permanent |
| Q8 | Login accounts for existing staff without one | **No automatic creation at go-live.** HR creates them deliberately, or at the next contract. New volunteers: at the first contract. | Account created at the first save ("create account" ticked by default), password email sent at once |
| Q9 | Duplicate national ID in another city | **Show the existing record's name and city** | Generic "already taken" message |
| Q10 | Saving | **First: "Save draft" button + "Saved at hh:mm" + warning when leaving unsaved. Later: automatic saving on the server, never in the browser.** | One Save that needs every required field; leaving the page loses everything |

**Points to note:** with nationality optional (Q3), a mistyped Syrian national ID is not caught when nationality is left empty. The Q9 message can show a **former** employee or another city's **incomplete** record; the security reviewer should be aware.

| Review | Name / role | Date |
|---|---|---|
| Technical lead | | |

---

<div dir="rtl">

## العربية

**تاريخ القرار:** 20-10-2026 (إجابات الموارد البشرية النهائية، سجّلها رافي) · **الحالة:** تم اتخاذ القرار، بانتظار مراجعة المسؤول التقني · التحليل الكامل: [employee-lifecycle.md](employee-lifecycle.md) القسم 8

**ما المقصود بـ"المسودة" هنا:** **سجل متطوع غير مكتمل**. تحفظ الموارد البشرية ما لديها من معلومات، ويبقى السجل بحالة "غير مكتمل" حتى تُكمله. المسودة ليست موظفًا بعد: لا تُحتسب، ولا حساب دخول، ولا بطاقة هوية، ولا تظهر في الدليل. وهي تختلف عن **عقد المسودة** (حجز وظيفة) وعن **الموظف غير الفعّال** (شخص عمل ثم غادر).

| # | السؤال | القرار | كيف يعمل النظام اليوم |
|---|---|---|---|
| س1 | حقول الاسم المطلوبة لحفظ المسودة | **حقول الاسم الثمانية كلها**: الاسم الأول والأخير واسم الأب واسم الأم، بالعربية والإنكليزية | كما هو: الحفظ الأول يتطلب الحقول الثمانية |
| س2 | اسم المستخدم في المسودة | **إلزامي كما هو اليوم؛ ولا يُستخدم في عنوان بطاقة الهوية** | إلزامي وفريد؛ ويُكوّن بريد العمل وعنوان البطاقة الحالي |
| س3 | الجنسية في المسودة | **اختيارية كما هي اليوم** | اختيارية؛ ويجب أن يكون الرقم الوطني 11 رقمًا فقط عندما تكون الجنسية سورية |
| س4 | متى يُحتسب المتطوع فعّالًا | **من أول عقد حقيقي.** وقبل ذلك: "جاهز، بانتظار العقد"، ولا يُحتسب. | يصبح فعّالًا فورًا عند الحفظ الأول |
| س5 | مهمة التعطيل الأسبوعية | **لا تمسّ المسودات أبدًا. ويُعرض المتطوعون المكتملون الذين ينتظرون عقدًا على الموارد البشرية للمراجعة بعد 60 يومًا؛ دون تعطيل تلقائي.** | كل أسبوع: يُعطَّل كل شخص فعّال بلا عقد، وتُوقف بطاقته ويُلغى دخوله |
| س6 | حجز وظيفة من المسودة | **مسموح؛ ويُحذف الحجز إذا حُذفت المسودة** | مسموح بعد الحفظ الأول؛ ولا يشغل مقعدًا حتى يصبح عقدًا حقيقيًا |
| س7 | حذف السجلات غير المكتملة | **الموارد البشرية لتلك المدينة، بعد تأكيد. وما لم يُلمس 90 يومًا يُعرض للمراجعة؛ ولا يُحذف تلقائيًا أبدًا.** | لا توجد مسودات؛ وحذف الموظف نهائي |
| س8 | حسابات الدخول للموظفين الحاليين بدون حساب | **لا إنشاء تلقائي عند الإطلاق.** تنشئها الموارد البشرية عن قصد، أو عند العقد التالي. المتطوعون الجدد: عند أول عقد. | يُنشأ الحساب عند الحفظ الأول (خيار "إنشاء حساب" مُفعّل افتراضيًا)، وتُرسل رسالة كلمة المرور فورًا |
| س9 | رقم وطني مكرر في مدينة أخرى | **عرض اسم صاحب السجل الموجود ومدينته** | رسالة عامة "مستخدم مسبقًا" |
| س10 | طريقة الحفظ | **أولًا: زر "حفظ كمسودة" + "آخر حفظ: الساعة hh:mm" + تنبيه عند المغادرة دون حفظ. لاحقًا: حفظ تلقائي على الخادم، وليس في المتصفح أبدًا.** | زر حفظ واحد يتطلب كل الحقول الإلزامية؛ ومغادرة الصفحة تُضيّع كل شيء |

**ملاحظات:** مع بقاء الجنسية اختيارية (س3)، لا يُكتشف الرقم الوطني السوري المكتوب خطأً عندما تُترك الجنسية فارغة. ورسالة س9 قد تُظهر موظفًا **سابقًا** أو سجلًا **غير مكتمل** من مدينة أخرى؛ ويجب أن يكون مراجع الأمن على علم بذلك.

| المراجعة | الاسم / الدور | التاريخ |
|---|---|---|
| المسؤول التقني | | |

</div>
