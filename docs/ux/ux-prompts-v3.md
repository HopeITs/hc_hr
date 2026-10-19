<div dir='rtl'>


## طريقة الاستخدام والتسليم

- ابدأ بمهمة P0. المعرّفات الأساسية محفوظة للمقارنة مع نسختك، وترتيب القائمة أو الحروف لا يغني عن عمود الاعتماديات.
- يُنسخ البرومبت الكامل للمهمة مع القواعد المشتركة، أو يُشار إلى هذه النسخة عبر المسار الحقيقي للمرفق. عند اعتماد وثائق داخل المستودع، تسجل P0 مكانها المقترح docs/ux/ux-prompts-v3.md؛ لا يُفترض أن الملف موجود مسبقًا.
- مهمة واحدة ذات نتيجة مستقلة لكل فرع، لكن يجب أن يبدأ الفرع من النسخة المدمجة لمتطلباته السابقة. يمكن التخطيط لعمل مستقل بالتوازي دون أن يعني ذلك تشغيل وكلاء أو منح صلاحية دمج أو نشر.
- التوقف لقرار عمل حقيقي فقط: لا تحتاج ألوان ضمن الهوية أو أسماء ملفات أو تفاصيل قابلة للعكس إلى موافقة متكررة. الإحالات إلى مكونات أو مهارات غير موجودة تُستبدل بتحقق وإجراء موثق، لا باستدعاءات مفترضة.
- لكل مهمة عقد تنفيذ من 13 قسمًا، ومعايير قبول معرّفة، ودليل مطلوب. لا يُعد نجاح الفرع وحده نجاحًا للإصدار؛ البوابات تفحص النسخة المدمجة.
- لم تُحدد أسماء المطورين أو السعة أو موعد نهائي، لذا تقديرات الجهد نطاقات شخص-ساعة منخفضة الثقة تشمل العمل والاختبار والمراجعة. ليست جدولًا زمنيًا ملتزمًا ولا مددًا تقويمية؛ تُراجع بعد P0 والقرارات.

## بوابات المراحل

| المرحلة | النتيجة | شرط الاجتياز |
|---|---|---|
| 0 | نقطة بداية وقياسات وقرارات موثقة | P0 وA1 وX1 وX2 متاحة؛ قرارات السياسات تُحسم قبل التنفيذ المعتمد عليها فقط |
| أ | أساس موثوق للإجازات والصلاحيات والطباعة والتفاعل | G-A: لا إخفاق حرج معروف في الوصول أو الحساب أو سير العمل الأساسي |
| ب | إتمام المهام الأساسية دون فقد عمل وعلى الهاتف | G-B: نجاح المسارات الأساسية والعودة والتكرار والتعافي بالعربية والإنجليزية |
| ج | قائمة وبحث وموافقات وتقويم ولوحات متسقة | G-C: أدلة مهام مع ممثلين للأدوار، واتساق العدادات وعدم كشف بيانات خاصة |
| د | ملف وخدمة ذاتية ومساعدة؛ توسعات مختارة | أدلة قبول المهام، والتصريح بما اختير أو أُجل في PWA والرحلات |
| هـ | قياس بعدي وتسليم إصدار قابل لإعادة التحقق | G-R: اختبارات وQA على SHA واحد وخطة بيانات/تعافٍ ونطاق مؤجل معلن |

لا يكفي «تفسير» خلل حرج لتمرير بوابة إطلاق. يمكن استمرار عمل مستقل، لكن لا تُطلق ميزة ذات خلل صلاحيات أو حساب أو فقد بيانات غير محلول.

## ترتيب البداية والاعتماديات

المسار الأول: P0 ثم A1 ثم X1 وX2 قبل تغييرات UX. يمكن بعد A1 تنفيذ تحليلات A2-D وA3-D وB5-D وA6-D دون تغيير تجربة المستخدم. بعد القياس تبدأ A5 ثم B0، وبقية التبعيات كما في الفهرس.

مسار اعتماد مرشح طويل: P0 -> A1 -> X2 -> A5 -> B0 -> A3 -> G-A -> B1 -> B2 -> B5 -> M1 -> G-B -> C0 -> C1 -> C3 -> C4 -> G-C -> D4. توجد فروع لازمة أخرى، مثل قرارات الإجازات ومسودة الموظف والتقويم. هذا مسار اعتمادية مرشح، وليس ادعاء لمسار حرج زمني دون سعة ومدد معتمدة.

## القرارات المطلوبة

| Decision | Owner role | Required answer | Producer | Affected work |
|---|---|---|---|---|
| DEC-01 | HR product owner + security reviewer | Directory versus private-profile fields, role/city scope and event existence | A2-D | A2; C2; D1 |
| DEC-02 | HR product owner + security reviewer | Public card contents/token policy, disable/rotate and old printed-card migration | A2-D | A2 |
| DEC-03 | HR product owner | Leave calendar/schedule/holiday/hour/pending-balance rules and historical treatment | A3-D | A3 |
| DEC-04 | HR product owner + backend lead | Employee draft state, account/activation rules, minimum fields and explicit save/autosave | B5-D | B5 |
| DEC-05 | HR product owner + domain owners | Approval sources, reasons, batch eligibility and authoritative state transitions | C3-D | C3 |
| DEC-06 | HR product owner | Team definition, absence visibility and advisory/blocking coverage warnings | C5-D | C5 |
| DEC-07 | Product owner | Whether installability is useful enough to add without private offline caching | D2-PWA | D2-PWA only |
| DEC-08 | Product owner + privacy/security owner | Feedback access/retention; text-only default and separately chosen attachments | D3 | Feedback persistence/attachments portion of D3 |
| DEC-09 | HR product owner | First lifecycle journey slice, consequential authority and deferred increments | D4 | D4-I |
| DEC-10 | Product owner + privacy/security owner | Whether minimized telemetry is needed, identifiers/access/retention/disclosure | X2-I | X2-I only; moderated research proceeds |

## القواعد المشتركة

```text
HC-HR UX EXECUTION V3 - SHARED RULES

Context and evidence
- Existing bilingual NGO HR system, not a greenfield rewrite. Read applicable AGENTS.md and CLAUDE.md before repository work; human instructions take precedence.
- Current reviewed baseline: E:/hr/hc-hr, main, SHA 0d7cd8d70ae0bef17d90ae6902b9ebc711cadbd9, confirmed against the remote on 16 October 2026. Historical baseline only: preserve-contracts-search, SHA 7faa289a8f9248f22547d8b59d684004403401ed. Start from main and reconcile newer revisions at P0. Composer declares Laravel 13, Livewire 4, PHP ^8.5; the audit used PHP 8.5.11. AGENTS.md contains older stack details.
- Current main audit: 529 tests, 516 passed, 8 assertion failures and 5 errors, 0 skipped, using explicit English locale and isolated SQLite :memory:. All 11 WithFeedback and 3 EmployeeEditSaveFeedback tests passed. The remaining 13 checks concern access/security (5), contracts/PDF (7) and duplicate expiry notification (1); they are not accepted release exceptions. Artifacts: main-audit-junit.xml and main-audit-tests.txt beside the delivered plan folder. No authenticated browser acceptance was completed in this audit. The historical 497/13 result is retained as history, not the current baseline.
- Commits 87e31bfa, 8769e98a, dd62fa25 and 5feb02aa, including WithFeedback/toasts/confirm-dialog, are now on main. B0/B1 verify and complete this existing work. A7/B2/A5 remain partial and A5-M remains pending. Preserve all 47 prompts and IDs; implementation found in source does not by itself close a QA gate. Earlier prerequisites that said these artifacts were absent are superseded by this main audit.

Product and implementation boundaries
- Planning documents do not authorize implementation, deployment, live migrations, new access grants, sending messages or modifying real HR records. Once a task is assigned for implementation, proceed with its settled scope without asking again for routine reversible choices.
- Preserve the existing Laravel/Livewire stack, routes, local operational theme (#c2151c primary and current ramp), Somar Sans, privacy boundaries, contract capacity rules and original media. No unsolicited full redesign, recruitment module, paid service, theme replacement or offline HR write queue.
- New component/service/schema names in task outputs are PROPOSED until implemented. Reuse before adding; verify APIs against installed versions/official primary documentation when necessary. Slash-command skills named by another agent are not assumed available.

Authorization and data
- Authorize every read/write and public Livewire action on the server using the active role and allowed city/record. Keep dropdown, validation and query scoping consistent with Position::inCity and existing scope traits. Classify actions in config/access-control.php with narrow justified exemptions.
- Salary visibility uses User::canViewSalary(); never send hidden figures in HTML, Livewire payloads, search, events, timeline, notifications or PDFs. Restricted event existence/counts are also policy-controlled.
- Internal avatars use employee->avatar_url and the authorized route. Public cards use the approved ID/photo policy. Do not replace this with getFirstMediaUrl('avatar') in new UI. Never re-encode stored originals in place.
- Distinguish employee lifecycle draft from inactive employee and contract Draft type. Do not rewrite historical leave approvals or activate/deactivate staff as an incidental UX change.
- One domain service owns each authoritative write. Recheck permissions and stale state at commit/decision time. Use transactions, atomic pending-state checks and operation/idempotency keys where duplicate/concurrent writes matter. Disabled buttons are only UI feedback.

UX, Arabic and accessibility
- Prioritize complete tasks and clear next steps. Show actual workflow state/next actor, saved versus unsaved state, understandable amount/date calculations and explicit recovery after failure.
- EN and AR have equivalent functions and reviewed wording. Use translation keys, correct plural forms, logical layout utilities, isolated mixed-script values and an unmirrored logo. Search both language columns explicitly when promised; transColumn() alone selects one locale.
- Use meaningful labels, field-linked errors, correct dialog semantics/focus/return behavior, text plus visual status, reduced motion and visible focus. Product touch target is 44 CSS px; WCAG 2.2 AA's target-size criterion has a separate 24px/spacing/exceptions definition. Do not label a 44px preference as the exact AA minimum.
- Verify core flows at 1440x900, 1280x720, 375x812 and 812x375, EN/LTR and AR/RTL, with 200% text zoom, keyboard, virtual keyboard and a representative screen reader. Use applicable surfaces per task; D2 adds mobile refinement after M1 fixes blockers.
- Preserve local form input after recoverable errors, warn only when dirty, reconcile unknown-result writes after network timeouts, avoid unsafe optimistic approval/activation, and explain expired temporary uploads honestly.
- Modal work preservation (A5-M, reused by B2): editable dialogs ignore backdrop clicks. Cancel/close/Escape close clean forms locally; dirty forms offer Keep editing or Discard changes. Keep editing and confirmation dismissal preserve input. Explicit discard clears only that transient form; confirmed save updates its baseline. Cancel never navigates, reloads or submits. Preserve table filters/page/scroll and return focus. Read-only dialogs retain ordinary dismissal; saving forms remain open with local progress. This is the shared contract, not permission to add sensitive browser storage or autosubmit drafts.

Tests and integration
- Use isolated SQLite :memory: automated tests and synthetic/disposable browser fixtures on an approved local/staging target. Existing operational database and real employees are not disposable QA data.
- Capture X1/X2 baselines before the first UX change. Backend/source-only discovery can proceed while measurement is prepared. If real participants are missing, mark that evidence pending rather than inventing results.
- For behavior/data/security changes add meaningful feature/domain tests. Copy/style-only changes use targeted visual/accessibility verification rather than tests that merely mirror markup.
- Test commands on this repository include php artisan test, php artisan test --filter=..., php vendor/bin/pint --dirty, and npm run build. Use the P0-selected PHP/runtime and platform-appropriate invocation. Do not clear or modify operational configuration to make tests pass.
- Every branch starts from the latest integrated prerequisite SHAs or declared dependency refs. Use codex/ for newly requested branches. Rebase/merge and rerun dependent checks before marking a task done. Do not reset others' work, commit, push or merge unless the assignment/project workflow authorizes it.
- Review changed scope, security and cross-role/city state, EN/AR/responsive behavior, regression evidence and recovery. Record baseline and final SHA/test counts and failures. Explaining a critical defect does not satisfy a release gate.

Definition of done and escalation
- Deliver task outputs, AC evidence, targeted tests/browser proof and review; update the task's readiness and downstream input contract. All checks described in this plan are PLANNED unless actual evidence is attached during implementation.
- Stop only dependent implementation for an unresolved DEC item, contradicted business invariant, missing authorized access or unapproved irreversible change. Continue independent discovery/ready tasks. Routine styling, file organization and compatible implementation choices do not need repeated permission.
- Final implementation report: what changed, why, AC evidence, checks run, remaining limitations, migration/recovery status and next consumer inputs. Do not claim all 24 recommendations are implemented if a design-only/optional item remains deferred.
```

## فهرس المهام

