# C5-D · Team calendar visibility and coverage warning contract (proposed)

Version: **team-coverage/1.0** · Date: 2026-10-20, HR answers 2026-10-21 · Task: C5-D in [ux-prompts-v3.md](ux-prompts-v3.md) (R-12)
Owner role: HR product owner + backend lead · Reviewer: HR product owner + technical lead · Consumer: **C5** (also read by C3, C4)
Status: **HR decisions recorded (DEC-06 + §5 answers, 2026-10-21).** Technical-lead review of §4 and §6 pending.

This is a decision/design document. **No application code, route, schema or data was changed.** Every service, scope, field and code named below is **PROPOSED** until C5 implements it.

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision inspected | branch `c5-d` @ `d8effd8c` (A5 on top of A2). |
| A2-D | [access-policy.md](access-policy.md): **done**, signed 2026-10-20. Handoff to C5-D: names and photos follow the Directory tier; absence type visibility is C5-D's own decision. |
| A3-D | [leave-calculation-contract.md](leave-calculation-contract.md): **done**, signed 2026-10-20. Handoff: use `LeaveRequestPricing::quote()` `excluded_dates` and `pending_hours`, never re-implement date arithmetic. |
| Decisions in force | [decisions.md](decisions.md): DEC-06 (team calendar), DEC-03 + A3-D follow-up (weekends, holidays D-4, pending reserves balance), DEC-05 revision (direct manager decides; technical manager abolished; upcoming card shows accepted leaves), DEC-01/A2D-Q1…Q4 (Directory tier). |
| Data | None read. Synthetic examples only (§7). |

## 2. Current behaviour (traced in source at `d8effd8c`)

| # | Surface | Today | Gap against the decisions |
|---|---|---|---|
| 1 | `Dashboard\CalendarCard` | Month grid built from `config('samples.calendar.*')`: **sample** holidays, saints and events. No leave data, no `holidays` table. | Not a team calendar yet; holidays are not D-4. |
| 2 | `Dashboard\UpcomingLeavesCard` → `LeaveRequest::scopeUpcomingVisibleTo` (`employee`) | Accepted leaves of anyone sharing a **department OR center** with any of the user's positions, in **any city**. | Department and center **match** the 2026-10-21 answer (§3). Missing: same direct manager (with the manager) and project-manager peers. Uses all `profile.positions`, not only active contracts. |
| 3 | same, `manager` | Pending + accepted of positions whose `direct_manager_id` **or `tech_manager_id`** is one of `profile.positions` (all positions, not only active contracts). | Technical manager abolished (DEC-05 rev.). Uses a different "my reports" rule from `scopeManageableBy` (active contracts). Pending shown on the card. |
| 4 | same, `HR-SYR`, `super-admin`, `general-manager`, `HR-<city>` | **All statuses, including rejected** (asserted by `UpcomingLeavesCardTest::test_ongoing_leaves_preserve_city_scoping_statuses_and_start_date_order`). | DEC-05 rev.: the upcoming card shows **accepted** leaves. `general-manager` is org-wide here but only own reports in `scopeManageableBy`. |
| 5 | Card body | Name, date(s), hour range, **leave type name** and **duration** for every viewer; **status** only for HR/manager roles (`@activerole`, a UI switch, but the employee query already filters to accepted). **`reason` is not rendered.** | Type matches DEC-06 literally (see §5 Q1). Duration comes from `getDurationAttribute`: weekends only, no holidays, and it mutates global `Carbon::setWeekendDays` → it will disagree with the A3-D preview. |
| 6 | Card payload | `public $upcomingLeaves` holds an Eloquent collection; Livewire's model synthesizer dehydrates it to class + keys, not attributes (not executed here, C5 verifies by payload inspection). | Leave-request ids reach the browser for colleague-level viewers. |
| 7 | Eager loading | `leaveBalance.leaveType`, `…contract.position`, `…contract.employee`; the duration accessor also reads `position.center` (not eager-loaded). | N+1 on centers. |
| 8 | `LeaveRequestPolicy::view` | Owner, or `scopeManageableBy` (HR city scope, HR-SYR/super-admin all, manager/general-manager via direct **and tech** manager on active contracts). | No colleague-level ability exists, correctly: `view` opens the full request (reason, reject reason) and must not be widened for a calendar. |
| 9 | `holidays` table | `morphs('holidayable')` (NOT NULL), `is_recurring`, `start_date`/`end_date`. No leave or calendar code reads it. | D-4 needs **national** holidays, which have no owner row in a NOT NULL morph (§6 T-1). |
| 10 | Time zone | `config/app.php` `timezone = Europe/Istanbul` (UTC+3 year-round, same offset as Asia/Damascus today). Day leaves are stored at `00:00` of the first and last date; hour leaves as one date with times. | Ranges must be built in the app time zone (§4.2). |

