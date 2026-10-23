# A2-D decision sheet: staff directory and public ID card

**For:** HR product owner and security reviewer · **From:** A2-D analysis (2026-10-19) · **Status:** decided 2026-10-20 (answers in access-policy.md §9; Q6 replaced by the card-link decision)
Full analysis and evidence: [access-policy.md](access-policy.md). Please mark one option per question and return the sheet; answers are recorded in access-policy.md §9.

Background in one paragraph: in October HR decided that all staff may see colleagues in every city (name, photo, position, work contact), and that a lost or replaced ID card must stop working immediately. Today the org chart's profile panel has its security check switched off, any signed-in user can open any full profile, the directory still lists former staff, and the public ID card's web address is the person's username (the part of the work email before @), so anyone can guess it and a replaced card can never be switched off.

## English

| # | Question | Options | Recommendation | Risk of each option | Blocks |
|---|---|---|---|---|---|
| **Q1** | Besides name, photo, position and work contact, may all staff also see a colleague's **program, department, center, city and manager / direct reports**? | **A.** Yes (as the org chart shows today) · **B.** No, only the four items HR listed | **A** | A: none known; this is organisation structure, not personal data. B: the org chart loses its manager and team lines for most users. | Profile panel and directory fields |
| **Q2** | Who may see a colleague's **career history (no pay), education, experience, skills and courses**? Today every manager sees this for anyone in any city; employees cannot see it even on their own profile. | **A.** Managers for their own team; general manager; HR for their city; national HR. Employees see their own. · **B.** Keep today's rule. · **C.** HR only. | **A** | A: a manager can no longer read other teams' history (may need to ask HR). B: managers keep reading every staff member's history nationwide. C: managers lose sight of their own team's background. | Profile page sections |
| **Q3** | Should **former staff** still appear in the directory, org chart and profile panel for ordinary staff and managers? Today they do, with work contacts. | **A.** No for staff and managers; yes for general manager and HR. · **B.** Yes for everyone (today). | **A** | A: colleagues can no longer look up a former staff member's old contact. B: departed people's details stay visible to all staff indefinitely. | Directory, profile panel, profile page |
| **Q4** | Should a **city HR officer** (e.g. Aleppo HR) see staff of **other cities** at directory level, like every other employee? HR data (private, pay, edits) stays limited to their own city. | **A.** Yes, directory level only · **B.** No, own city only | **A** | A: none beyond what every employee already sees. B: HR sees less than an employee; the 134 broken photos on Aleppo HR's dashboard stay broken. | Photos, profile panel, dashboard team card |
| **Q5** | Who may **disable, re-enable and re-issue** an ID card? Can staff report their own card lost? | **A.** HR for that city, national HR and super-admin; staff can report a loss, HR acts. · **B.** National HR and super-admin only. · **C.** Staff can disable their own card directly. | **A** | A: none known. B: slower response to a lost card in cities without national HR on site. C: a card could be switched off by mistake or by someone using an unattended session. | The card-management screen |
| **Q6** | How should we move the ID cards to **secure links**, and **by when** must the old printed cards stop working? | **A.** All cards switch to secure links at once; everyone needs a new card first. · **B.** Keep username links (today). · **C.** New and replacement cards use secure links; old printed cards keep working until that person's card is replaced or until a **sunset date** you choose. | **C**, with a sunset date at the next card-printing round | A: every printed card stops working on switch day unless reprinted beforehand. B: anyone can open anyone's card from their email; a replaced card can **never** be switched off, so it breaks the October decision. C: old cards remain guessable until replaced or the sunset date; security must **accept this risk in writing** for that period. | Secure-card rollout and the old-card sunset |
| **Q7** | Where should the card's **emergency number** come from? Today: the work phone of the first HR staff member found in that city. | **A.** A fixed HR hotline number per city, set by HR · **B.** Keep today's rule | **A** (optional) | A: someone must keep the per-city numbers up to date. B: the number changes without notice when HR staff change. | Card display (minor) |