| ID | المرحلة | المهمة | المالك المقترح | التبعيات | الجاهزية | الجهد بالساعات |
|---|---|---|---|---|---|---|
| P0 | 0 · خط الأساس والقرارات | تثبيت نقطة البداية والمتطلبات السابقة | Technical lead | None | Ready | 3-6 |
| A1 | 0 · خط الأساس والقرارات | تثبيت خط أساس موثوق للاختبارات | Backend developer | P0 | Conditional | 4-10 |
| X1 | 0 · خط الأساس والقرارات | قياس الأداء قبل أي تغيير في التجربة | QA/performance engineer | A1 | Conditional | 4-8 |
| X2 | 0 · خط الأساس والقرارات | قياس سهولة الاستخدام قبل التحسين | UX researcher + QA engineer | A1 | Conditional | 6-12 |
| A2-D | أ · الثقة والأساس المشترك | حسم سياسة دليل الموظفين والبطاقة العامة | HR product owner + security reviewer | P0, A1 | Conditional | 4-8 |
| A3-D | أ · الثقة والأساس المشترك | توحيد قواعد احتساب طلب الإجازة | Backend lead + HR product owner | P0, A1 | Conditional | 6-12 |
| A6-D | أ · الثقة والأساس المشترك | تشخيص إخفاقات العقود والطباعة | Backend developer | A1 | Conditional | 4-8 |
| B5-D | ب · إتمام المهام الأساسية | حسم مسودة الموظف ومتطلبات التسجيل | HR product owner + backend lead | P0, A1 | Conditional | 5-10 |
| A2 | أ · الثقة والأساس المشترك | تنفيذ سياسة الصلاحيات والهوية المعتمدة | Backend developer | A2-D | Blocked | 12-24 |
| A5 | أ · الثقة والأساس المشترك | إصلاح إتاحة النوافذ وعناصر التحكم | Frontend developer | X1, X2 | Conditional | 10-20 |
| C5-D | ج · تنظيم العمل حسب المهام | حسم خصوصية التقويم وتنبيهات التغطية | HR product owner + backend lead | A2-D, A3-D | Conditional | 4-8 |
| A3-R | أ · الثقة والأساس المشترك | تشخيص وإصلاح اختيار نوع الإجازة | Frontend/Livewire developer | A5 | Conditional | 6-12 |
| A4 | أ · الثقة والأساس المشترك | استكمال العربية والإنجليزية في المسارات الأساسية | Frontend developer + Arabic content reviewer | X1, X2, A5 | Conditional | 12-24 |
| B0 | أ · الثقة والأساس المشترك | التحقق من مكونات الرسائل المشتركة واستكمالها | Frontend/Livewire developer | A5 | Conditional | 8-16 |
| X2-I | هـ · التحقق والتسليم | خيار قياس محاولات المهام داخل التطبيق | Backend developer + QA analyst | X2, A2 | Blocked | 10-20 |
| A3 | أ · الثقة والأساس المشترك | عرض أثر الإجازة ومسار الطلب | Backend + frontend developer | A3-D, A3-R, B0 | Blocked | 18-32 |
| A6 | أ · الثقة والأساس المشترك | إصلاح طباعة العقد والحفاظ على نموذج الإنشاء | Backend + frontend developer | A6-D, A5, B0 | Conditional | 14-28 |
| A7 | أ · الثقة والأساس المشترك | إلغاء الانتظار وشاشة التحميل الحاجبة | Frontend developer | X1, X2, B0 | Conditional | 4-8 |
| A5-M | أ · الثقة والأساس المشترك | حماية إدخال النوافذ وإغلاقها دون تحديث الصفحة | Frontend/Livewire developer | B0 | Conditional | 10-20 |
| G-A | أ · الثقة والأساس المشترك | بوابة التحقق من الأساسيات | QA lead | A2, A3, A4, A6, A7, B0, A5-M | Conditional | 6-12 |
| B1 | ب · إتمام المهام الأساسية | تنفيذ التنقل السلس والتحقق منه | Frontend/Livewire developer | G-A | Conditional | 10-20 |
| B4-D | ب · إتمام المهام الأساسية | تصميم الجداول والعروض حسب المهمة | UX designer + HR product owner | G-A | Conditional | 4-8 |
| B6 | ب · إتمام المهام الأساسية | منع تكرار الإشعارات وتوضيح تقدم الطلب | Backend + frontend developer | G-A, B0 | Conditional | 12-24 |
| B2 | ب · إتمام المهام الأساسية | حماية إدخال المستخدم وتوضيح نتيجة الحفظ | Frontend/Livewire developer | B1, B0, A5-M | Conditional | 12-24 |
| B3 | ب · إتمام المهام الأساسية | توحيد التحميل والفراغ وإعادة المحاولة | Frontend/Livewire developer | B1, B0 | Conditional | 10-20 |
| B4 | ب · إتمام المهام الأساسية | تنفيذ الجداول الأخف وحفظ السياق | Frontend/Livewire developer | B4-D, B1, B3, A2 | Conditional | 16-30 |
| B5 | ب · إتمام المهام الأساسية | تنفيذ تسجيل موظف تدريجي قابل للاستكمال | Backend + frontend developer | B5-D, B2, A2 | Blocked | 22-40 |
| M1 | ب · إتمام المهام الأساسية | إصلاح الجوال للمسارات الأساسية مبكرًا | Frontend developer | B2, B3, B4, B5 | Conditional | 12-24 |
| G-B | ب · إتمام المهام الأساسية | بوابة إتمام المهام وحماية البيانات | QA lead | B1, B2, B3, B4, B5, B6, M1 | Conditional | 8-14 |
| C0 | ج · تنظيم العمل حسب المهام | اعتماد تنظيم المهام وأنماط التفاعل | UX designer + HR product owner | G-B | Conditional | 8-16 |
| C3-D | ج · تنظيم العمل حسب المهام | حسم مصادر الموافقات وقواعد القرار | Backend lead + HR product owner | A2-D, A3-D, G-B | Conditional | 6-12 |
| C1 | ج · تنظيم العمل حسب المهام | تنظيم القائمة حسب العمل الشخصي والفريق والموارد البشرية | Frontend/Livewire developer | C0, B1 | Conditional | 8-16 |
| D1-D | د · تجربة الموظف والتوسع | تصميم ملف موظف موحد بحسب الصلاحيات | UX designer + HR product owner | C0, A2, B5-D | Conditional | 6-12 |
| C2 | ج · تنظيم العمل حسب المهام | إضافة بحث ثنائي اللغة وإجراءات سريعة محمية | Backend + frontend developer | C0, C1, A2, B1, B0 | Conditional | 18-32 |
| C3 | ج · تنظيم العمل حسب المهام | تنفيذ صندوق موافقات موحد وآمن | Backend + frontend developer | C3-D, C1, B6, A3 | Blocked | 24-44 |
| C5 | ج · تنظيم العمل حسب المهام | تنفيذ تقويم الفريق وتنبيه التداخل | Backend + frontend developer | C5-D, C1, A3 | Blocked | 20-36 |
| D1 | د · تجربة الموظف والتوسع | تنفيذ الملف الموحد والخط الزمني المحمي | Backend + frontend developer | D1-D, B2, B5, A2 | Conditional | 22-40 |
| D3 | د · تجربة الموظف والتوسع | إضافة مساعدة سياقية وقناة ملاحظات منضبطة | Frontend + backend developer | C1, B0, A4 | Conditional | 14-26 |
| C4 | ج · تنظيم العمل حسب المهام | تنفيذ لوحة تحكم مناسبة لكل دور | Frontend/Livewire developer | C0, C3, C5, B3, B6 | Conditional | 12-24 |
| G-C | ج · تنظيم العمل حسب المهام | بوابة التحقق من تنظيم التشغيل | UX researcher + QA lead | C1, C2, C3, C4, C5 | Conditional | 8-16 |
| D2 | د · تجربة الموظف والتوسع | تحسين واجهة الخدمة الذاتية للجوال | Frontend developer | M1, C1, C2, C3, C4, D1 | Conditional | 14-28 |
| D2-PWA | د · تجربة الموظف والتوسع | خيار تثبيت التطبيق دون تخزين بيانات خاصة دون اتصال | Frontend developer | D2 | Blocked | 6-12 |
| D4 | د · تجربة الموظف والتوسع | تصميم رحلات التعيين والتجديد والمغادرة | HR product owner + UX designer + backend lead | G-C, B5-D, C3-D | Conditional | 8-16 |
| X1-I | هـ · التحقق والتسليم | تحسين الاختناقات المقاسة والتعافي من الشبكة | Backend + frontend developer | X1, G-B, G-C | Conditional | 16-32 |
| D4-I | د · تجربة الموظف والتوسع | تنفيذ الجزء المعتمد من الرحلات المترابطة | Backend + frontend developer | D4, C3, B5 | Blocked | 24-48 |
| X2-R | هـ · التحقق والتسليم | إعادة اختبار المستخدمين وتحديد الاحتكاك المتبقي | UX researcher + QA lead | G-C, D1, D2, D3, X1-I | Conditional | 8-16 |
| G-R | هـ · التحقق والتسليم | التحقق الشامل وتسليم حزمة الإصدار | QA lead + technical lead | X2-R, X1-I, D1, D2, D3, G-C, D4 | Conditional | 12-24 |

## برومبتات التنفيذ المفصلة

البرومبت التالي يُستخدم للمهمة المحددة فقط بعد اعتماد بدء تنفيذها واستيفاء تبعياتها.

## 0 · خط الأساس والقرارات

### P0 - تثبيت نقطة البداية والمتطلبات السابقة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

P0 - Reconcile the starting revision and implementation prerequisites

Phase: 0 - Baseline and decisions; Requirements: R-01, R-02, R-03, R-24

Owner role: Technical lead; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Ready - discovery only; repository work remains read-only. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 3-6 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: None. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Every developer starts from the same verified revision and knows which components really exist.

2. Scope and protected surfaces

Inspect: AGENTS.md; CLAUDE.md; composer.json; package.json; phpunit.xml; git history. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Record branch, full commit SHA, runtime versions and clean/dirty status without resetting local work.

2. Start from the reviewed main revision 0d7cd8d70ae0bef17d90ae6902b9ebc711cadbd9, or record and reconcile a newer main SHA. The former preserve-contracts-search baseline lacked commits 87e31bfa, 8769e98a, dd62fa25 and 5feb02aa; all four now exist on main, including WithFeedback/toasts/confirm-dialog. Verify and reuse them; do not cherry-pick or rebuild them as missing prerequisites.

3. Create proposed docs/ux/start-state.md and a component/status inventory. Identify test runtime, staging access and disposable fixtures; copy the selected v3 master into the declared plan location if repository documentation is authorized.

4. Declare the integration branch, implementer/reviewer roles and review procedure; replace unavailable slash-command skills with the equivalent documented procedure.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-P0-01: A single starting SHA and runtime manifest are recorded; no plan claim is accepted solely from its text.

AC-P0-02: Each prerequisite is verified-present, implemented-unverified, partial, missing or unknown with evidence.

AC-P0-03: A synthetic-data test target is identified; real employee records are not modified for QA.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/start-state.md; component inventory; integration/QA setup contract (all proposed outputs). Consumers: A1, A2-D, A3-D, B5-D. Mark done only with AC evidence and review.

Executor brief

When assigned to execute P0, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: None. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A1 - تثبيت خط أساس موثوق للاختبارات

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A1 - Establish a reproducible isolated test baseline

Phase: 0 - Baseline and decisions; Requirements: R-24

Owner role: Backend developer; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-10 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: P0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The same code produces an explainable test result independent of the developer’s application locale.

2. Scope and protected surfaces

Inspect: phpunit.xml; tests/TestCase.php; tests/Feature; tests/Unit. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Pin APP_LOCALE=en and fallback locale in the test configuration only, using force where required for inherited environment values. Arabic tests explicitly set their own locale. Do not edit the normal .env to fix tests.

2. Keep the existing SQLite :memory: safety guard. Run the suite and record SHA, environment, command, counts and machine-readable results.

3. Classify every failure as application defect, outdated expectation or environment issue, with evidence and a destination task. Repair only verified environment/test-harness issues here.

4. Preserve meaningful assertions; document why any expectation changes. Route security to A2, contract/PDF issues to A6 and duplicate warnings to B6.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A1-01: Tests abort if configured to use the operational database.

AC-A1-02: An inherited Arabic application locale does not change the neutral test baseline; Arabic-specific checks remain meaningful.

AC-A1-03: Every remaining failure has an owner and task; critical application defects are not waived by calling them explained.

10. Verification

Run php artisan test using the P0 runtime. Compare with an inherited Arabic locale. Store JUnit/test output outside committed product files unless agreed.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/test-baseline.md; categorized failure register; reproducible test command (proposed). Consumers: X1, X2, A2-D, A3-D, A6-D, B5-D. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A1, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: P0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### X1 - قياس الأداء قبل أي تغيير في التجربة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

X1 - Measure performance before changing the UX

Phase: 0 - Baseline and decisions; Requirements: R-01, R-02, R-23

Owner role: QA/performance engineer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-8 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Performance improvements can be compared against an unchanged, measured baseline.

2. Scope and protected surfaces

Inspect: app/Livewire/Dashboard; app/Livewire/Employee; app/Livewire/Contract; tests/Feature/Performance. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Measure dashboard, employee search, contract creation and opening the leave form with fixed synthetic data.

2. Record server time, request/query count, response size, navigation-ready time and first useful content on a documented desktop and phone profile, including a repeatable Slow 4G profile.

