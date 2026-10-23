# A3-D decision sheet: leave calculation rules

**For:** HR product owner (D-5 also technical lead) · **From:** A3-D analysis (2026-10-20) · **Status:** answered and approved 2026-10-20 (answers in decisions.md, DEC-03 follow-up)
Full analysis: [leave-calculation-contract.md](leave-calculation-contract.md) §8. Please mark one option per question and return the sheet; answers are recorded in decisions.md under DEC-03.

Background: DEC-03 already settled weekends (from the center), holidays not counted, pending requests reserve balance, the "over 4 hours = one day" rule, and that old requests stay unchanged. The questions below are what DEC-03 did not cover. "If unanswered" is what the system keeps doing, so no answer never breaks anything.

## English

| # | Question | Options | Recommendation | If unanswered |
|---|---|---|---|---|
| **D-1** | Volunteers with **no center** use their governorate's weekend, but the system has no such field. Where is it set, and who edits it? | **A.** A weekend field on each governorate, edited by national HR · **B.** Assign every volunteer a center, even a virtual one · **C.** Keep the default Sunday + Saturday | **A** | C, with a visible warning on the request |
| **D-2** | Besides maternity, which leave types count **calendar days** (weekends and holidays included)? | Tick any: sick · marriage · paternity · bereavement · unpaid · none | HR to state. Typical: sick, marriage, paternity, bereavement | Only maternity; the rest skip weekends |
| **D-3a** | How are **hourly requests rounded**? Example: 3h 20min | **A.** Up to next quarter hour (3.5 h) · **B.** To nearest quarter (3.25 h) · **C.** Exact, minimum 15 min | **A** | A |
| **D-3b** | Today an hourly request is capped at **half a day (4 h)**, so the "over 4 hours = one day" rule can never apply. Should the cap be removed? | **A.** Remove cap: over 4 h becomes one full day · **B.** Keep cap; longer needs a day request | **B** (simpler, no surprises) | B |
| **D-4** | **Which holidays** apply to a volunteer? | **A.** National + the center's city + the program's · **B.** City only · **C.** National only. And: does a recurring holiday repeat every year? yes / no | **A**, recurring = yes | None applied; a warning only |
| **D-5** | Save the **computed hours on each request** at creation, so later changes to holidays or weekends never change an existing request? (Technical lead; needs a new database column.) | **A.** Yes · **B.** No, recompute each time | **A** | B |
| **D-6** | When a request **exceeds the remaining balance** for a non-annual type (sick, marriage…), should it block or only warn? Annual always blocks. | **A.** Block · **B.** Warn, HR decides at approval | **B** | B |
| **D-7** | **December**: leave is not allowed except in exceptional cases. | **A.** Warning only · **B.** Block unless the requester marks it exceptional and the manager and HR approve | **A** | A |
| **D-8** | Are there **six-day or irregular schedules** in real contracts? If yes, day length is weekly hours ÷ days worked, instead of ÷ 5. | **A.** Yes, list them: ______ · **B.** No, everyone works five days | HR to state | Derived from the weekend: two-day weekend = 5 days, Friday-only = 6 |
| **D-9** | What defines **part-time**? (Part-time is deducted by hours only.) | Weekly hours below ____ · or a flag on the contract | HR to state | Not applied: everyone treated as full-time |

| Signature | Name / role | Date |
|---|---|---|
| HR product owner | Approved in session | 2026-10-20 |
| Technical lead (D-5) | Approved in session | 2026-10-20 |

---

<div dir="rtl">

## العربية

**إلى:** مسؤول منتج الموارد البشرية (وللسؤال D-5 المسؤول التقني) · **من:** تحليل المهمة A3-D (20-10-2026) · **الحالة:** أُجيبت واعتُمدت في 20-10-2026 (الإجابات في decisions.md تحت DEC-03)
التحليل الكامل: [leave-calculation-contract.md](leave-calculation-contract.md) القسم 8. يرجى اختيار خيار واحد لكل سؤال وإعادة الورقة؛ تُسجَّل الإجابات في decisions.md تحت DEC-03.

خلفية: حسم DEC-03 سابقًا عطلة الأسبوع (من المركز)، وعدم احتساب العطل الرسمية، وحجز الطلبات المعلّقة من الرصيد، وقاعدة "أكثر من 4 ساعات = يوم"، وبقاء الطلبات القديمة كما هي. الأسئلة أدناه لم يغطّها DEC-03. عمود "عند عدم الإجابة" يبيّن ما سيستمر النظام عليه، فلا يتعطل شيء بغياب الإجابة.