**Not offered, on purpose:** keeping username links and automatically redirecting them to the secure link. It would hand the secret link to anyone who knows a username, so it gives no protection.

| Signature | Name / role | Date |
|---|---|---|
| HR product owner | | |
| Security reviewer | | |

---

<div dir="rtl">

## العربية

**إلى:** مسؤول منتج الموارد البشرية ومراجع الأمن · **من:** تحليل المهمة A2-D (19-10-2026) · **الحالة:** اكتمل التحليل، بانتظار قرارات الموارد البشرية والأمن
التحليل الكامل والأدلة: [access-policy.md](access-policy.md). يرجى اختيار خيار واحد لكل سؤال وإعادة الورقة؛ تُسجَّل الإجابات في القسم 9 من access-policy.md.

خلفية مختصرة: قررت الموارد البشرية في تشرين الأول أن يرى جميع الموظفين زملاءهم في كل المدن (الاسم، الصورة، المسمى الوظيفي، وسيلة التواصل الخاصة بالعمل)، وأن البطاقة المفقودة أو المستبدلة يجب أن تتوقف عن العمل فورًا. أما اليوم: فحص الأمان في لوحة الملف الشخصي ضمن الهيكل التنظيمي معطَّل، وأي مستخدم مسجَّل يستطيع فتح الملف الكامل لأي موظف، ودليل الموظفين ما زال يعرض الموظفين السابقين، ورابط بطاقة الهوية العامة هو اسم المستخدم (الجزء الذي يسبق @ في بريد العمل)، فيمكن لأي شخص تخمينه، ولا يمكن أبدًا إيقاف البطاقة القديمة بعد استبدالها.