3. Use several runs with cold/warm conditions stated. Keep measured data separate from proposed targets; do not interpret browser-tool wall time as app latency.

4. Publish the reproduction recipe and ranked bottlenecks before proposing optimizations.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-X1-01: The report includes fixture scale, viewport, browser/runtime, network settings, run count and timestamp.

AC-X1-02: Raw measurements and medians are available for the four flows; no invented baseline is reported.

AC-X1-03: No UX/performance code is changed during baseline capture.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/performance-baseline.md and sanitized evidence (proposed). Consumers: A5, A4, A7, X1-I. Mark done only with AC evidence and review.

Executor brief

When assigned to execute X1, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### X2 - قياس سهولة الاستخدام قبل التحسين

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

X2 - Establish task-based usability baseline and success measures

Phase: 0 - Baseline and decisions; Requirements: R-15

Owner role: UX researcher + QA engineer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The team knows where users fail before improving the four highest-value tasks.

2. Scope and protected surfaces

Inspect: docs/دليل-الاستخدام.md; UX report and supplied v2 plan. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Prepare scripts for request leave, find an employee, review a request and create a contract draft; specify starting screen, scenario, stop point and success/failure rules.

2. Recruit representative HR, manager and employee participants across Arabic/English and more than one city; use disposable scenarios. Capture task success, time, errors, help and post-task ease.

3. Use the standard ten-item SUS with documented translation/source and standard scoring; label an unvalidated translation accordingly. Record sample size and limitations rather than claiming statistical proof.

4. Capture the user baseline on the reviewed current main before further A3/A4/A5/A7/B0 UI changes. Some feedback/navigation/loader changes are already merged: this is a baseline for the next delta, not a retrospective before-measurement for those changes. Source-only investigations may proceed independently. If participants are unavailable, record that limitation; do not fabricate a baseline.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-X2-01: All four task scripts have observable success, error and abandonment definitions.

AC-X2-02: Real baseline observations or an explicit pending-user-research limitation are recorded.

AC-X2-03: Leave under 60 seconds and employee lookup under 15 seconds are proposed targets, not current results or automatic release guarantees.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/usability-baseline.md; task scripts; anonymized observations; SUS scoring method (proposed). Consumers: A5, A4, A7, X2-I. Mark done only with AC evidence and review.

Executor brief

When assigned to execute X2, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

## أ · الثقة والأساس المشترك

### A2-D - حسم سياسة دليل الموظفين والبطاقة العامة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A2-D - Decide directory visibility and public digital-ID policy

Phase: A - Trust and shared foundations; Requirements: R-24

Owner role: HR product owner + security reviewer; Reviewer: HR product owner + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-01 directory visibility; DEC-02 public-ID policy before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-8 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: P0, A1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Access rules protect private HR data without breaking an intentionally broader staff directory.

2. Scope and protected surfaces

Inspect: app/Livewire/Position/ProfileDrawer.php; app/Policies/EmployeePolicy.php; app/Livewire/EmployeeCards/ShowDigitalId.php; app/Services/DigitalIdManager.php; routes/web.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Trace why ProfileDrawer checks were disabled and reproduce the original legitimate org-chart use case using fixtures.

2. Present field-by-field directory versus full-profile permissions, active-role/city boundaries and an explicit policy for whether record existence itself may be shown.

3. Inspect QR generation and printed username URLs. Compare token-only cards, explicitly public minimal cards and an authenticated migration/reissue path.

4. Do not offer unauthenticated username-to-token redirects as token protection: they disclose the token. Decide disable, rotation, expiry, logging and compatibility behavior.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes. Required decisions: DEC-01 directory visibility; DEC-02 public-ID policy.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A2-D-01: HR/security record a chosen field/action matrix and deny behavior for each role/city.

AC-A2-D-02: Existing card compatibility is decided without pretending a guessable redirect preserves secrecy.

AC-A2-D-03: Open questions are named decisions; implementation stays conditional until answers exist.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/access-policy.md with approved matrix and card migration decision (proposed). Consumers: A2, C3-D, C5-D. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A2-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: P0, A1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A3-D - توحيد قواعد احتساب طلب الإجازة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A3-D - Define one leave calculation and preview contract

Phase: A - Trust and shared foundations; Requirements: R-04, R-12

Owner role: Backend lead + HR product owner; Reviewer: HR product owner + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-03 leave accounting and holiday policy before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: P0, A1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Preview and final leave deduction use one documented policy and one authoritative calculation contract.

2. Scope and protected surfaces

Inspect: app/Models/LeaveRequest.php; app/Livewire/Forms/LeaveRequestForm.php; app/Livewire/Employee/Leave/MyLeaveRequestModule.php; app/Services/LeaveBalanceService.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Trace request duration in LeaveRequest, LeaveRequestForm and MyLeaveRequestModule. LeaveBalanceService currently generates entitlements; it is not an existing request-pricing API.

2. Specify authoritative work schedule, center weekends, holidays, end-date inclusion, calendar-day exceptions, hourly rounding and treatment of pending requests.

3. Define a proposed server-side result: unit, requested amount, available amount, pending commitments, projected balance, calculation explanation and warnings, without hidden salary data.

4. Record which rules preserve current behavior and which require HR decisions. Define how historical approved requests remain unchanged unless an authorized reconciliation is planned.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes. Required decisions: DEC-03 leave accounting and holiday policy.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A3-D-01: A table covers annual, hourly, calendar-day exceptions, weekends/holidays and non-five-day schedules.

AC-A3-D-02: Preview, validation and deduction consumers share the same proposed contract and rounding rules.

AC-A3-D-03: HR explicitly resolves policy changes; no universal Friday/Saturday weekend or five-day divisor is assumed.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/leave-calculation-contract.md; policy examples and fixture cases (proposed). Consumers: A3, C3-D, C5-D. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A3-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: P0, A1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A6-D - تشخيص إخفاقات العقود والطباعة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A6-D - Diagnose contract PDF and renewal test failures

Phase: A - Trust and shared foundations; Requirements: R-22

Owner role: Backend developer; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-8 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The team knows which contract/PDF failures are real application defects and which are stale test assumptions.

2. Scope and protected surfaces

Inspect: app/Services/ContractPdfRenderer.php; app/Support/InspectableBrowsershot.php; resources/views/components/contract; tests/Feature/ContractPdfRendererTest.php; tests/Feature/ContractPayClauseTest.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Run the contract subset and local contracts:export-doctor on synthetic/staging data only.

2. Trace remaining ArabicText imports and distinguish browser-native Arabic shaping from obsolete PDF code.

3. Trace the Browsershot HTML-versus-URL input contract before changing tests. Inspect markup assertions for create and malformed renewal parameters.

4. Deliver minimal verified fixes and proposed expectation changes as a diagnosis table consumed by A6; do not dismiss a production failure as environment noise.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A6-D-01: Each contract/PDF failure has a reproduction, root cause or explicit unresolved evidence gap.

AC-A6-D-02: The real export path is exercised without salary disclosure to unauthorized roles.

AC-A6-D-03: The table identifies exact tests and outputs A6 must repair.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/contract-failure-triage.md and sanitized doctor/export evidence (proposed). Consumers: A6. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A6-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A2 - تنفيذ سياسة الصلاحيات والهوية المعتمدة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A2 - Enforce the chosen profile and digital-ID access rules

Phase: A - Trust and shared foundations; Requirements: R-24

Owner role: Backend developer; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Blocked - DEC-01; DEC-02 must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A2-D. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Direct requests and stale sessions enforce the approved access policy on every access path.

2. Scope and protected surfaces

Inspect: app/Livewire/Position/ProfileDrawer.php; app/Policies/EmployeePolicy.php; app/Services/DigitalIdManager.php; routes/web.php; config/access-control.php; tests/Feature/Security. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement the A2-D field-specific checks on profile open and subsequent render, preserving allowed directory behavior.

2. Align route parameters, constraints, QR generation, public card lookup and signed photo handling with the chosen ID policy.

3. Classify every uncovered public Livewire action individually; exemptions require a defensible reason and cannot replace authorization.

4. Test same/other city, own record, active/inactive assignments, role switch, card disable and token rotation according to the approved policy.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-01; DEC-02.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve employee/card records. Token or route changes require an explicit compatibility/reissue migration and recovery plan; never restore old revoked tokens as a rollback shortcut.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A2-01: Disallowed profile/field access is denied on direct calls and after an active-role switch.

AC-A2-02: Disabled cards cannot resolve; rotation invalidates old bearer links when token mode is chosen.

AC-A2-03: Authorized directory use cases still work and action-coverage tests pass without weakening their purpose.

10. Verification

Run relevant authorization, ID, avatar and action-coverage tests plus synthetic direct-request tests; do not probe real staff records.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Pilot the policy on synthetic/staging data; document card reissue and route compatibility before production rollout.

13. Outputs and next-task handoff

A2 implementation/decision notes and acceptance evidence. Consumers: G-A, B4, B5, C2, D1-D, D1, X2-I. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A2, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A2-D. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A5 - إصلاح إتاحة النوافذ وعناصر التحكم

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A5 - Make shared dialogs and controls keyboard-accessible

Phase: A - Trust and shared foundations; Requirements: R-19

Owner role: Frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Partial. The new confirmation dialog has alertdialog semantics and x-trap, but shared form modals lack equivalent semantics and radios still default to id=btn. Passing markup tests do not complete this prompt. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 10-20 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: X1, X2. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

A user can operate the shared modal and form controls with keyboard and assistive technology.

2. Scope and protected surfaces

Inspect: resources/views/components/modal.blade.php; resources/views/components/modal/dialog.blade.php; resources/views/components/forms/radio-input.blade.php; tests/Feature/AccessibilityTest.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Add dialog name/role/modal semantics, initial focus, focus trapping, Escape behavior and return focus; inspect installed Alpine plugins before selecting an implementation.

2. Generate stable unique input IDs and correct label associations, including day/hour radios. Name icon buttons with translated accessible labels.

3. Lock background scroll and prevent custom scrollbars or sticky elements from covering modal controls or keyboard focus.

4. Use 44px mobile touch targets as the product target; validate WCAG 2.2 AA target-size exceptions separately. Check 200% text zoom, reflow, contrast and reduced motion.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A5-01: Keyboard-only open, navigate and close returns focus correctly in EN/AR; background controls cannot receive modal focus.

AC-A5-02: Each radio has a unique stable ID and the correct accessible name.

AC-A5-03: Buttons and errors are named/associated; focused controls remain visible at phone sizes and zoom.

10. Verification

Render-level accessibility tests plus manual keyboard and a real screen-reader pass on representative dialogs. Capture desktop/mobile EN/AR evidence; automated scans alone do not prove accessibility.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

A5 implementation/decision notes and acceptance evidence. Consumers: A3-R, A4, A6, B0. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A5, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: X1, X2. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A3-R - تشخيص وإصلاح اختيار نوع الإجازة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A3-R - Reproduce and repair the leave selector regression

Phase: A - Trust and shared foundations; Requirements: R-04

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A5. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Leave selections remain selected after Livewire updates in Arabic and English.

2. Scope and protected surfaces

Inspect: resources/views/livewire/employee/leave/form.blade.php; resources/views/components/forms/select2-input.blade.php; app/Livewire/Employee/Leave/MyLeaveRequestModule.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Reproduce Arabic annual selection reverting to the placeholder; include English and one other shared-selector form as controls.

2. Investigate placeholder value/selected state, string-versus-integer comparison, entanglement and wire:ignore lifecycle; treat these as leads, not confirmed causes.

3. Repair the smallest owning component and normalize values once. Test clear, reselect, edit prefill, modal reopen and navigation.

4. Keep this task independent of new leave policy and calculation changes.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A3-R-01: The selected balance remains correct after server updates in both locales.

AC-A3-R-02: Opening an existing request preserves its value; reopening a new form does not inherit stale state.

AC-A3-R-03: A second affected shared-selector form still works and request count does not multiply.

10. Verification

Browser reproduction with visible listbox selection and post-update state; a PHP test alone cannot prove a Select2 UI fix.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

A3-R implementation/decision notes and acceptance evidence. Consumers: A3. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A3-R, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A5. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A4 - استكمال العربية والإنجليزية في المسارات الأساسية

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A4 - Complete the bilingual and RTL experience in core journeys

Phase: A - Trust and shared foundations; Requirements: R-18

Owner role: Frontend developer + Arabic content reviewer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: X1, X2, A5. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Arabic and English users can complete the same core tasks without unintended mixed-language UI.

2. Scope and protected surfaces

Inspect: resources/lang; resources/views/livewire; resources/views/components; app/Enums; resources/views/livewire/dashboard/calendar-card.blade.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Inventory literal labels, validation, notifications, third-party widgets, enum labels and missing translation keys; distinguish legitimate proper names/code examples from untranslated UI.

2. Fix dashboard, leaves, employees, contracts and notifications by area; include calendar days/months, table empty/pagination states and Select2 search messages.

3. Use consistent status terminology and correct Arabic plural forms; isolate dates, contact strings and Latin names; use logical layout utilities and retain the unmirrored logo.

4. Review natural Arabic, long names and date ambiguity with a human content reviewer; preserve data values rather than translating stored identifiers.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A4-01: The scoped inventory contains no unexplained English UI strings in Arabic journeys.