| # | السؤال | الخيارات | التوصية | عند عدم الإجابة |
|---|---|---|---|---|
| **D-1** | المتطوع **بلا مركز** يتبع عطلة محافظته، لكن النظام لا يملك هذا الحقل. أين يُحدَّد ومن يعدّله؟ | **أ.** حقل عطلة لكل محافظة تعدّله الموارد البشرية المركزية · **ب.** ربط كل متطوع بمركز ولو افتراضي · **ج.** إبقاء الافتراضي (الأحد + السبت) | **أ** | ج، مع تحذير ظاهر في الطلب |
| **D-2** | إضافة إلى الأمومة، أي أنواع الإجازة تُحسب **بالأيام التقويمية** (تشمل العطل الأسبوعية والرسمية)؟ | علّم ما ينطبق: مرضية · زواج · أبوة · وفاة · بدون راتب · لا شيء | تحدّدها الموارد البشرية. المعتاد: مرضية، زواج، أبوة، وفاة | الأمومة فقط؛ الباقي يتخطى العطل الأسبوعية |
| **D-3a** | كيف تُقرَّب **الطلبات الساعية**؟ مثال: 3 ساعات و20 دقيقة | **أ.** للأعلى إلى ربع الساعة التالي (3.5) · **ب.** لأقرب ربع (3.25) · **ج.** بالضبط وحدّ أدنى 15 دقيقة | **أ** | أ |
| **D-3b** | اليوم يُحدّ الطلب الساعي بـ**نصف يوم (4 ساعات)**، فلا تنطبق قاعدة "أكثر من 4 ساعات = يوم" أبدًا. هل يُلغى الحد؟ | **أ.** إلغاء الحد: ما فوق 4 ساعات يصبح يومًا كاملًا · **ب.** إبقاء الحد؛ الأطول يُطلب كيوم | **ب** (أبسط، بلا مفاجآت) | ب |
| **D-4** | **أي عطل رسمية** تنطبق على المتطوع؟ | **أ.** الوطنية + عطل مدينة المركز + عطل البرنامج · **ب.** المدينة فقط · **ج.** الوطنية فقط. وهل تتكرر العطلة المتكررة كل سنة؟ نعم / لا | **أ**، والتكرار = نعم | لا تُطبَّق عطل؛ تحذير فقط |
| **D-5** | هل نحفظ **الساعات المحسوبة مع كل طلب** عند إنشائه، حتى لا يغيّر تعديل العطل أو عطلة الأسبوع طلبًا قائمًا؟ (للمسؤول التقني؛ يحتاج عمودًا جديدًا في قاعدة البيانات) | **أ.** نعم · **ب.** لا، إعادة الحساب كل مرة | **أ** | ب |
| **D-6** | عند **تجاوز الطلب للرصيد المتبقي** في نوع غير سنوي (مرضية، زواج…)، هل يُمنع أم يُحذَّر فقط؟ السنوية تُمنع دائمًا. | **أ.** منع · **ب.** تحذير، وتقرر الموارد البشرية عند الموافقة | **ب** | ب |
| **D-7** | **كانون الأول**: الإجازة غير مسموحة إلا في حالات استثنائية. | **أ.** تحذير فقط · **ب.** منع إلا إذا علّم الطالب أنها استثنائية ووافق المدير والموارد البشرية | **أ** | أ |
| **D-8** | هل توجد في العقود الفعلية **جداول ستة أيام أو غير منتظمة**؟ إن وُجدت، يصبح طول اليوم = الساعات الأسبوعية ÷ أيام العمل بدل ÷ 5. | **أ.** نعم، اذكرها: ______ · **ب.** لا، الجميع خمسة أيام | تحدّدها الموارد البشرية | يُشتق من عطلة الأسبوع: عطلة يومين = 5 أيام، الجمعة فقط = 6 |
| **D-9** | ما الذي يحدد **الدوام الجزئي**؟ (يُخصم الجزئي بالساعات فقط) | ساعات أسبوعية أقل من ____ · أو علامة في العقد | تحدّدها الموارد البشرية | لا يُطبَّق: الجميع كدوام كامل |

| التوقيع | الاسم / الدور | التاريخ |
|---|---|---|
| مسؤول منتج الموارد البشرية | اعتُمد في الجلسة | 2026-10-20 |
| المسؤول التقني (D-5) | اعتُمد في الجلسة | 2026-10-20 |

</div>