| # | السؤال | الخيارات | التوصية | مخاطر كل خيار | ما يتوقف عليه |
|---|---|---|---|---|---|
| **س1** | إضافة إلى الاسم والصورة والمسمى ووسيلة التواصل، هل يرى جميع الموظفين **البرنامج والقسم والمركز والمدينة والمدير المباشر والمرؤوسين** للزميل؟ | **أ.** نعم (كما يعرضها الهيكل التنظيمي اليوم) · **ب.** لا، العناصر الأربعة فقط | **أ** | أ: لا خطر معروف؛ هذه بنية المؤسسة وليست بيانات شخصية. ب: يفقد الهيكل التنظيمي خطوط المدير والفريق لمعظم المستخدمين. | حقول لوحة الملف الشخصي والدليل |
| **س2** | من يرى **السجل الوظيفي (بدون الراتب) والتعليم والخبرة والمهارات والدورات** للزميل؟ اليوم يراها كل مدير لأي موظف في أي مدينة، ولا يراها الموظف حتى في ملفه الخاص. | **أ.** المدير لفريقه؛ المدير العام؛ الموارد البشرية لمدينتها؛ الموارد البشرية المركزية؛ والموظف لملفه. · **ب.** الإبقاء على الوضع الحالي. · **ج.** الموارد البشرية فقط. | **أ** | أ: لا يعود المدير قادرًا على قراءة سجل الفرق الأخرى (قد يطلبه من الموارد البشرية). ب: يبقى كل مدير قادرًا على قراءة سجل جميع الموظفين في كل المحافظات. ج: يفقد المدير الاطلاع على خلفية فريقه. | أقسام صفحة الملف الشخصي |
| **س3** | هل يبقى **الموظفون السابقون** ظاهرين في الدليل والهيكل التنظيمي ولوحة الملف الشخصي للموظفين والمديرين؟ اليوم يظهرون مع وسائل تواصلهم. | **أ.** لا للموظفين والمديرين؛ نعم للمدير العام والموارد البشرية. · **ب.** نعم للجميع (الوضع الحالي). | **أ** | أ: لا يستطيع الزملاء البحث عن وسيلة تواصل قديمة لموظف سابق. ب: تبقى بيانات من غادروا ظاهرة لجميع الموظفين بلا حدّ زمني. | الدليل، لوحة الملف الشخصي، صفحة الملف |
| **س4** | هل يرى **موظف الموارد البشرية في مدينة** (مثل موارد حلب) موظفي **المدن الأخرى** بمستوى الدليل فقط، كما يراهم أي موظف؟ تبقى بيانات الموارد البشرية (الخاصة، الراتب، التعديل) محصورة بمدينته. | **أ.** نعم، بمستوى الدليل فقط · **ب.** لا، مدينته فقط | **أ** | أ: لا شيء يتجاوز ما يراه أي موظف أصلًا. ب: يرى موظف الموارد البشرية أقل مما يراه الموظف العادي، وتبقى 134 صورة معطلة في لوحة موارد حلب. | الصور، لوحة الملف الشخصي، بطاقة الفريق في لوحة التحكم |
| **س5** | من يحق له **إيقاف بطاقة الهوية وإعادة تفعيلها وإعادة إصدارها**؟ وهل يستطيع الموظف الإبلاغ عن فقدان بطاقته؟ | **أ.** الموارد البشرية لتلك المدينة، والموارد البشرية المركزية، ومدير النظام؛ ويستطيع الموظف الإبلاغ عن الفقدان وتتصرف الموارد البشرية. · **ب.** الموارد البشرية المركزية ومدير النظام فقط. · **ج.** يستطيع الموظف إيقاف بطاقته مباشرة. | **أ** | أ: لا خطر معروف. ب: استجابة أبطأ لفقدان بطاقة في المدن التي لا توجد فيها الموارد البشرية المركزية. ج: قد تُوقف بطاقة خطأً أو من شخص يستخدم جلسة مفتوحة دون رقابة. | شاشة إدارة البطاقات |
| **س6** | كيف ننقل بطاقات الهوية إلى **روابط آمنة**، و**متى** يجب أن تتوقف البطاقات المطبوعة القديمة؟ | **أ.** تحويل جميع البطاقات دفعة واحدة؛ ويحتاج الجميع بطاقة جديدة قبل ذلك. · **ب.** الإبقاء على روابط اسم المستخدم (الوضع الحالي). · **ج.** البطاقات الجديدة والبديلة بروابط آمنة؛ وتبقى البطاقات القديمة تعمل حتى استبدال بطاقة صاحبها أو حتى **تاريخ انتهاء** تحددونه. | **ج**، مع تاريخ انتهاء عند دورة الطباعة القادمة | أ: تتوقف كل بطاقة مطبوعة يوم التحويل ما لم تُطبع بديلتها مسبقًا. ب: يستطيع أي شخص فتح بطاقة أي موظف من بريده، ولا يمكن أبدًا إيقاف بطاقة مستبدلة، فيخالف قرار تشرين الأول. ج: تبقى البطاقات القديمة قابلة للتخمين حتى استبدالها أو حتى تاريخ الانتهاء، ويجب أن **يقبل الأمن هذا الخطر كتابيًا** لتلك الفترة. | التحويل إلى البطاقات الآمنة وإنهاء البطاقات القديمة |
| **س7** | من أين يأتي **رقم الطوارئ** على البطاقة؟ اليوم: رقم عمل أول موظف موارد بشرية في تلك المدينة. | **أ.** رقم خط ساخن ثابت للموارد البشرية لكل مدينة تحدده الموارد البشرية · **ب.** الإبقاء على القاعدة الحالية | **أ** (اختياري) | أ: يجب أن يحدّث أحدٌ أرقام المدن باستمرار. ب: يتغير الرقم دون إشعار عند تغير موظفي الموارد البشرية. | عرض البطاقة (أثر بسيط) |

**خيار مستبعد عمدًا:** الإبقاء على روابط اسم المستخدم مع تحويلها تلقائيًا إلى الرابط الآمن؛ فهذا يسلّم الرابط السري لكل من يعرف اسم المستخدم، فلا يوفر أي حماية.

| التوقيع | الاسم / الدور | التاريخ |
|---|---|---|
| مسؤول منتج الموارد البشرية | | |
| مراجع الأمن | | |

</div>