AC-A4-02: EN/AR keys and rendered validation cover the intended meanings, including Arabic plural examples.

AC-A4-03: Long mixed-script values remain readable in RTL and keyboard order matches the displayed flow.

10. Verification

Meaningful translation checks plus EN/AR visual review. A key-parity test is a guard, not proof that translations are correct.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

A4 implementation/decision notes and acceptance evidence. Consumers: G-A, D3. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A4, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: X1, X2, A5. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B0 - التحقق من مكونات الرسائل المشتركة واستكمالها

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B0 - Verify or build a minimal shared feedback foundation

Phase: A - Trust and shared foundations; Requirements: R-03, R-05, R-19

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Implemented; browser QA pending. Shared feedback merged in 5feb02aa; 11/11 WithFeedback tests pass. Retain this prompt to verify remaining acceptance criteria and repair any demonstrated gaps. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-16 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A5. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Subsequent tasks reuse the shared feedback mechanism already implemented on main and complete any missing acceptance evidence.

2. Scope and protected surfaces

Inspect: app/Livewire/Concerns/WithFeedback.php; app/Livewire/Features/SupportToasts.php; resources/js/feedback.js; resources/views/components/toasts.blade.php; resources/views/components/confirm-dialog.blade.php; app/Providers/AppServiceProvider.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Verify and reuse app/Livewire/Concerns/WithFeedback.php, app/Livewire/Features/SupportToasts.php, resources/js/feedback.js and the shared toasts/confirm-dialog views already merged in 5feb02aa. The current audit passed all 11 WithFeedback tests. Verify the selected revision and remaining EN/AR browser/focus/navigation acceptance checks; repair gaps rather than introducing a second feedback system.

2. Provide translated success/error toast and confirmation dialog behavior with live announcements, correct focus and no repeated listener registration.

3. Separate server authorization/transaction ownership from confirmation UI. Define the actual method/event names consumers will call.

4. Document limited reversible-undo support; do not migrate every screen or introduce arbitrary compensation logic here.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B0-01: The feedback component/API exists on the integrated branch and is documented with real names.

AC-B0-02: Success is announced, confirmations are keyboard accessible and navigation does not duplicate messages.

AC-B0-03: Failed actions preserve recoverable state and confirmations cannot bypass authorization.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

Verified feedback API contract, shared components and tests; actual names recorded for consumers. Consumers: A3, A6, A7, A5-M, G-A, B2, B3, B6, C2, D3. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B0, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A5. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A3 - عرض أثر الإجازة ومسار الطلب

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A3 - Show an accurate leave-impact summary and request status path

Phase: A - Trust and shared foundations; Requirements: R-04

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Blocked - DEC-03 must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 18-32 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A3-D, A3-R, B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Before submitting, employees understand the duration, remaining balance and actual next step.

2. Scope and protected surfaces

Inspect: app/Models/LeaveRequest.php; app/Livewire/Forms/LeaveRequestForm.php; app/Livewire/Employee/Leave; resources/views/livewire/employee/leave. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement the A3-D calculation contract in a shared server-side owner used for preview, validation and final accounting; keep existing entitlement generation distinct.

2. Show available/requested/projected balance beside dates, with explained exclusions. Distinguish pending commitments and proposed from final values.

3. Show field-linked errors for reversed dates, own overlap and balance shortfall. Use actual workflow states and next actor; never invent a universal linear approval chain.

4. Cancel closes locally. Preserve unsent input on failed validation; show a clear submitted status/next action after success and protect against duplicate writes. Team coverage integration follows C5.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-03.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Balance changed in another session, partial form, no eligible balance, locale switch and request retry have explicit outcomes.

7. Data integrity

Use transactional authoritative checks where balances/states are changed. Recheck at submit/decision time; preserve historical approvals and audit records.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A3-01: Preview and committed deduction match for every A3-D fixture, including hourly and calendar-day exceptions.

AC-A3-02: Reversed dates or insufficient balance explain the exact problem without discarding input.

AC-A3-03: Success identifies the actual status and next actor; duplicate submission creates one request and one set of notifications.

10. Verification

Shared calculation/Livewire tests plus EN/AR desktop/375px browser flows on disposable users. Capture amount preview versus persisted amount.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

A3 implementation/decision notes and acceptance evidence. Consumers: G-A, C3, C5. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A3, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A3-D, A3-R, B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A6 - إصلاح طباعة العقد والحفاظ على نموذج الإنشاء

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A6 - Repair contract export and preserve the three-section composer

Phase: A - Trust and shared foundations; Requirements: R-22

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 14-28 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A6-D, A5, B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Contract review and printed documents match authorized contract data, with understandable export recovery.

2. Scope and protected surfaces

Inspect: app/Services/ContractPdfRenderer.php; app/Http/Controllers/ContractExportController.php; app/Livewire/Contract; resources/views/pdf/contract.blade.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Fix verified A6-D defects; remove obsolete shaping only after real Arabic export proof. Preserve savePdf/disableCaptureURLS behavior and small stdout.

2. Keep the existing assignment/schedule/pay sections, completion counter and summary. Explain city/program/position/capacity dependencies and draft/auto-renew status.

3. Show translated local loading/error/retry feedback without creating a new contract on export retry.

4. Check annual and service contracts, Arabic and English, plus renewal and malformed query handling. Withhold figures in every payload for roles that cannot view salary.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Export is read-only with respect to contract creation. Preserve original media, contract history and existing pay visibility rules.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A6-01: Representative Arabic/English PDFs are non-empty, readable and match approved contract figures and terms.

AC-A6-02: Unauthorized salary viewers cannot fetch the PDF or hidden pay payloads.

AC-A6-03: A failed export can be retried against the same contract; meaningful contract regression checks pass.

10. Verification

Unit/feature coverage plus visual inspection of actual printed pages; mocked renderer tests alone do not validate PDF appearance.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

A6 implementation/decision notes and acceptance evidence. Consumers: G-A. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A6, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A6-D, A5, B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A7 - إلغاء الانتظار وشاشة التحميل الحاجبة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A7 - Remove artificial page-covering navigation delays

Phase: A - Trust and shared foundations; Requirements: R-01

Owner role: Frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Partial. 87e31bfa shortened the wait, but main retains a page-covering video with 300ms minimum visibility plus 250ms fade. Complete the original outcome. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-8 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: X1, X2, B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Ready content is never hidden behind decorative loading animation.

2. Scope and protected surfaces

Inspect: resources/views/components/layouts/app.blade.php; resources/js/app.js; resources/js/product-tour.js. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Inspect the actual loader state on current main: MIN_VISIBLE=300 plus FADE=250, with the full-screen video retained. The 1500ms value belongs to the historical preserve-contracts-search baseline. Keep the shortening already merged and finish removing the artificial wait/covering overlay.

2. Remove forced waiting and page-covering video on navigation; retain a small progress indicator and local pending feedback.

3. Handle reduced motion and failed navigation without a stranded overlay. Do not add a welcome animation unless separately chosen.

4. Repeat the X1 navigation measurements and capture before/after evidence.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A7-01: No fixed minimum delay or decorative overlay hides ready content.

AC-A7-02: Dashboard-to-employees-to-contracts navigation exposes clear progress without trapping input.

AC-A7-03: Slow/error navigation and reduced-motion mode recover correctly.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

A7 implementation/decision notes and acceptance evidence. Consumers: G-A. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A7, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: X1, X2, B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### A5-M - حماية إدخال النوافذ وإغلاقها دون تحديث الصفحة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

A5-M - Protect modal input and close without refreshing the page

Phase: A - Trust and shared foundations; Requirements: R-03, R-18, R-19

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Pending. Shared editable-modal backdrop/Escape still dismiss immediately; leave Cancel still links to leaves.index. With primary-button now using wire:navigate, this is same-page navigation rather than a proven full-document reload. Local close and input protection are still required. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 10-20 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users leave editable dialogs deliberately, without accidental input loss or a page reload. This is an approved plan addition, not an implemented fix.

2. Scope and protected surfaces

Inspect: resources/views/components/modal.blade.php; resources/views/components/modal/dialog.blade.php; resources/views/components/modal/cancel-btn.blade.php; resources/views/components/primary-button.blade.php; resources/views/livewire/employee/leave/index.blade.php; resources/views/livewire/employee/leave/form.blade.php; app/Livewire/Employee/Leave/MyLeaveRequestModule.php; existing translation files; inventoried modal consumers. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Inventory shared-modal consumers and classify editable forms versus read-only dialogs. Start with My Leaves, then verify each migrated form. Source review found unconditional backdrop/Escape dismissal in modal.blade.php, a reset in MyLeaveRequestModule::create(), and a Cancel href to leaves.index in employee/leave/index.blade.php. Live reproduction was blocked by sign-in; reproduce on synthetic authenticated fixtures before changing behavior.

2. Use the A5 accessibility behavior and documented B0 confirmation API to provide one close-request path. For editable dialogs, ignore backdrop clicks. Route Cancel, the translated close button and Escape through a dirty-state check. Keep ordinary read-only dialogs dismissible; do not globally disable their normal close behavior.

3. Compare normalized current values with the initial new/edit snapshot, including selectors, dates, text and attachments. Changed-then-restored values are clean. Use an in-memory, per-form state owner; preserve entries through Livewire renders and update the baseline after successful saves. Do not store sensitive HR fields in browser localStorage as an incidental draft feature.

4. If clean, close locally without reload or navigation. If dirty, show: Discard your changes? / Your changes have not been saved. / Keep editing / Discard changes. Arabic: تجاهل التغييرات؟ / لم يتم حفظ تغييراتك. / متابعة التعديل / تجاهل التغييرات. Default focus goes to Keep editing. Escape or dismissing this confirmation returns to editing with all values intact; avoid simultaneous active focus traps.

5. Reset only after an explicit Discard changes decision or a confirmed successful save. Cancel and Discard never submit the form or write an HR record. During an in-flight save, keep the dialog open with local progress; after failure preserve entries, and after an unknown-result timeout reconcile the request before retrying.

6. Replace modal Cancel links to leaves.index with non-submit close controls. Closing preserves the background table filters, pagination and scroll, and returns focus to the opener. Coordinate the common dirty-state API with B2 so navigation and modal guards do not produce duplicate prompts.

7. Use Request leave / طلب إجازة for new-request entry and dialog title, and Submit request / إرسال الطلب for new submissions. Existing requests use Edit leave request / تعديل طلب الإجازة and Save changes / حفظ التغييرات. Add reviewed translation keys; do not change the approval workflow or imply that a draft is submitted.

4. Business rules and decisions

Editable forms ignore backdrop clicks. Explicit close actions close clean forms or guard dirty forms. Dirty means a difference from the initial saved/new snapshot, not merely that an input event occurred. Reset only on explicit discard or confirmed successful save. Read-only dialogs retain normal dismissal. No cross-session draft persistence is introduced by this task.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover new/edit forms; changed-then-restored values; each close route; confirmation dismissal; Select2 and date values after Livewire renders; pending save, failed save and ambiguous timeout; repeated open/close; multiple modal instances; background filter/page/scroll retention. Explain expired temporary uploads without pretending browser file inputs can always be restored.

7. Data integrity

Close and discard only affect transient UI state. Preserve saved HR records, permissions, approval rules and notification behavior. Never implement cancel by submitting, redirecting or clearing unrelated component state.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-A5-M-01: Given a new or edited form with entered values, clicking the backdrop keeps it open and retains every value; opening read-only details still permits normal dismissal.

AC-A5-M-02: For a pristine form, or an edited form restored to its original values, Cancel, close and Escape close immediately without confirmation, page navigation, document reload or an HR write.

AC-A5-M-03: For a dirty form, each explicit close route presents exactly one confirmation. Keep editing, Escape or dismissing that confirmation retains all values, validation state and the underlying form; initial confirmation focus is on Keep editing.

AC-A5-M-04: Discard changes closes and clears only the current form. Reopening a new request starts clean; reopening an existing request loads its saved values. No other open form is reset.

AC-A5-M-05: Closing preserves background table filters, current page and scroll, then returns keyboard focus to the opener. Cancel is non-submit and does not point to leaves.index.

AC-A5-M-06: While saving, accidental close attempts do not interrupt or clear the form. Validation/server failures preserve values and errors; successful save resets the baseline; ambiguous timeouts cannot create duplicate requests.

AC-A5-M-07: All close and confirmation cases pass in EN/LTR and AR/RTL, at desktop and phone sizes, with keyboard and a representative screen reader; translated controls remain reachable with the phone keyboard open.

AC-A5-M-08: New-request and edit-request labels match their actual action in both languages; closing, discarding and failed validation do not create or update an HR record.

10. Verification

Planned: authenticated synthetic browser checks mapped to all eight ACs, including navigation/network observation proving no document reload on Cancel. Add meaningful state-lifecycle tests where server state is involved; markup-only assertions are insufficient. Capture EN/AR desktop/mobile and keyboard/screen-reader evidence. Current review is source-only, not a passing browser test.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Migrate the leave form first, then the inventoried editable consumers using the same contract; verify read-only consumers after the shared change. This reversible UI fix requires no data migration.