## 3. Settled decisions (AC-C5-D-01, source: DEC-06, 2026-10-16)

> **"My team"**: both views, same project/program **and** same direct manager (user can switch). **What colleagues see**: the leave **with its type**. **Coverage**: advisory warning to the manager only; never blocks a request.

Also binding here: DEC-05 rev. (upcoming card = accepted; only the direct manager decides; HR acts on behalf only while the manager is on accepted leave), DEC-03 (pending reserves balance), D-4 (holidays: national + center's city + program; recurring repeat yearly), A2D-Q3 (former staff hidden from staff and managers), A2D-Q4 (regional HR sees other cities at Directory tier only).

### 3.1 HR answers, 2026-10-21 (refine DEC-06)

| Point | Decision |
|---|---|
| **Colleague set** ("my team", replaces DEC-06's "same project/program" view) | A person sees the absences of colleagues who share **any** of: (1) the same **department**, in **any governorate** (e.g. the technical department in Homs and Aleppo); (2) the same **center**; (3) the same **direct manager**, including **the manager themself**; (4) for a **project manager** (job level *Project Manager*, grade **3**): the other project managers of the **same program**, in any governorate. |
| **Service departments → project managers** (HR, 2026-10-21) | Absences of volunteers in a **service department** (CT, IT, HR, PR, Logistic, Financial, MEAL, Legal Affair consultant) are also visible to the **project managers (grade 3) of every program in the same governorate**. Example: a Logistic volunteer in Aleppo on leave appears for the Study Zone Aleppo project manager. Colleague level (C): accepted only, sensitive types as "On leave". Visibility only: it adds no `team_overlap` warning, because the project manager does not decide that request. |
| Type shown to colleagues (Q1) | Sick, maternity, paternity and bereavement show as **"On leave"**; other types by name. Manager and HR always see the real type. |
| Pending (Q2) | Colleagues: **accepted only**. Manager and HR coverage: accepted + pending, marked. Dashboard card: accepted only for every role. |
| Manager warning (Q3) | On **any overlap** with another team member on a working day. |
| Requester hint (Q4) | **Yes, count only**, no names, accepted only, advisory. |
| Regional HR (Q6 a) | As HR: absences of **own city only**. Other cities: only what any colleague sees (§3.1 colleague set). |
| super-admin (Q6 b) | Same as **national HR** (`HR-SYR`). |

**Coverage never blocks.** The only blocking rules on a request remain A3-D's (annual over balance, December without exception flag, no countable days, …). Coverage adds no error code.

## 4. Proposed contract

### 4.1 Visibility matrix (whose absences a viewer may see, and what)

Projection levels: **C** = colleague level (§4.4 fields only, accepted only). **M** = manager level (C + status pending/accepted, substitute, hour range). **H** = HR level (M + rejected/history on HR screens, never on the calendar card).

The **colleague set** of a person is defined in §3.1: same department (any governorate) ∪ same center ∪ same direct manager (+ the manager) ∪ project-manager peers of the same program ∪, for a project-manager viewer, service-department volunteers of the same governorate. It is computed from the viewer's positions on **active contracts**.

| Viewer (active role) | Own colleague set (§3.1) | Own direct reports | Own city (all staff) | Other cities |
|---|---|---|---|---|
| `employee` | C | n/a | none beyond colleague set | none beyond colleague set |
| `manager` | C | **M** | none beyond colleague set | none beyond colleague set |
| `general-manager` | C | M | M (org-wide) | M (org-wide) |
| `HR-<city>` | C | M | **H** | colleague set only, at C (§3.1 Q6 a) |
| `HR-SYR` | C | M | H | H |
| `super-admin` | as `HR-SYR` (§3.1 Q6 b) | | | |

Where two cells apply to one person (e.g. a report who is also in the same center), the **higher** level wins.

Rules for every cell:
- Only **active** employees with an active contract are listed (A2D-Q3); a former employee's absences are not shown to staff or managers.
- Names and photos come from the A2 **Directory projection** and `employee->avatar_url` (authorized route), never `getFirstMediaUrl`.
- Rights are evaluated **on the server, per request, from the active role** (`User::myRole()`); a role switch recomputes. Menu visibility and `@activerole` are presentation only.
- "Own direct reports" = positions whose `direct_manager_id` is a position the viewer holds on an **active contract** (the `scopeManageableBy` rule, **without** `tech_manager_id`). One rule for card, calendar, preview and approvals.

### 4.2 Range and overlap semantics (AC-C5-D-03)

One definition, used by the calendar, the dashboard card, the leave preview and the approval inbox:

| Term | Definition |
|---|---|
| Range | `[from, to]`: **local dates, both inclusive**, in the app time zone. `to = null` means open-ended (the upcoming card today, kept as "no horizon limit" per the existing test). |
| A leave **overlaps** a range | `start_at < (to + 1 day) 00:00` **and** `end_at >= from 00:00`. Sargable (no `whereDate`), and equivalent to today's `scopeUpcoming` for `from = today, to = null`: a day leave ending today (stored `00:00`) and an hour leave that ended earlier today both overlap today. |
| Dates a leave **occupies** | Day leave: every date `start_date … end_date`. Hour leave: its single date, flagged **partial** with its times. |
| A date **counts** for coverage | Only if it is a **working day for that member**: not in `LeaveRequestPricing::quote()->excluded_dates` (center or program-governorate weekends, D-4 holidays). Maternity (calendar days) still occupies weekends, but no one is expected on a weekend, so coverage ignores it there. |
| Statuses | Calendar/card for C level: **accepted**. Coverage for M/H: **accepted + pending**, counted separately (§5 Q2). **Rejected never counts** and never appears on calendar or card. |
| Bounds | Month view: the 6-week grid (≤ 42 days). Agenda/phone list: ≤ 31 days per page. Preview: the requested range. Requests above **62 days** are refused by the service (bounded queries, C5 step 2). |
| Expected staff | Team members with an active contract covering the date (contract start/end inclusive) for whom the date is a working day. |

### 4.3 Proposed service (`App\Services\TeamCoverage`, PROPOSED)

```
absences(User $viewer, TeamScope $scope, DateRange $range): Collection<AbsenceEntry>
coverage(User $viewer, TeamScope $scope, DateRange $range, ?LeaveRequest $candidate = null): Collection<CoverageDay>
```

- `TeamScope`: `colleagues` (the whole §3.1 set, default) or one part of it as a filter: `department`, `center`, `directManager` (positions under one manager position, plus the manager), `programManagers` (job level grade 3 in the same program; only for a viewer who holds such a position), `serviceDepartments` (service-department volunteers in the viewer's governorate; only for a grade-3 viewer); plus `reports` (viewer's own direct reports), `city`, `org`. The service **intersects** the scope with the §4.1 matrix; a scope the viewer may not use returns the same empty result as an empty team (no "forbidden" signal).
- `$candidate`: an unsaved or edited request, so the leave preview sees the same days as the calendar (excluded from totals when editing, as in `quote($excluding)`).
- Reads only. One domain service per write is unchanged: approval, edit and cancel stay in their existing owners; `TeamCoverage` never writes and never mutates global Carbon state.
- Eager-load `leaveBalance.leaveType`, `leaveBalance.contract.employee`, `leaveBalance.contract.position.center` in one query per range; working-day exclusions come from `LeaveRequestPricing`. C5 measures query growth across 1/10/50 members rather than promising one query.

### 4.4 Projection (AC-C5-D-02)

**`AbsenceEntry`** (an array/DTO built at render; **no Eloquent models in public Livewire properties**, so no leave-request ids or attributes reach a C-level browser):

| Field | C | M | H |
|---|---|---|---|
| Directory name, photo URL (authorized route), position title | ✓ | ✓ | ✓ |
| Dates; `partial` flag; hour range for hour leaves | dates + partial | ✓ | ✓ |
| Type label (per §5 Q1 mapping; birthday holiday = "Birthday", §6.1) | ✓ | ✓ (real type) | ✓ (real type) |
| Status (pending / accepted) | — (accepted only) | ✓ | ✓ |
| Substitute (`alternative_employee_id`, Directory name) | — | ✓ | ✓ |
| `is_self` | ✓ | ✓ | ✓ |
| Opaque row key (hash of request id + viewer session, for `wire:key`) | ✓ | — | — |
| Request id + link to the request (policy `view` re-checked on open) | — | ✓ | ✓ |

**Never in any projection, payload, event, notification or export:** `reason`, `reject_reason`, the HR note, `approved_by`, balances (`deserved`, `used`, `available_hours`, `projected_hours`), contract or pay fields, rejected requests, and requests of people outside the viewer's §4.1 set. Salary is not involved and `canViewSalary()` must not become involved.

**`CoverageDay`**: `date`, `expected`, `absent_full`, `absent_partial`, `pending` (M/H only), `is_holiday` + holiday name (D-4), `members[]` (AbsenceEntry, only the viewer's visible set).
**Aggregate rule:** every count is computed **over the viewer's visible set only**. A count is never derived from people the viewer cannot see, so totals cannot reveal hidden absences.

**Existence of unauthorized events: hidden.** Out-of-scope absences are not shown as "busy" blocks, not counted, and not distinguishable from "no absence" (same response, same empty state), consistent with A2-D §4.4.

### 4.5 Coverage warning

| Code (PROPOSED) | Severity | Shown to | Meaning |
|---|---|---|---|
| `team_overlap` | **advisory** | the deciding manager (approval view, calendar) | On ≥ 1 counted date, other members of the requester's direct-manager team are absent (accepted or pending) (Q3: any overlap). Payload: dates + counts + names (M level). |
| `team_overlap_hint` | advisory | requester in the leave preview (Q4: yes) | "N colleagues are already off on some of these days", over the requester's §3.1 colleague set. Count only, accepted only, no names. |
| `manager_absent` | info | requester and HR | The requester's direct manager has accepted leave in the range; HR may decide on their behalf (DEC-05 rev.). |

Warnings are translation keys + parameters (EN/AR, A4), use text + icon (not colour alone), and are never `errors[]`. No threshold is configurable in this version (§5 Q3).

## 5. Detail questions (HR product owner) — answered 2026-10-21

All answered; the decisions are in **§3.1**. Q1–Q4, Q5 b and Q6 took the recommended option. **Q5 a was replaced** by HR's own definition of the colleague set (department in any governorate, center, direct manager, project-manager peers in the same program). The tables below are kept as the record of what was asked.

| # | Question | Options | Recommendation | If unanswered |
|---|---|---|---|---|
| **Q1** | Colleagues see the **leave type** (DEC-06). Sick, maternity, paternity and bereavement reveal health or family events. Show those to **colleagues** as a neutral "On leave"? | **A.** Real type for everyone (DEC-06 literal) · **B.** Colleagues see "On leave" for sick, maternity, paternity, bereavement; real type for annual, marriage, unpaid, hourly. Manager and HR always see the real type. | **B** | A |
| **Q2** | Do **pending** requests show? | **A.** Colleagues: accepted only. Manager/HR coverage: accepted + pending, marked "pending". Dashboard card: accepted only for every role (DEC-05 rev.). · **B.** Pending visible to colleagues too. | **A** | A |
| **Q3** | When does the manager's coverage warning appear? | **A.** Any overlap with another team member on a working day · **B.** Only when absent ≥ a share of the team (e.g. half) · **C.** Per-team setting | **A** (simple; the manager judges) | A |
| **Q4** | DEC-06 sends the warning "to the manager only". May the **requester** see an anonymous hint ("2 colleagues are off on some of these days") before submitting? | **A.** Yes, count only, no names · **B.** No | **A** (C5's objective: employees understand conflicts before requesting) | B |
| **Q5** | **Team boundaries.** (a) Program view: same program **in the same governorate**, or nationwide? (b) Does the direct-manager view include **the manager themself**, so the team sees when the manager is away? | (a) **A.** same governorate · **B.** nationwide. (b) **A.** yes · **B.** no | (a) **A** (matches D-1), (b) **A** | (a) A, (b) A |
| **Q6** | **HR and super-admin.** (a) Regional HR: absences of **own city** only (Directory tier for other cities does not include absences)? (b) super-admin: same as national HR, or none? | (a) **A.** own city only · **B.** all cities. (b) **A.** as HR-SYR · **B.** none | (a) **A**, (b) **A** (leave is not pay data; A2-D treats super-admin as HR-SYR without F) | (a) A, (b) A |

### العربية — أسئلة للموارد البشرية

| # | السؤال | الخيارات | التوصية | إن لم يُجب |
|---|---|---|---|---|
| **س1** | يرى الزملاء **نوع الإجازة** (DEC-06). المرضية والأمومة والأبوة والوفاة تكشف أمورًا صحية أو عائلية. هل تظهر للزملاء كـ«في إجازة» فقط؟ | **أ.** النوع الحقيقي للجميع · **ب.** «في إجازة» للزملاء في هذه الأنواع؛ المدير والموارد البشرية يرون النوع دائمًا | **ب** | أ |
| **س2** | هل تظهر الطلبات **قيد المراجعة**؟ | **أ.** للزملاء: المقبولة فقط. للمدير والموارد البشرية: المقبولة + قيد المراجعة مع تمييزها. بطاقة الإجازات القادمة: المقبولة فقط للجميع · **ب.** تظهر للزملاء أيضًا | **أ** | أ |
| **س3** | متى يظهر تنبيه التغطية للمدير؟ | **أ.** عند أي تداخل مع زميل في يوم عمل · **ب.** عند غياب نسبة من الفريق · **ج.** إعداد لكل فريق | **أ** | أ |
| **س4** | هل يرى **مقدّم الطلب** تلميحًا بلا أسماء («زميلان في إجازة في بعض هذه الأيام») قبل الإرسال؟ | **أ.** نعم، عدد فقط · **ب.** لا | **أ** | ب |
| **س5** | حدود الفريق: (أ) فريق البرنامج في **نفس المحافظة** أم على مستوى البلد؟ (ب) هل يظهر **المدير نفسه** في فريقه؟ | (أ) نفس المحافظة / كل البلد · (ب) نعم / لا | نفس المحافظة؛ نعم | نفس المحافظة؛ نعم |
| **س6** | (أ) موارد المدينة ترى إجازات **مدينتها فقط**؟ (ب) مدير النظام مثل الموارد البشرية المركزية أم لا شيء؟ | (أ) مدينتها / كل المدن · (ب) مثل المركزية / لا شيء | مدينتها؛ مثل المركزية | مدينتها؛ مثل المركزية |

## 6. Technical items (technical lead, not HR)

| # | Item | Proposal |
|---|---|---|
| T-1 | Holidays have no national or per-project owner in `holidays.holidayable` (NOT NULL morph). | See §6.1. |
| T-2 | Card duration uses `getDurationAttribute` (no holidays, global Carbon state). | Replace with `LeaveRequestPricing::quote()->requested_days_display` once A3 ships it. |
| T-3 | `UpcomingLeavesCardTest` asserts a **rejected** leave on the HR card. | Expectation changes **with** DEC-05 rev. / Q2, documented in C5; not silently. |
| T-4 | `tech_manager_id` still used by `scopeUpcomingVisibleTo` and `scopeManageableBy`. | Remove from the team/report rule in C5 (DEC-05 rev.); `scopeManageableBy` change is shared with C3. |
| T-5 | "Project manager" has no flag; HR identifies it as the job level *Project Manager*, **grade 3** (confirmed by HR 2026-10-21). Local `job_levels` (read 2026-10-21): grade 3 is **only** *Project Manager* / مدير المشروع (grades 1–8 each have one level). | Match on `job_levels.grade = 3` through one named constant/config value, never a scattered literal. |
| T-6 | "Service department" has no flag. `departments` has a unique `code`. | A config list of department **codes** (proposed `config('team-coverage.service_department_codes')`), no schema change. **Codes, read from the local `departments` table 2026-10-21 and mapped by HR:** `Comm` (CT = Communication), `IT`, `HR`, `Fund` (PR = Fundraising), `Fin`, `MEAL`, `Logi`, `LegA` (Legal Affair consultant). Not service departments: `MGMT`, `FDesk`, `Tchr`, `Comp`, `Kprs`, `Asst`, `PSS`. Codes are matched, not ids, so the list survives a reseed; C5 re-checks them against production before release. If HR wants to change the list from a screen later, a boolean `is_service` column replaces the config. Governorate = the center's city, else the position's city (same rule as `Position::scopeInCity`). |

### 6.1 Plan for T-1 (holidays), PROPOSED — revised with HR's answer 2026-10-21

**HR's answer (2026-10-21):** official holidays are listed in the HR policy. They are entered either by **national HR (HR-SYR)** or by the **program manager**, for the **projects they are responsible for, per governorate**. A program manager may also **grant a one-off holiday** to a project. Projects may share a holiday or not; each project may have different official holidays.

This **refines D-4** ("national + the center's city + the program"): the unit that holidays apply to is the **project**, not the city or the whole program.

**Findings (source and local data, read-only, 2026-10-21):**
- There is no `Project` model. In the data a **project = a program in a governorate**, headed by a grade-3 position (e.g. Study Zone × ALP, DMS, HMS, LAT, TAR; 21 such pairs locally).
- A program manager is responsible for the projects whose project-manager position has them as **direct manager**, not for its `program_id`: the *Hope Center* program managers head DISC, LEAP and Training. Responsibility must follow `direct_manager_id`.
- **CYG** (DMS, HMS) reports to a grade-5 *Projects and Fundraising Manager*, not a program manager (H-1: HR-SYR sets their holidays).
- `holidays`: one-language `name`, NOT NULL `holidayable` morph, `is_recurring`. **0 rows locally.** Nothing reads or writes it.

**Design:**

| Part | Proposal |
|---|---|
| Holiday | `name_en`, `name_ar`, `start_date`, `end_date`, `is_recurring`, `scope` = `national` \| `projects`, `kind` = `official` \| `grant` (a one-off grant is always non-recurring), `created_by`. |
| Which projects | Pivot `holiday_project` (`holiday_id`, `program_id`, `city_id`), unique per triple. One holiday can cover several projects (shared) or one (own). |
| Applies to a volunteer | `national` ∪ holidays linked to the volunteer's project = (position `program_id`, governorate = the center's city, else the position's city, the `Position::scopeInCity` rule). |
| Who may write | **HR-SYR**: any holiday, national or projects. **Program manager**: `projects` scope only, and only for projects whose grade-3 position has one of the manager's active positions as `direct_manager_id`; never `national`. Re-checked on the server on save and delete (proposed `HolidayPolicy`); the project picker and the validation use the same query. super-admin as HR-SYR (§3.1). |
| Effect on requests | With D-5 (snapshot at creation), a holiday added later **does not change** a request already created; HR adjusts by hand if needed. Pending requests without a snapshot reprice on read (A3-D §2). |

**Steps (owner task: A3, since the leave quote is the first consumer and C5 depends on A3):**
1. Read-only `count(*)` of `holidays` on production. Locally it is 0.
2. Migration: add the new columns and the pivot, and drop the unused morph. It **refuses to run if `holidays` has rows**, so no data can be lost; `down()` restores the old shape only while empty. The `holidays` table has no children, so the SQLite rebuild carries no cascade risk.
3. Model: `HasTranslation` name, `projects()` relation, scopes `national()`, `applicableTo(Position)`, `occurringBetween(from, to)` (recurring rows by month and day, across 31 Dec → 1 Jan).
4. One reader, `HolidayCalendar::datesFor(Position, DateRange)`, for `LeaveRequestPricing` (A3), `TeamCoverage` (C5) and `CalendarCard`; then remove the sample config.
5. Screen (EN/AR): list by year; add holiday with scope, kind and project picker (HR-SYR: "all projects in a governorate" shortcut); edit and delete with confirmation.
6. Tests: national applies to everyone; a project holiday only to that program × governorate; shared holiday on two projects; one-off grant does not repeat next year; program manager refused for another manager's project and for `national`; recurring across the year boundary; holiday on a weekend counted once.

**Moving holidays:** `is_recurring` repeats the **same calendar date**: right for 1 Jan or 25 Dec, wrong for Eid al-Fitr, Eid al-Adha and Easter. Those are entered **each year** as non-recurring rows.

**HR answers (2026-10-21):**
- **H-1** CYG holidays (no program manager above its project managers): entered by **HR (HR-SYR) only**.
- **H-2** **No** governorate-wide holiday level: holidays are per **project**. (The "all projects in a governorate" picker in step 5 is only a selection shortcut; it creates per-project links.)
- **H-3** Saints' days are **commemorations only, not holidays**. The calendar may show them as information; they never enter `holidays` and never change a leave quote.

#### Birthday holiday (HR, 2026-10-21)

| Point | Decision |
|---|---|
| What | A holiday **for everyone**, each volunteer on **their own birthday**. It is **not necessarily recurring**: HR creates it for a period (e.g. this year only) and may not renew it. |
| Date | `actual_dob` (real birthday), else `dob` (ID). Only day and month are used. No date → no birthday holiday. |
| Conflict | If the birthday falls on a weekend or another holiday, the volunteer **picks another day in the same week** (before or after). |
| Calendar | Shown to colleagues as **"Birthday"** (day and month visible; the **year is never shown**). HR accepts this as an exception to A2-D, where the birthday is tier P. Only people who already see this volunteer's absences (§3.1) see it. |

Design (PROPOSED):
- A `holidays` row with `kind = birthday`, `scope` national or projects, created by **HR-SYR only** (H-6), and `start_date`/`end_date` = the **validity window** (e.g. 1 Jan – 31 Dec 2026). `is_recurring` = false unless HR renews it automatically.
- For each volunteer with an active contract inside the window, **except service agreements** (H-5), the occurrence = birthday's day/month in that year (29 Feb → 28 Feb in non-leap years, technical default). It is **excluded from the leave quote** like a holiday (no balance deducted) and **counts as absent** for coverage.
- Moved day: a per-volunteer record (proposed `birthday_holiday_choices`: holiday, employee, year, chosen date), validated server-side to be a working day in the **same ISO week** as the birthday, one per year. The **direct manager approves** the chosen day (H-4).
- The birthday is read only by the server to compute the date; the projection carries the label "Birthday" and the date, never `dob`/`actual_dob`.
- Tests: birthday on a working day; on a weekend → choice required, other week refused; no DOB → none; contract ended before the birthday → none; window not renewed → none next year; colleague outside §3.1 does not see it.

**Birthday answers (HR, 2026-10-21):**
- **H-4** A moved birthday day needs the **direct manager's approval**.
- **H-5** **Service-agreement** volunteers do **not** get the birthday holiday.
- **H-6** Only **HR-SYR** creates the birthday holiday (super-admin as HR-SYR); program managers cannot.

## 7. Synthetic examples (evidence for AC-C5-D-02 and -03)

Personas: Lina (employee) and Omar (employee) report to manager Sami, program "Study Zone", education department, center A, Homs; Rana (employee) is in Study Zone, **finance** department, center B, Aleppo; Tarek is in the **technical** department, Aleppo, and Lina's colleague Nour is in the technical department, Homs; Fadi (Homs) and Maya (Aleppo) are project managers (grade 3) of Study Zone; HR-HMS officer Huda. Center weekend Fri+Sat. Holiday: Wed 4 Nov (project Study Zone × Homs). All rows apply §3.1 and §4.2.

| # | Setup | Viewer, scope, range | Expected result |
|---|---|---|---|
| E1 | Lina: accepted sick leave Mon 2 – Thu 5 Nov, reason "surgery" | Omar, directManager, Nov | Lina, 2–5 Nov, **"On leave"** (Q1 B), no reason, no status, no request id |
| E2 | same | Sami, reports, Nov | Lina, 2–5 Nov, "Sick", accepted; counted days 2, 3, 5 (4 Nov holiday excluded) |
| E3 | Omar: pending annual Tue 3 Nov | Lina, directManager, Nov | **Not shown** (pending, C level) |
| E4 | E1 + E3 | Sami approves Omar's request | `team_overlap` advisory on 3 Nov: 1 accepted + 0 other pending; approve button **not** disabled |
| E5 | E1 | Omar previews annual 3–4 Nov | `team_overlap_hint`: "1 colleague off on some of these days" (count only); 4 Nov listed as holiday, not counted |
| E6 | Rana: accepted annual 3 Nov (Aleppo) | Lina, colleagues, Nov | **Not shown, not counted** (different department, center and manager; same program alone is not enough); response identical to "no absence" |
| E7 | E6 | Huda (HR-HMS), city, Nov | Rana absent from result and counts (other city, Q6 a) |
| E8 | Hour leave Lina 3 Nov 08:00–10:00, ended | Omar, card, today = 3 Nov 12:00 | Shown (overlaps today: `end_at >= 3 Nov 00:00`), `partial`, no times at C level |
| E9 | Day leave ending today (stored `00:00`) | any, card | Shown; ending yesterday → not shown (same as existing test cases) |
| E10 | Lina's leave rejected | any viewer, calendar/card | Not shown, not counted (HR sees it only on HR request screens) |
| E11 | Sami accepted leave 2–3 Nov | Lina previews 2 Nov | `manager_absent` info; appears in Lina's direct-manager view (Q5 b) |
| E12 | Tarek (technical, Aleppo): accepted annual 3 Nov | Nour (technical, Homs), colleagues, Nov | **Shown** at C level: same department, any governorate |
| E13 | Maya (PM, Aleppo): accepted annual 3 Nov | Fadi (PM, Homs, same program), colleagues | **Shown** at C level (project-manager peers). Lina (not grade 3) does **not** see Maya |
| E15 | Birthday holiday 2026 active; Omar's birthday Fri 6 Nov (weekend), he picks Thu 5 Nov | Lina, colleagues, Nov | Omar, 5 Nov, **"Birthday"**, no year, no `dob`; no balance deducted; counts as absent on 5 Nov |
| E14 | Logistic volunteer, Aleppo: accepted sick leave 3 Nov | Maya (Study Zone PM, Aleppo), colleagues | **Shown** as "On leave" (service department, same governorate). Fadi (PM, **Homs**) does **not** see it; Lina (not grade 3) does **not** see it; no `team_overlap` for Maya |

## 8. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-C5-D-01** Visibility and warning/blocking rules are explicit HR decisions. | **Met** (HR answers 2026-10-21). DEC-06 core §3; colleague set, type mapping, pending, warning trigger, requester hint, HR and super-admin scope §3.1. | §3, §3.1, §4.1, §4.5 |
| **AC-C5-D-02** The projection excludes restricted reasons and unauthorized event metadata. | **Met (design).** | §4.4 field table, "never" list, aggregate-over-visible-set rule, hidden existence; E1, E3, E6, E7, E10 |
| **AC-C5-D-03** Calendar, dashboard and request-preview consumers share the same range/overlap semantics. | **Met (design).** | §4.2 single definition, §4.3 single service with `$candidate`, §2 #10 / E8–E9 equivalence with today's `scopeUpcoming` |

Verification performed: source trace of the files in §2 at `d8effd8c`; prerequisite artifacts A2-D and A3-D read (both signed 2026-10-20). **No application test was run or added, and none is claimed from this document.** Limitations: the `holidays` table contents and production leave-type data were not inspected; the Livewire payload claim (§2 #6) was not executed; C5 must verify both.

## 9. Handoff

| Consumer | Takes from this document |
|---|---|
| **C5** | §3.1 decisions, §4 contract, §6 T-1…T-5, §7 as test fixtures (domain + Livewire payload tests, EN/AR). T-1 and T-5 need the technical lead before the holiday and project-manager parts. The `UpcomingLeavesCardTest` expectation changes (rejected leave off the HR card) cite §3.1 Q2. |
| **C3** | `team_overlap` in the approval view; shared "own direct reports" rule (§4.1, T-4). |
| **C4** | Dashboard card = `TeamCoverage::absences(range: today…)`, accepted only (Q2). |

## 10. Sign-off record

| Item | Decision | Decided by (role) | Date |
|---|---|---|---|
| DEC-06 core | Recorded §3 | HR product owner | 2026-10-16 |
| Q1–Q4, Q5 b, Q6 | Recommended options (§3.1) | HR product owner | 2026-10-21 (in session) |
| Colleague set (replaces Q5 a) | Department (any governorate) + center + direct manager (with the manager) + project-manager peers of the same program (grade 3) | HR product owner | 2026-10-21 (in session) |
| §4 contract, §6 technical items | pending | Technical lead | |