13. Outputs and next-task handoff

Documented shared modal/dirty-close API, editable/read-only consumer inventory, updated leave labels and AC evidence; B2 reuses this API for navigation protection. Consumers: G-A, B2. Mark done only with AC evidence and review.

Executor brief

When assigned to execute A5-M, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### G-A - بوابة التحقق من الأساسيات

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

G-A - Verify the foundation milestone on the integrated branch

Phase: A - Trust and shared foundations; Requirements: R-01, R-03, R-04, R-18, R-19, R-22, R-24

Owner role: QA lead; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A2, A3, A4, A6, A7, B0, A5-M. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The foundation milestone has integrated evidence rather than isolated branch successes.

2. Scope and protected surfaces

Inspect: Integrated branch; docs/ux/test-baseline.md; milestone evidence. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Retest access, leave accounting, Arabic selector, PDF output, shared feedback and keyboard dialogs on the integrated SHA. Include A5-M backdrop, dirty close, discard, save-failure and Cancel-without-reload evidence.

2. Run the complete suite and classify remaining failures by severity and owner. Security, data integrity and essential export defects cannot pass on explanation alone.

3. Record phone EN/AR smoke checks and unresolved non-critical defects with explicit disposition.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-G-A-01: No known critical access, accounting or essential workflow defect remains in the milestone.

AC-G-A-02: The test report references the integrated SHA and actual browser evidence.

AC-G-A-03: A written gate result lists passed checks, failures and any accepted non-critical limitations.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

G-A implementation/decision notes and acceptance evidence. Consumers: B1, B4-D, B6. Mark done only with AC evidence and review.

Executor brief

When assigned to execute G-A, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A2, A3, A4, A6, A7, B0, A5-M. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

## ب · إتمام المهام الأساسية

### B5-D - حسم مسودة الموظف ومتطلبات التسجيل

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B5-D - Decide employee draft lifecycle and minimal onboarding

Phase: B - Core task completion; Requirements: R-17

Owner role: HR product owner + backend lead; Reviewer: HR product owner + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-04 employee lifecycle and draft saving before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 5-10 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: P0, A1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

A resumable employee record has explicit rules separate from inactivity and draft contracts.

2. Scope and protected surfaces

Inspect: app/Livewire/Forms/Employee/PersonalInfoForm.php; app/Livewire/Forms/Employee/DraftContractForm.php; app/Livewire/Employee/EmployeeCreate.php; tests/Feature/EmployeeCreateDraftContractTest.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Map required validation, identity/contact dependencies, existing active flags, account creation and DraftContractForm behavior.

2. Choose minimum first-step fields, draft ownership/visibility, who may resume/delete/activate, and whether saving can create an account.

3. Define any new lifecycle state separately from is_active and contract Draft type; decide historical-record/backfill treatment and activation preconditions.

4. Recommend explicit Save draft plus a visible saved timestamp before introducing autosave; choose a policy with HR rather than assuming it.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes. Required decisions: DEC-04 employee lifecycle and draft saving.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B5-D-01: The state/transition table distinguishes employee draft, inactive employee and draft contract.

AC-B5-D-02: Existing staff and accounts retain defined behavior after rollout.

AC-B5-D-03: HR decides required fields, activation authority and save/resume behavior before B5 implementation.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/employee-lifecycle.md; minimum-field and historical-data matrix (proposed). Consumers: B5, D1-D, D4. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B5-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: P0, A1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B1 - تنفيذ التنقل السلس والتحقق منه

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B1 - Implement or verify navigate-safe internal navigation

Phase: B - Core task completion; Requirements: R-02

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Implemented; browser QA pending. Sidebar/in-page navigation merged in 8769e98a and dd62fa25. Repeated navigation, widget lifecycle, EN/AR and history checks still need browser evidence. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 10-20 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: G-A. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Internal navigation preserves useful context and all widgets keep working after repeated visits.

2. Scope and protected surfaces

Inspect: resources/views/livewire/layout/sidebar.blade.php; resources/views/components/layouts/app/sidebar; resources/js/app.js; resources/js/product-tour.js. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Navigation commits 8769e98a and dd62fa25 are now merged on reviewed main. Verify existing sidebar/in-page wire:navigate behavior and widget lifecycle updates. Inventory intentional full loads and remaining gaps; do not reapply the merged commits or rebuild working navigation.

2. Use lifecycle-safe initialization/cleanup for tables, Select2, pickers, charts, tags, Swiper and tours; retain full loads for appropriate downloads/external destinations.

3. Check back/forward/refresh, deep links and repeated page visits; one interaction must not create duplicate requests.

4. Preserve route authorization and role/locale context; resolve known failures one owning cause at a time.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B1-01: All scoped internal links have a documented navigation behavior and no broken widgets.

AC-B1-02: Back/forward and direct links restore the intended screen without stale role data.

AC-B1-03: Repeated navigation produces no duplicate listener effects or relevant console errors.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

B1 implementation/decision notes and acceptance evidence. Consumers: B2, B3, B4, G-B, C1, C2. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B1, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: G-A. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B4-D - تصميم الجداول والعروض حسب المهمة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B4-D - Design task-focused tables and view persistence

Phase: B - Core task completion; Requirements: R-16

Owner role: UX designer + HR product owner; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-8 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: G-A. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Default table columns and saved views reflect real tasks rather than every database attribute.

2. Scope and protected surfaces

Inspect: app/Livewire/Employee/EmployeeTable.php; app/Livewire/Contract/ContractsTable.php; app/Livewire/Employee/Leave. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Inventory employee/contract/leave columns, actions and existing picker/persistence capabilities.

2. Choose compact defaults per table; do not force the same name/position schema onto unrelated tables. Show essential actions without horizontal hunting.

3. Specify search/sort/filter/page restoration, canonical URL versus local preference storage and safe reset when permissions/dataset change.

4. Approve a small set of named presets and a phone representation; mark personalized saved views as a separate complexity decision.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B4-D-01: Each table has an agreed default/detail/mobile layout and action hierarchy.

AC-B4-D-02: State persistence and stale-page reset rules are documented.

AC-B4-D-03: Saved view meanings and authorized scopes are explicit.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/table-contract.md; EN/AR desktop/phone wireframes (proposed). Consumers: B4. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B4-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: G-A. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B6 - منع تكرار الإشعارات وتوضيح تقدم الطلب

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B6 - Prevent duplicate notifications and show clear request progress

Phase: B - Core task completion; Requirements: R-20

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: G-A, B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Notifications identify the correct record, its current state and the action the recipient can take.

2. Scope and protected surfaces

Inspect: app/Console/Commands; app/Notifications; app/Livewire/Concerns/HandlesNotificationActions.php; tests/Feature/NotificationNavigationTest.php; tests/Feature/ContractAutoRenewalWorkflowTest.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Reproduce duplicate contract-expiry warnings and distinguish multiple roles, repeated runs and grouped summaries.

2. Enforce an atomic once-per-contract/recipient/day policy using the agreed application timezone; cover overlapping workers and retries, not only sequential runs.

3. Group similar alerts without losing record-level navigation or authorization. Distinguish unread from still-needs-action.

4. Show the actual request status/next actor and update handled state after resolution; omit confidential leave reasons from generic notifications.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Deduplication needs a durable atomic mechanism and stable idempotency key. Preserve historical audit/notifications; do not delete history to hide duplicates.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B6-01: Repeated or concurrent runs do not duplicate the same warning for one contract/recipient/day.

AC-B6-02: Each notification opens the intended authorized record; resolved requests are not presented as awaiting action.

AC-B6-03: Grouping, role changes, inaccessible records and missing/deleted targets have safe translated outcomes.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

B6 implementation/decision notes and acceptance evidence. Consumers: G-B, C3, C4. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B6, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: G-A, B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B2 - حماية إدخال المستخدم وتوضيح نتيجة الحفظ

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B2 - Preserve form work and provide actionable save feedback

Phase: B - Core task completion; Requirements: R-03

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Partial. Shared save feedback/error summaries exist and 3/3 employee save-feedback tests pass. Field-linked clickable summaries and dirty navigation/unload protection are still missing. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B1, B0, A5-M. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users can fix errors and leave forms deliberately without losing unexplained work.

2. Scope and protected surfaces

Inspect: resources/views/components/forms/errors-validation-list.blade.php; resources/views/components/forms/dirty-gate.blade.php; app/Livewire/Employee/EmployeeEdit.php; app/Livewire/Contract. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Create field-linked error summaries for long employee/contract/leave forms; activate the correct section and focus the field.

2. Reuse the A5-M dirty-state/confirmation contract for navigation and browser unload; do not build a second modal-close mechanism. Preserve dirty protection after failed saves; clear or rebaseline only after explicit discard or a confirmed successful save. Native browser-unload wording is browser-controlled and cannot promise the custom dialog text.

3. Preserve input on validation/server failures; explain temporary upload expiry and safe reattachment rather than promising browsers can retain files indefinitely.

4. Disable pending submit controls and add server-side duplicate protection where writes require it. Offer undo only for approved reversible operations.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Do not implement duplicate protection solely by disabling a button. Undo must reauthorize and respect later edits; use supported reversible actions only.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B2-01: Each summary error reaches its field even in another tab/section.

AC-B2-02: Unchanged forms do not warn; changed forms warn and successful saves reset the guard.

AC-B2-03: Failures preserve normal inputs and explain any file reattachment; retry does not duplicate records.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

B2 implementation/decision notes and acceptance evidence. Consumers: B5, M1, G-B, D1. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B2, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B1, B0, A5-M. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B3 - توحيد التحميل والفراغ وإعادة المحاولة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B3 - Add useful loading, empty and retry states

Phase: B - Core task completion; Requirements: R-05

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 10-20 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B1, B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Waiting, no data, filtered-out results and failed loading are visibly different states.

2. Scope and protected surfaces

Inspect: resources/views/components/center-empty-state.blade.php; app/Livewire/Dashboard; resources/views/livewire/employee; resources/views/livewire/contract. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Reuse or extend the existing empty-state component; add local skeleton/spinner patterns with reduced-motion support.

2. Choose lazy loading only for independently useful cards after measuring dependency/query effects; do not automatically mark every card lazy.

3. Apply distinct no-records/no-search-results states and permission-aware next actions to dashboard, leave, employee, contract, checklist and notification screens.

4. Show recoverable load failures with safe retry; maintain stable layout and announce state changes.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B3-01: Each scoped page distinguishes loading, empty dataset, filtered empty result and load error.

AC-B3-02: Clear filters restores records and empty-state actions match real permissions.

AC-B3-03: Slow loading does not block the entire dashboard or create duplicate writes/listeners.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

B3 implementation/decision notes and acceptance evidence. Consumers: B4, M1, G-B, C4. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B3, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B1, B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B4 - تنفيذ الجداول الأخف وحفظ السياق

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B4 - Build compact tables, preserved context and named views

Phase: B - Core task completion; Requirements: R-16

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 16-30 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B4-D, B1, B3, A2. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Opening details and returning preserves the user’s search and position without exposing other scopes.

2. Scope and protected surfaces

Inspect: app/Livewire/Employee/EmployeeTable.php; app/Livewire/Contract/ContractsTable.php; resources/views/livewire/employee; resources/views/livewire/contract. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement the approved defaults using existing table capabilities before custom replacements.

2. Add accessible detail drawers, visible filter chips and Clear all; name all row actions and preserve authorized avatar URLs.

3. Persist the B4-D state with user/role/city isolation. Reset invalid filters or out-of-range pages safely after dataset/role changes.

4. Implement approved named presets; use mobile cards or a concise comparison view where justified rather than shrinking every column.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B4-01: Search page 3 -> open details -> return restores search, filters, sort and page.

AC-B4-02: A role/city switch never restores unauthorized cached results.

AC-B4-03: Primary identity and actions remain reachable at 375px; no page-level sideways scrolling is required for the common task.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

B4 implementation/decision notes and acceptance evidence. Consumers: M1, G-B. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B4, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B4-D, B1, B3, A2. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### B5 - تنفيذ تسجيل موظف تدريجي قابل للاستكمال

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

B5 - Implement resumable employee onboarding

Phase: B - Core task completion; Requirements: R-17

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Blocked - DEC-04 must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 22-40 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B5-D, B2, A2. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

HR can save the approved minimum record and resume later without accidental activation or lost input.

2. Scope and protected surfaces

Inspect: app/Livewire/Employee/EmployeeCreate.php; app/Livewire/Employee/EmployeeEdit.php; app/Livewire/Forms/Employee; resources/views/livewire/employee/form.blade.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement B5-D lifecycle, migrations and authorization without reusing is_active as an undocumented draft marker.

2. Replace disabled-tab stacks with progressive sections, clear completion states and reasons for dependencies.

3. Show save state and meaningful next action; preserve section inputs and prevent duplicate accounts/draft records.

4. Keep contract-draft reservation rules separate. Test activation eligibility and legacy staff behavior.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-04.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Use additive migrations with tested backfill/recovery. Account creation and activation must be transactional/idempotent; never bulk-deactivate existing staff.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-B5-01: The approved minimum can be saved and resumed by an authorized HR user.

AC-B5-02: Incomplete drafts cannot activate staff/accounts/contracts contrary to DEC-04.

AC-B5-03: Existing active/inactive employees and contract draft upgrades retain their expected behavior.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Pilot with synthetic legacy and new records; verify restore/backfill before any live migration is separately authorized.

13. Outputs and next-task handoff

B5 implementation/decision notes and acceptance evidence. Consumers: M1, G-B, D1, D4-I. Mark done only with AC evidence and review.

Executor brief

When assigned to execute B5, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B5-D, B2, A2. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### M1 - إصلاح الجوال للمسارات الأساسية مبكرًا

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

M1 - Make the essential journeys usable on phones now

Phase: B - Core task completion; Requirements: R-13, R-17, R-19

Owner role: Frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B2, B3, B4, B5. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Phone users can complete the core flows before the later mobile-shell enhancement.

2. Scope and protected surfaces

Inspect: resources/views/livewire/employee; resources/views/livewire/contract; resources/views/livewire/layout/header.blade.php; resources/views/components/modal.blade.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Test leave request, employee create/resume, list/details, contract review and notifications at 375x812 and 812x375 in EN/AR.

2. Remove page overflow, clipped header controls and disabled-section stacks; use concise section navigation and full-width fields.

3. Keep summaries and submit actions reachable without obscuring focused fields, virtual-keyboard content or safe areas.

4. Use local full-screen sheets where suitable and verify desktop regressions; do not wait for D2 to fix task-blocking phone issues.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-M1-01: Core tasks complete on portrait and landscape phones without page-level horizontal scrolling.

AC-M1-02: Focus, errors and submit controls remain visible with zoom/virtual keyboard.

AC-M1-03: Desktop behavior and authorization remain correct.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

M1 implementation/decision notes and acceptance evidence. Consumers: G-B, D2. Mark done only with AC evidence and review.

Executor brief

When assigned to execute M1, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B2, B3, B4, B5. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### G-B - بوابة إتمام المهام وحماية البيانات

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

G-B - Verify core task completion and data recovery

Phase: B - Core task completion; Requirements: R-02, R-03, R-04, R-05, R-13, R-16, R-17, R-20

Owner role: QA lead; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-14 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: B1, B2, B3, B4, B5, B6, M1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Core workflows are usable in both languages and recover safely from common failures.

2. Scope and protected surfaces

Inspect: Integrated branch and X2 task scripts. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Run the four X2 scripts with fixtures and repeat navigation, validation, retry and role-switch checks.

2. Verify duplicate-submit, two-session stale data, table state and mobile/keyboard scenarios.

3. Publish integrated regression evidence and defect disposition; release-blocking failures must be resolved.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-G-B-01: The four tasks complete in EN/AR on desktop and phone with expected permissions.

AC-G-B-02: Recovery tests preserve input and accounting; requests/accounts are not duplicated.

AC-G-B-03: The milestone result cites one integrated SHA and measurable evidence.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

G-B implementation/decision notes and acceptance evidence. Consumers: C0, C3-D, X1-I. Mark done only with AC evidence and review.

Executor brief

When assigned to execute G-B, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: B1, B2, B3, B4, B5, B6, M1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

## ج · تنظيم العمل حسب المهام

### C5-D - حسم خصوصية التقويم وتنبيهات التغطية

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C5-D - Decide team-calendar visibility and coverage warning policy

Phase: C - Task-oriented operations; Requirements: R-12

Owner role: HR product owner + backend lead; Reviewer: HR product owner + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-06 team calendar and coverage before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 4-8 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A2-D, A3-D. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Team absence information helps scheduling without exposing private leave details.

2. Scope and protected surfaces

Inspect: app/Livewire/Dashboard/CalendarCard.php; app/Livewire/Dashboard/UpcomingLeavesCard.php; app/Policies/LeaveRequestPolicy.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Define team membership, cross-city/manager coverage, holiday source and whose calendars each role may see.

2. Decide advisory versus blocking coverage rules and whether pending requests count; specify what absence type/status may be shown.

3. Default to aggregate coverage and omit medical/private reasons; decide whether unauthorized event existence must be hidden.

4. Define a proposed range/overlap query contract reusable by calendar, dashboard and leave preview.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes. Required decisions: DEC-06 team calendar and coverage.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C5-D-01: Visibility and warning/blocking rules are explicit HR decisions.

AC-C5-D-02: The projection excludes restricted reasons and unauthorized event metadata.

AC-C5-D-03: Calendar, dashboard and request-preview consumers share the same documented range/overlap semantics.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/team-coverage-contract.md (proposed). Consumers: C5. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C5-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A2-D, A3-D. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C0 - اعتماد تنظيم المهام وأنماط التفاعل

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C0 - Validate information architecture and interaction patterns

Phase: C - Task-oriented operations; Requirements: R-06, R-07, R-08, R-09, R-10

Owner role: UX designer + HR product owner; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-16 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: G-B. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Navigation, quick actions, approvals and dashboard use one understandable task structure.

2. Scope and protected surfaces

Inspect: resources/views/livewire/layout/sidebar.blade.php; resources/views/livewire/layout/header.blade.php; resources/views/livewire/dashboard/index.blade.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Map employee, manager, regional HR, nationwide HR and administrator journeys to My space, My team, HR operations and Settings.

2. Prototype navigation, header search/New, work inbox and role dashboard in EN/AR at desktop/phone sizes; retain current brand tokens and Somar Sans.

3. Use short scenario checks with representative users to confirm names and findability; avoid adding more equally weighted dashboard cards.

4. Document stable route/action IDs, entry/return paths and protected legacy access; decide MVP design without discretionary feature creep.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C0-01: Each role has an agreed task/permission map and first-screen priorities.

AC-C0-02: Search/New and inbox/dashboard use consistent terminology and entry/return behavior.

AC-C0-03: Evidence records what users could find and any revised labels.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/interaction-contract.md; role/task map; EN/AR prototypes and findings (proposed). Consumers: C1, C2, C4, D1-D. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C0, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: G-B. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C3-D - حسم مصادر الموافقات وقواعد القرار

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C3-D - Specify a shared approval-source and decision contract

Phase: C - Task-oriented operations; Requirements: R-09

Owner role: Backend lead + HR product owner; Reviewer: HR product owner + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-05 approval and bulk-action policy before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: A2-D, A3-D, G-B. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

A unified inbox reuses domain decisions rather than becoming a second approval engine.

2. Scope and protected surfaces

Inspect: app/Livewire/Employee/Leave/UpdateLeaveRequest.php; app/Livewire/Forms/LeaveRequestForm.php; app/Services/ChecklistWorkflow.php; app/Services/LeaveNotificationService.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Inventory actual sources: leave requests and checklist deliveries/returns; recruitment is not an existing module in this checkout.

2. Define each source’s stable key, list projection, authorized scope, state/version, capabilities, decision method and notification/history ownership.

3. Specify stale/concurrent decision handling, atomic balance/asset effects, required rejection reasons and partial batch outcomes.

4. Choose which low-risk sources may use bulk actions; distinguish preview/confirmation from final decision and record audit rules.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes. Required decisions: DEC-05 approval and bulk-action policy.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C3-D-01: Every supported source has one authoritative decision owner and a shared projection contract.

AC-C3-D-02: Authorization, conflict/idempotency and batch semantics are agreed for each source.

AC-C3-D-03: Bulk capability is explicit; no fake recruitment provider is added.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/approval-source-contract.md and source/permission matrix (proposed). Consumers: C3, D4. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C3-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: A2-D, A3-D, G-B. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C1 - تنظيم القائمة حسب العمل الشخصي والفريق والموارد البشرية

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C1 - Organize navigation by personal, team and HR work

Phase: C - Task-oriented operations; Requirements: R-06

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-16 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C0, B1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users find frequent work without navigating through configuration tables.

2. Scope and protected surfaces

Inspect: resources/views/livewire/layout/sidebar.blade.php; resources/views/livewire/layout/header.blade.php; resources/js/product-tour.js. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Apply C0 groups and settings entry using current permission checks; do not change route access merely to match a menu.

2. Show active role/city context clearly and preserve collapsed sidebar and phone access.

3. Update tour targets, breadcrumbs and direct links. Keep every authorized existing function reachable.

4. Use action-oriented translated labels and test visibility after an active-role switch.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C1-01: Visible navigation matches what the active role can open.

AC-C1-02: Settings and all authorized old functions remain reachable.

AC-C1-03: Collapsed/mobile navigation, breadcrumbs and tours remain usable in EN/AR.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

C1 implementation/decision notes and acceptance evidence. Consumers: C2, C3, C5, G-C, D2, D3. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C1, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C0, B1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C2 - إضافة بحث ثنائي اللغة وإجراءات سريعة محمية

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C2 - Add authorized bilingual search and contextual quick actions

Phase: C - Task-oriented operations; Requirements: R-07, R-08

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 18-32 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C0, C1, A2, B1, B0. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users find authorized people/contracts and start frequent tasks from anywhere.

2. Scope and protected surfaces

Inspect: app/Support/helpers.php; app/Providers/AppServiceProvider.php; app/Livewire/Concerns/ScopesToAuthorizedCity.php; resources/views/livewire/layout/header.blade.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Create a verified page/action registry with the same server authorization semantics as navigation; use it for search actions and New.

2. Search both physical Arabic and English name columns explicitly. transColumn selects one locale and is not sufficient for bilingual matching. Include city/position disambiguation, debounce, limits and query scoping.

3. Implement visible search plus Ctrl/Cmd+K with accessible keyboard/listbox behavior and translated loading/empty/error states.

4. Use allowlisted internal return destinations; completion/cancel returns to a valid authorized originating context. Clear result caches on role changes.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

No results, duplicate names, unavailable record, throttled search and invalid return URL have defined safe outcomes.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C2-01: An English name can find the permitted employee in Arabic UI and vice versa.

AC-C2-02: Queries never return disallowed city/private data, including in previews and role-switch caches.

AC-C2-03: Keyboard selection and New start the correct permitted workflow and safely restore context.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

C2 implementation/decision notes and acceptance evidence. Consumers: G-C, D2. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C2, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C0, C1, A2, B1, B0. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C3 - تنفيذ صندوق موافقات موحد وآمن

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C3 - Build a unified action inbox with safe decisions

Phase: C - Task-oriented operations; Requirements: R-09

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Blocked - DEC-05 must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 24-44 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C3-D, C1, B6, A3. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users review pending work in one place and understand every decision result.

2. Scope and protected surfaces

Inspect: app/Services/ChecklistWorkflow.php; app/Livewire/ChecklistItems; app/Livewire/Employee/Leave; proposed approval adapters/page. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement the source contract with leave and checklist adapters reusing existing domain writes.

2. Show requester, type, dates/duration, decision context and accessible actions; filter/count only work the current user may act on.

3. Provide explicit review/confirmation for consequential or batch actions and inline rejection reasons. Avoid claiming one-click approval while adding unexplained steps.

4. Handle another approver acting first with a translated no-longer-pending result; show per-item outcomes for allowed batches and preserve legacy screens.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-05.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Atomic compare/recheck of pending state and authorized scope before each write; one domain-owned transaction/idempotency mechanism; safe per-item batch feedback.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C3-01: Inbox counts and rows agree with actionable authorized work.

AC-C3-02: Two simultaneous decisions cannot double-deduct leave or double-transfer an asset.

AC-C3-03: Rejections, confirmations, batches and notifications retain the domain audit/history semantics.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

C3 implementation/decision notes and acceptance evidence. Consumers: C4, G-C, D2, D4-I. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C3, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C3-D, C1, B6, A3. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C5 - تنفيذ تقويم الفريق وتنبيه التداخل

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C5 - Build the team calendar and contextual coverage warnings

Phase: C - Task-oriented operations; Requirements: R-12

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Blocked - DEC-06 must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 20-36 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C5-D, C1, A3. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Managers see coverage and employees understand scheduling conflicts before requesting leave.

2. Scope and protected surfaces

Inspect: app/Livewire/Dashboard/CalendarCard.php; app/Livewire/Dashboard/UpcomingLeavesCard.php; app/Livewire/Employee/Leave; proposed team-calendar query owner. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Build day/week/month views with team/city/status filters and accessible text/shape status encoding.

2. Implement bounded range queries with eager loading and the approved holiday/timezone rules; measure query growth rather than promising literally one query.

3. Connect the same coverage service to the leave preview and expose advisory/blocking meaning explicitly.

4. Keep private reasons absent from UI and payloads; provide a phone list/agenda view instead of a tiny month grid when useful.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-06.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C5-01: Calendar and leave-form warnings agree for the same range/team.

AC-C5-02: Visibility and pending/approved/holiday rules match DEC-06 in EN/AR.

AC-C5-03: Keyboard and phone agenda navigation work without exposing private reasons.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

C5 implementation/decision notes and acceptance evidence. Consumers: C4, G-C. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C5, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C5-D, C1, A3. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### C4 - تنفيذ لوحة تحكم مناسبة لكل دور

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

C4 - Prioritize dashboards for each active role

Phase: C - Task-oriented operations; Requirements: R-10

Owner role: Frontend/Livewire developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C0, C3, C5, B3, B6. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The first screen answers what the user needs to do next.

2. Scope and protected surfaces

Inspect: resources/views/livewire/dashboard/index.blade.php; app/Livewire/Dashboard; resources/views/components/dashboard-cards. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Employee: balance, Request leave, current requests/status, documents and upcoming holidays.

2. Manager: actionable inbox, team absence/coverage and overdue decisions. HR: expiring contracts, incomplete records and overdue work before KPIs.

3. Move news/notes below operational work; reuse C3/C5 counts and projections rather than rebuilding decision/calendar logic.

4. Preserve active-role/city/salary visibility and sensible independent loading. Defer personalization unless its extra scope is chosen.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-C4-01: Each active role sees the C0 priorities and can identify its next action in a task check.

AC-C4-02: Switching role changes cards/counts without leaking cached private data.

AC-C4-03: Cards link to filtered actionable records and do not count inaccessible work.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

C4 implementation/decision notes and acceptance evidence. Consumers: G-C, D2. Mark done only with AC evidence and review.

Executor brief

When assigned to execute C4, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C0, C3, C5, B3, B6. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### G-C - بوابة التحقق من تنظيم التشغيل

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

G-C - Validate navigation, inbox and role dashboards with users

Phase: C - Task-oriented operations; Requirements: R-06, R-07, R-08, R-09, R-10, R-12

Owner role: UX researcher + QA lead; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-16 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C1, C2, C3, C4, C5. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The new organization demonstrably supports employee, manager and HR tasks.

2. Scope and protected surfaces

Inspect: Integrated branch; C0 prototypes; X2 scripts. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Repeat task scripts with representative role/city/locale participants and record sample limitations.

2. Check inbox/calendar/dashboard consistency, navigation findability, bilingual search and request-state comprehension.

3. Run integrated authorization, concurrency and browser regressions; prioritize observed failures before optional expansion.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-G-C-01: Findability and task success are reported against X2 without invented improvements.

AC-G-C-02: No contradictory actionable counts or private calendar/profile exposure is observed in the defined matrix.

AC-G-C-03: The gate states whether changes are accepted, need correction or are held for policy decisions.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

G-C implementation/decision notes and acceptance evidence. Consumers: D4, X1-I, X2-R, G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute G-C, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C1, C2, C3, C4, C5. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

## د · تجربة الموظف والتوسع

### D1-D - تصميم ملف موظف موحد بحسب الصلاحيات

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D1-D - Design a permission-aware unified employee profile

Phase: D - Employee experience and expansion; Requirements: R-11

Owner role: UX designer + HR product owner; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C0, A2, B5-D. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users see a coherent profile without learning unauthorized HR event details.

2. Scope and protected surfaces

Inspect: app/Livewire/Employee/EmployeeProfile.php; app/Policies/EmployeePolicy.php; app/Models/Employee.php; resources/views/livewire/employee. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Map header, sections, section editing and timeline to the chosen directory/full-profile policy.

2. Choose field/action access and which events are omitted for each role. Do not show a restricted placeholder if even the existence of the event is confidential.

3. Specify section-level completeness, save feedback, conflicts and deep-link/return behavior.

4. Keep private document access, salary and disciplinary information explicit; use the authorized avatar_url accessor.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D1-D-01: Profile and timeline have an approved field/event visibility matrix.

AC-D1-D-02: Each editable section has a save/validation/conflict interaction design.

AC-D1-D-03: Authorized avatar/document paths and legacy route compatibility are specified.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/profile-contract.md; permission-aware EN/AR layouts (proposed). Consumers: D1. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D1-D, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C0, A2, B5-D. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### D1 - تنفيذ الملف الموحد والخط الزمني المحمي

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D1 - Implement unified profile sections and authorized timeline

Phase: D - Employee experience and expansion; Requirements: R-11

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 22-40 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: D1-D, B2, B5, A2. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

A user can inspect and edit an allowed section without leaving the profile or changing unrelated data.

2. Scope and protected surfaces

Inspect: app/Livewire/Employee/EmployeeProfile.php; app/Livewire/Forms/Employee; app/Models/Employee.php; tests/Feature/EmployeeProfileRecordsVisibilityTest.php; tests/Feature/EmployeeProfileBadgesVisibilityTest.php. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement the approved header and sections using existing Form objects and employee->avatar_url, not a raw media URL.

2. Authorize each read/edit and return only allowed timeline events/fields; omitted private events must not leak counts or metadata.

3. Save one section independently with dirty guard, accessible error links and stale-edit feedback.

4. Preserve the old edit route and verify section saving does not change unrelated identity, pay, documents or contracts.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Guard against stale section updates; preserve audit trail, private media and unchanged fields. Do not replace stored images or re-encode originals.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D1-01: Profile photographs use the authorized endpoint with correct fallback.

AC-D1-02: Restricted event data/existence is omitted as policy requires, including payloads/counts.

AC-D1-03: Saving one section updates only its permitted data and preserves other sections.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

D1 implementation/decision notes and acceptance evidence. Consumers: D2, X2-R, G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D1, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: D1-D, B2, B5, A2. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### D3 - إضافة مساعدة سياقية وقناة ملاحظات منضبطة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D3 - Provide contextual help and a bounded in-app feedback channel

Phase: D - Employee experience and expansion; Requirements: R-14

Owner role: Frontend + backend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-08 feedback retention and access before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 14-26 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: C1, B0, A4. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Users can understand difficult fields without leaving their task or losing their place.

2. Scope and protected surfaces

Inspect: docs/دليل-الاستخدام.md; resources/js/product-tour.js; app/Support/ProductTour.php; proposed help/feedback surfaces. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Keep tours optional and replayable; write proposed concise help for leave units, contract types, auto-renew and position dependencies for content review.

2. Create a searchable page-context help panel in EN/AR using verified guide content; do not assume an English guide already exists.

3. Build a feedback form with clear disclosure of captured page/locale/role context, minimal data and authorized access/retention decisions.

4. Default to text-only feedback. Attachments are optional and require explicit file-type/storage/access rules; an image validation service is not a general document sanitizer.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-08 feedback retention and access.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Keep feedback out of public storage. Do not capture form contents, government IDs, salary figures or arbitrary browser state automatically.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D3-01: Help answers the selected field/task question in both languages without losing form state.

AC-D3-02: Tours can be skipped and replayed without duplicated overlays.

AC-D3-03: Feedback stores only disclosed approved context; unsupported attachment types are not accepted.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

D3 implementation/decision notes and acceptance evidence. Consumers: X2-R, G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D3, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: C1, B0, A4. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### D2 - تحسين واجهة الخدمة الذاتية للجوال

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D2 - Refine mobile self-service navigation and full-screen flows

Phase: D - Employee experience and expansion; Requirements: R-13

Owner role: Frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 14-28 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: M1, C1, C2, C3, C4, D1. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Mobile self-service feels coherent across home, requests, inbox and profile.

2. Scope and protected surfaces

Inspect: resources/views/livewire/layout; resources/views/components/modal.blade.php; mobile self-service surfaces. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Build a short permission-aware bottom navigation with safe-area spacing and meaningful labels.

2. Refine phone cards, agenda views, form sections and full-screen sheets using M1 evidence; preserve desktop behavior except approved shared fixes.

3. Test orientation, virtual keyboard, text enlargement and focused controls beneath sticky bars.

4. Expose connectivity requirements; do not cache private HR payloads offline as an incidental mobile optimization.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D2-01: Home/Requests/Inbox/Profile work according to role permissions at 375px and landscape.

AC-D2-02: Navigation/submit bars never obscure focused controls or errors.

AC-D2-03: Desktop EN/AR task regressions remain clear.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

D2 implementation/decision notes and acceptance evidence. Consumers: D2-PWA, X2-R, G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D2, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: M1, C1, C2, C3, C4, D1. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### D2-PWA - خيار تثبيت التطبيق دون تخزين بيانات خاصة دون اتصال

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D2-PWA - Optionally add installable self-service without private offline caching

Phase: D - Employee experience and expansion; Requirements: R-13

Owner role: Frontend developer; Reviewer: QA engineer + technical lead; Priority: Normal

Readiness: Blocked - DEC-07 optional installation scope must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 6-12 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: D2. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

If selected, users can install the stable experience while sensitive actions remain connection-dependent.

2. Scope and protected surfaces

Inspect: Proposed manifest and installation assets; existing auth/session configuration. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Confirm installation is useful and in scope; otherwise record this optional task as deferred.

2. Provide manifest/icons and validated install metadata. Any service worker must not cache private API/HTML, avatars, attachments or payroll data.

3. Explain offline/expired-session states and safe reconnection; never queue HR approvals or contract writes offline.

4. Test install/update/logout cleanup and document exactly what remains available without connection.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-07 optional installation scope.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Preserve existing records and unrelated fields. Any discovered schema/write changes need an explicit compatibility and recovery plan before implementation.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D2-PWA-01: Optional scope is explicitly selected or deferred.

AC-D2-PWA-02: No sensitive response appears in offline caches after logout or role switch.

AC-D2-PWA-03: Offline writes fail clearly and cannot replay unexpectedly when connectivity returns.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

D2-PWA implementation/decision notes and acceptance evidence. Consumers: final handoff. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D2-PWA, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: D2. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### D4 - تصميم رحلات التعيين والتجديد والمغادرة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D4 - Design linked hire, renewal and exit journeys

Phase: D - Employee experience and expansion; Requirements: R-21

Owner role: HR product owner + UX designer + backend lead; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts; settle DEC-09 lifecycle journey scope and authority before the affected implementation portion. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-16 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: G-C, B5-D, C3-D. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

A small linked-workflow design names every step, owner and state without inventing a recruitment module.

2. Scope and protected surfaces

Inspect: app/Services/ChecklistWorkflow.php; app/Console/Commands; app/Livewire/Employee; app/Livewire/Contract; archive/document flows. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Map employee, contract, checklist, documents and deactivation steps for hire, renewal and exit.

2. Define responsible roles, due-date basis, status, prerequisites, reused data and escalation/exception handling.

3. Distinguish automated reminders/recommendations from human employment decisions; do not authorize account removal or employment changes merely from a workflow label.

4. Propose the smallest first slice and obtain an explicit scope/state transition decision before D4-I. This task alone does not complete recommendation 21 implementation.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes. Required decisions: DEC-09 lifecycle journey scope and authority.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D4-01: All three journeys have a step/state/owner map and concrete first-slice scope.

AC-D4-02: Each irreversible or consequential action has a defined human authority and recovery consideration.

AC-D4-03: The document states which parts are designed, selected or deferred.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

docs/ux/journeys.md and selected first-slice contract (proposed). Consumers: D4-I, G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D4, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: G-C, B5-D, C3-D. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### D4-I - تنفيذ الجزء المعتمد من الرحلات المترابطة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

D4-I - Implement the selected first lifecycle journey slice

Phase: D - Employee experience and expansion; Requirements: R-21

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Blocked - DEC-09 must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 24-48 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: D4, C3, B5. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

If approved, an existing employee lifecycle journey has one traceable checklist rather than duplicate data entry.

2. Scope and protected surfaces

Inspect: D4 selected contract; app/Services/ChecklistWorkflow.php; existing domain services; proposed journey UI. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Implement only the D4-selected slice with existing domain services and one authoritative task/status record per step.

2. Show owner, due date, status and blocking prerequisite; link to existing employee/contract/checklist records.

3. Add bounded reminders and safe retries without duplicating accounts, contracts, assets or employee state changes.

4. Keep unselected journeys explicitly deferred and document the next increment rather than claiming all lifecycle automation exists.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-09.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Any new task/state tables use additive migrations and tested historical treatment; one domain service owns each authoritative write.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-D4-I-01: The selected slice works end to end with one owner/status per step.

AC-D4-I-02: Existing records are reused and retry does not duplicate domain writes.

AC-D4-I-03: Consequential transitions require the chosen human authority and retain an audit trail.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

D4-I implementation/decision notes and acceptance evidence. Consumers: final handoff. Mark done only with AC evidence and review.

Executor brief

When assigned to execute D4-I, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: D4, C3, B5. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

## هـ · التحقق والتسليم

### X2-I - خيار قياس محاولات المهام داخل التطبيق

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

X2-I - Optionally instrument minimal task outcomes

Phase: E - Validation and handoff; Requirements: R-15

Owner role: Backend developer + QA analyst; Reviewer: QA engineer + technical lead; Priority: Normal

Readiness: Blocked - DEC-10 analytics minimization and retention must be settled. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 10-20 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: X2, A2. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

If approved, measurements count attempts and outcomes without storing HR content.

2. Scope and protected surfaces

Inspect: Proposed task-outcome events/report; existing authorization infrastructure. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Agree purpose, access, retention and disclosed identifiers with the product owner; prefer aggregate/pseudonymous data and no third-party analytics by default.

2. Define attempt ID, task type, start/success/failure/cancel events, durations, role context, duplicate event handling and abandonment timeout.

3. Capture no names, entered form values, reasons, government IDs, pay or document contents. Aggregate counts/medians with explicit denominators and missing-event limitations.

4. Validate the schema with a synthetic start/success/failure/retry series; moderated X2/X2-R research remains valid if instrumentation is declined.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes. Required decisions: DEC-10 analytics minimization and retention.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Do not use a raw user ID plus role as a substitute for an attempt model; do not start production data collection by writing the schema.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-X2-I-01: Optional measurement policy is selected or the task is explicitly deferred.

AC-X2-I-02: Completion/error/abandonment measures reconcile to defined attempts without double counting.

AC-X2-I-03: Only approved minimized data is stored and authorized reports honor retention/access rules.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

X2-I implementation/decision notes and acceptance evidence. Consumers: final handoff. Mark done only with AC evidence and review.

Executor brief

When assigned to execute X2-I, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: X2, A2. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### X1-I - تحسين الاختناقات المقاسة والتعافي من الشبكة

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

X1-I - Optimize measured bottlenecks and safe network recovery

Phase: E - Validation and handoff; Requirements: R-23

Owner role: Backend + frontend developer; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 16-32 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: X1, G-B, G-C. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

Measured slow paths improve without weakening data integrity or hiding necessary feedback.

2. Scope and protected surfaces

Inspect: tests/Feature/Performance; measured owners identified by X1; app/Livewire; resources/js. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Rank bottlenecks using repeatable X1 comparisons on the integrated app; optimize actual N+1, duplicate data, payloads, eager assets or expensive redraws.

2. Use lazy cards/images/charts where they improve useful completion rather than just first paint; preserve authorized photo routes and reserved layout space.

3. Recheck idempotency under timeout/retry and concurrency; display an honest unknown-result state when a timed-out write may have succeeded, then reconcile by operation key.

4. Remeasure the same profiles/fixtures and document before/after tradeoffs with maintained guards.

4. Business rules and decisions

Apply SHARED RULES and the explicit upstream policy/design contracts; no unapproved business-rule changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Cover empty/denied, loading/error and stale role/locale states relevant to this surface; preserve recoverable user work and report changed prerequisites.

7. Data integrity

Use operation-aware reconciliation for ambiguous write outcomes; a generic retry button alone is not idempotency.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-X1-I-01: Measurements use comparable fixture/network/browser conditions and disclose any changes.

AC-X1-I-02: No duplicate records, approvals or balance effects occur after network retries.

AC-X1-I-03: Optimization preserves EN/AR correctness, permissions and accessible loading feedback.

10. Verification

Map each AC to meaningful domain/Livewire tests and scoped EN/AR desktop/phone browser checks. Include relevant negative/concurrency cases; attach evidence and report actual results and remaining limits.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Integrate the smallest coherent change; preserve old routes/flows until replacement is verified. For reversible UI-only work, revert that change if regression occurs; data-affecting recovery must be proved separately.

13. Outputs and next-task handoff

X1-I implementation/decision notes and acceptance evidence. Consumers: X2-R, G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute X1-I, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: X1, G-B, G-C. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### X2-R - إعادة اختبار المستخدمين وتحديد الاحتكاك المتبقي

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

X2-R - Repeat usability testing and prioritize remaining friction

Phase: E - Validation and handoff; Requirements: R-15

Owner role: UX researcher + QA lead; Reviewer: QA engineer + technical lead; Priority: High

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 8-16 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: G-C, D1, D2, D3, X1-I. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The final experience is judged by observed task outcomes rather than feature count.

2. Scope and protected surfaces

Inspect: docs/ux/usability-baseline.md; integrated application; optional X2-I aggregates. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Repeat X2 scenarios with comparable role/city/language conditions and state sample differences.

2. Measure completion, errors, help, time, status comprehension and SUS/post-task ease using the same method.

3. Report regressions and improvements with raw counts/medians and limits; turn remaining findings into a prioritized follow-up register.

4. Check that the proposed sub-minute leave and 15-second search targets remain realistic; missing users or an unmeasured baseline cannot be replaced with invented improvement percentages.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-X2-R-01: Results are compared transparently with the same defined baseline or the missing baseline is disclosed.

AC-X2-R-02: Core tasks and request-state understanding have observable evidence across representative roles/locales.

AC-X2-R-03: Remaining friction is ranked by severity and frequency, with an owner and explicit disposition.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Deliver a versioned decision/design/evidence artifact; no application deployment or data migration belongs to this task.

13. Outputs and next-task handoff

X2-R implementation/decision notes and acceptance evidence. Consumers: G-R. Mark done only with AC evidence and review.

Executor brief

When assigned to execute X2-R, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: G-C, D1, D2, D3, X1-I. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

### G-R - التحقق الشامل وتسليم حزمة الإصدار

```text
Read SHARED RULES in the delivered ux-prompts-v3.md at its actual attached or P0-declared path.

G-R - Run integrated regression and deliver a release-ready handoff

Phase: E - Validation and handoff; Requirements: R-01, R-02, R-03, R-04, R-05, R-06, R-07, R-08, R-09, R-10, R-11, R-12, R-13, R-14, R-15, R-16, R-17, R-18, R-19, R-20, R-21, R-22, R-23, R-24

Owner role: QA lead + technical lead; Reviewer: QA engineer + technical lead; Priority: Urgent

Readiness: Conditional - complete listed prerequisites and inspect their delivered artifacts. Main audit status: Not fully reassessed. This audit does not establish full completion of this prompt. Inspect existing behavior and verify its acceptance criteria before implementing the remaining delta. This prompt remains in scope; it is not marked fully verified or released.

Estimated effort: 12-24 person-hours including implementation/deliverable, tests and review; low confidence pending P0 and policy decisions. No calendar commitment.

Depends on: X2-R, X1-I, D1, D2, D3, G-C, D4. Required inputs: the named outputs and AC evidence from these predecessor tasks, not their chat history.

1. Objective

The delivered version has traceable acceptance evidence, known scope and a safe rollout/recovery plan.

2. Scope and protected surfaces

Inspect: Integrated branch; all task outputs; proposed release/QA handoff. Scope is limited to the objective and steps below; preserve unrelated behavior, roles/cities/pay boundaries and real HR data.

3. Required work

1. Check every required task/AC against the integrated SHA; inspect optional D2-PWA/D4-I/X2-I as selected or explicitly deferred with reasons.

2. Run the full suite, build, affected formatting checks, EN/AR browser matrix, cross-city/role checks, concurrency/retry and actual PDF validation.

3. Validate additive migrations/backfills against representative data with restore evidence; provide release notes, support guidance, known issues and rollback/forward-recovery instructions.

4. Deliver evidence and a release recommendation. Deployment, live migration, publishing and external communications remain separate authorized actions.

4. Business rules and decisions

Preserve current business rules; this task defines or checks the named contract and does not itself approve policy changes.

5. Security and permissions

Apply the active-role/city/record and salary/privacy rules from SHARED RULES. Authorize server actions; do not use menu visibility as permission. Document any task-specific policy contract.

6. Exceptional cases

Missing evidence or access is recorded as a limitation, not success. Keep independent work moving while a named decision is pending.

7. Data integrity

No product data/schema writes are part of this task; use synthetic observations and sanitized evidence.

8. Implementation notes

Paths above are inspected starting points, not proof of completed behavior. All new docs/services/components are proposed. Use the actual P0 runtime and B0 API; do not copy escaped identifiers from v2.

9. Acceptance criteria

AC-G-R-01: No unresolved critical/high access, accounting, destructive-data or core-task regression remains.

AC-G-R-02: All selected scope has acceptance evidence; deferred optional work and untested environments are explicit.

AC-G-R-03: The handoff can be reproduced by a new developer and includes recovery plus an HR/QA acceptance record.

10. Verification

Review the named deliverable against all numbered ACs; use synthetic examples and record source/decision evidence. No application tests are claimed solely from a document review.

11. Mistakes to avoid

Do not replace evidence with a passing markup assertion, silently change policy to pass a test, duplicate a domain write, or introduce data exposure through convenient UI projections.

12. Rollout and recovery

Do not assume code rollback reverses a migration or revives revoked credentials. Prove data recovery and a forward-fix path for irreversible compatibility changes.

13. Outputs and next-task handoff

G-R implementation/decision notes and acceptance evidence. Consumers: final handoff. Mark done only with AC evidence and review.

Executor brief

When assigned to execute G-R, read this complete task and SHARED RULES in the delivered ux-prompts-v3.md, using the actual attached path or P0-declared repository path. Verify prerequisite artifacts: X2-R, X1-I, D1, D2, D3, G-C, D4. Deliver only this outcome. Routine reversible implementation choices are delegated; stop dependent work for the listed material decisions or contradicted assumptions. Do not deploy, migrate live data, publish, contact others or change real HR records without separate authorization.
```

## مطابقة التوصيات الـ24 مع المهام

| Requirement | Outcome | Task coverage |
|---|---|---|
| R-01 | Remove artificial waiting | P0, X1, A7 |
| R-02 | Navigate-safe transitions | P0, X1, B1 |
| R-03 | Consistent feedback and work preservation | P0, B0, A5-M, B2 |
| R-04 | Leave impact and request clarity | A3-R, A3-D, A3 |
| R-05 | Loading/empty/retry states | B0, B3 |
| R-06 | Task/role navigation | C0, C1 |
| R-07 | Global bilingual search | C0, C2 |
| R-08 | Contextual quick actions | C0, C2 |
| R-09 | Unified approvals | C0, C3-D, C3 |
| R-10 | Role dashboards | C0, C4 |
| R-11 | Unified employee profile | D1-D, D1 |
| R-12 | Team calendar/coverage | A3-D, C5-D, C5 |
| R-13 | Mobile-first self-service | M1, D2, D2-PWA |
| R-14 | Contextual help | D3 |
| R-15 | Measured usability | X2, X2-I, X2-R |
| R-16 | Compact tables and preserved views | B4-D, B4 |
| R-17 | Resumable employee onboarding | B5-D, B5, M1 |
| R-18 | Complete EN/AR localization | A4, A5-M |
| R-19 | Accessibility | A5, B0, A5-M, M1 |
| R-20 | Useful non-duplicate notifications | B6 |
| R-21 | Connected lifecycle journeys | D4, D4-I |
| R-22 | Reliable contract/PDF experience | A6-D, A6 |
| R-23 | Measured performance/recovery | X1, X1-I |
| R-24 | Access control and digital identity | P0, A1, A2-D, A2 |

## تعليمات ClickUp بالإنجليزية

## Simple ClickUp import

Use HC-HR-ClickUp-Simple-EN.csv in your existing project/List. It has one Task Name column only, with titles formatted Phase 1: [Prompt ID] Task title. The bracketed ID matches the exact task/prompt heading in this plan; preserve it when renaming a task so its ClickUp status and evidence can be traced back to the prompt. For example: Phase 2: [A5-M] Protect modal input and close without refreshing the page. Map this column to Task Name and assign developers in ClickUp. Phase 1 maps to baseline phase 0; Phases 2-6 map to planning phases A-E. Phase labels and prompt IDs are part of each title; separate phase Lists and custom fields are not required.

There are 47 task titles, including Phase 2: [A5-M] Protect modal input and close without refreshing the page. If earlier titles are already in ClickUp, rename the existing tasks to add their matching prompt IDs rather than importing duplicate tasks; add A5-M only if it is missing. Full task prompts and dependencies remain in these planning documents; they are not extra CSV columns. A prompt ID identifies planned work, not proof of completion; implementation and QA evidence remain necessary. No live ClickUp import has been performed.

Execution sequencing remains in the task cards. Conditional dependency rule: if DEC-07 selects installability, add D2-PWA before G-R; if DEC-09 selects lifecycle implementation, add D4-I before G-R; if DEC-10 selects telemetry for evaluation, add X2-I before X2-R and G-R. Verify selected-scope requirements at release regardless of whether native ClickUp dependencies are used.

## Sources and evidence limits

- Input: ux-prompts-v2.md supplied from C:/Users/asus/Downloads/Telegram Desktop; reviewed as reference content, not execution authorization.
- Original scope: HC-HR-UX-Improvement-Plan-AR.pdf, 15 October 2026: all 24 requirements remain traceable below.
- Current repository review: E:/hr/hc-hr main at 0d7cd8d70ae0bef17d90ae6902b9ebc711cadbd9, matched to remote main. Fresh isolated PHP 8.5.11/English test run: 529 tests, 516 passed, 8 failures, 5 errors. Evidence files main-audit-junit.xml and main-audit-tests.txt are in the conversation output root, one directory above revision-v3. Source and tests were reviewed; authenticated browser sign-off is still pending. No product source was modified. Earlier v3 drafting used preserve-contracts-search at 7faa289a8f9248f22547d8b59d684004403401ed; that is historical context only.
- [ClickUp supported import fields](https://help.clickup.com/hc/en-us/articles/6310876671255-Fields-supported-by-the-Spreadsheets-importer) and [spreadsheet preparation](https://help.clickup.com/hc/en-us/articles/6310821748759-Prepare-a-spreadsheet-for-import), checked 16 October 2026. Import is a planned mapping, not a live ClickUp test; actual workspace features/permissions vary.
- [W3C target-size guidance](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum) and [focus not obscured](https://www.w3.org/WAI/WCAG22/Understanding/focus-not-obscured-minimum). 44px remains a product usability target, not the exact AA minimum.


</div>
