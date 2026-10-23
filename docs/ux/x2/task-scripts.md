# X2 · Task scripts (proposed)

Part of [usability-baseline.md](../usability-baseline.md). Task X2 in [ux-prompts-v3.md](../ux-prompts-v3.md) (R-15).
Status: **v1.1, ready for pilot, awaiting review**. No participant session has been run. Every path and end-state check was walked once by the X2 executor on synthetic data, in English, on 2026-10-19 ([dry-run-2026-10-19.md](dry-run-2026-10-19.md)); that walkthrough is an instrument check, not a result.
Written against `main` @ `6b96806d473342929c536e7842547ae57c40c400` (2026-10-19). Every screen, label and rule below was read from that revision's source. None of it was observed with a user.

---

## 0. Rules shared by all four scripts

### 0.1 Session target and accounts

- **Disposable synthetic target only.** That means X2's session copy: the X1 synthetic SQLite build plus X2's session states, built outside the repository as described in [test-environment.md](test-environment.md) (`*@example.test` accounts), or a successor that P0/A1 approve. **Never** the operational MySQL, never `config/samples/sampleData.php`, never a participant's own account.
- Participants sign in as a **synthetic persona** that matches their real role. The moderator signs them in **before** the session starts, so the password is never typed by or shown to the participant. Sign-in is not part of any task.
- Reset the target between participants: restore the clean snapshot ([test-environment.md §3](test-environment.md)). Every participant should see the same pending requests and the same empty composer.
- **Personas and states per city (ALP, HMS)** are listed in [test-environment.md §2](test-environment.md): HR officer, manager with three direct reports' pending requests (Task C), staff persona with an annual balance (Task A), a shared-surname target (Task B), a contract-ready employee and a two-seat position (Task D), and a Draft reservation (D′). Names, dates and request ids for the cards come from `x2-session-manifest.json`. *(Document-only update 2026-10-19: this replaces the earlier "fixture gaps" note. No card, stop point, end-state check or cap changed, so the scripts version stays 1.0.)*
- **Before each participant (added in v1.1):**
  - **Product tours.** Every page shows a first-visit tour (e.g. "Step 1 of 15" on the dashboard). The "seen" mark is kept in the browser's local storage, separately for each tour, and the dashboard tour differs by role. A participant who meets a tour mid-task loses time to something real users dismissed long ago. So, in the session browser, the moderator signs in as **each** persona of the session, opens every page that persona's tasks use (dashboard, *My Leaves*, *All Leaves*, *Employees*, *Contracts*, the composer), and chooses *Skip tour*. Use the same browser profile for the participant. Do not clear site data between participants; reset only the database.
  - **Bell.** `session-states.php` leaves the staff personas' bells empty and gives each manager only the notices for its own three pending requests. If any other notification appears, rebuild.

### 0.2 Timing

| Mark | Observable event |
|---|---|
| **Start** | The participant has read the scenario card aloud (or had it read to them), has confirmed they understand it, and the moderator says "Start" / "ابدأ". The clock starts at the participant's **first input** after that: mouse, touch or key. |
| **Stop** | The first of: (a) the task's **stop point** is reached, (b) the participant says they are done, (c) the participant says they would give up or ask someone, (d) the **time cap** is reached. |
| **Time on task** | Stop − Start, in whole seconds, from a screen recording (preferred) or a stopwatch. Record which one was used. Page loading counts: it is part of what the user experiences. System-side timings belong to X1. |

The moderator does not explain the UI, does not confirm whether an answer is right, and does not point. A neutral prompt ("What would you do next?") is not help.

### 0.3 Outcome codes (every attempt gets exactly one)

| Code | Meaning | Observable rule |
|---|---|---|
| **S** | Success | The stop point is reached, the **end-state check** for the task passes, and no help was given. |
| **SA** | Success with assistance | Same end state, but the moderator gave help at level H2 or H3 (below). This is reported separately and **not** counted as an unassisted success. |
| **F** | Failure: wrong result | The participant believes they finished, but the end-state check fails. Examples: wrong record, wrong dates, wrong decision, or a duplicate write. |
| **A** | Abandonment | The participant gives up, says they would ask a colleague or IT, or reaches the time cap without reaching the stop point. |

**Help levels** (record each time): **H1** a neutral prompt (not counted as help) · **H2** a hint that names a place or control ("look at the side menu") · **H3** the moderator demonstrates or does the step.

### 0.4 Errors (counted per attempt; they do not by themselves decide the outcome)

An error is a **visible** event that someone else watching the recording could also mark. Each script lists its specific ones. These are common to all four:

- **E-NAV**: opens a screen that is not on any path to the goal, then has to leave it. Each wrong screen counts once.
- **E-VAL**: a validation message is shown to the participant. Record which field.
- **E-LOSS**: entered input is lost before it was saved. Examples: a modal closes via the backdrop, Escape or Cancel, or the page reloads. Record what was lost.
- **E-DUP**: the same write happens twice. Examples: two requests, two contracts, two decisions.
- **E-SYS**: a server error, a stuck loader or a console-visible failure that blocks progress. Record the screen and the time.

### 0.5 After each task

1. **Post-task ease (SEQ)**: the participant answers the single question in [sus-method.md](sus-method.md) §3 (scale 1–7).
2. **Comprehension question**: each script lists one. It records what the participant believes, not what the system does.
3. The moderator checks the end state against the database or the UI and fills in the end-state check. The participant does not see this step.

### 0.6 Order and locale

- Each participant does only the tasks their role can do in the current app (§0.7). The order is rotated between participants, so the same task does not always come first.
- Each participant works in **one** interface language, the one they use at work: `/en/...` or `/ar/...`. That language is recorded. Scenario cards are given in the same language.
- Device: desktop/laptop is the primary profile. A phone run (375×812, the participant's own browser at the synthetic target) is an optional second profile, recorded separately and never mixed into the desktop figures.

### 0.7 Who can do which task (current permissions, `config/access-control.php` and the policies)

| Task | Employee | Manager | HR (`HR-<city>`) | General manager |
|---|---|---|---|---|
| A · Request leave | yes | yes | yes | yes |
| B · Find an employee | no (no `employees.view`) | no | **yes** | yes |
| C · Review a request | no | **yes**: only requests of their own positions' reports | **yes**: requests in their city | yes |
| D · Create a contract | no | no (contracts are read-only) | **yes** | yes (if `contracts.create`) |

Never use a `super-admin` persona for Task D. That role is salary-blind by design (`config/salary.php`), so the pay section would be withheld.

---

## Task A · Request leave

| Item | Definition |
|---|---|
| **Persona** | Synthetic `employee` (also valid: a `manager`) whose latest active contract has an **annual** leave balance with at least 3 days available. The session copy's `staff.alp@example.test` / `staff.hms@example.test` fit ([test-environment.md](test-environment.md)). |
| **Target-date rule** | The moderator chooses **one working day 8–14 days after the session date**. It must not be a weekend day at the persona's center (`centers.weekends`), a holiday, or a day in December (December leave is restricted by policy, see [decisions.md](../decisions.md)). Write it on the card as a full date. |
| **Starting screen** | Dashboard (`/{locale}`), fully loaded, signed in as the persona. |
| **Expected path (current app)** | Side menu, section *Time & Leave* / *الإجازات* → *My Leaves* / *اجازاتي* (`/leaves`) → *Add New* / *إضافة جديد* → modal *Add New*: *Type* (searchable list) → a *Day / Hour* choice appears, Day is preselected → *Start Date* and *End Date* → *Reason* (required) → *Save* / *حفظ* → *Confirmation* dialog summarising type, dates, duration and reason → *Save*. *Holidays and Leaves* / *العطل والاجازات* is only the page breadcrumb, not a menu item. *(Walked in EN on 2026-10-19; AR labels from source.)* |
| **Stop point** | The success toast appears and the modal closes, **or** the participant says they are done. |
| **Time cap** | 5 minutes. |
| **Proposed target** | Median time ≤ 60 s for unassisted successes: a **proposed target**, not a current result or release guarantee (see [usability-baseline.md](../usability-baseline.md) §5). |

**Scenario card (EN):**
> You would like to take **[full date]** off. It is one full day of your annual leave. Please ask for this leave in the system. If it asks for a reason, write "Family visit".

**Scenario card (AR):**
> ترغب بأخذ يوم **[التاريخ كاملًا]** إجازة. هو يوم كامل من إجازتك السنوية. يرجى طلب هذه الإجازة من خلال النظام. إذا طُلب منك سبب، اكتب «زيارة عائلية».

**End-state check (all must be true for S):**
1. Exactly **one** new leave request exists for the persona, created during the attempt.
2. Its balance is the **annual** leave type, and its mode is **day**.
3. Its start date = end date = the target date.
4. The reason is not empty.
5. Its status is **Pending**.

**Task-specific errors:**
- **A-E1**: picks a wrong leave type, then corrects it. If not corrected, this is F.
- **A-E2**: chooses hourly mode or an hour range.
- **A-E3**: sets a wrong start or end date. A one-day request with a mismatched range counts.
- **A-E4**: the chosen leave type **reverts to the placeholder** after another field updates. Record the locale. This is the known A3-R lead; record it only if it is seen.
- **A-E5**: uses the modal's *Cancel* and loses input. That control links to `leaves.index` (the known A5-M lead); count it as E-LOSS too.
- **A-E6**: dismisses the confirmation dialog without realising the request was not sent.

**Abandonment signals:** the participant cannot find where to request leave, says "I would ask HR", or cannot get past a validation message.

**Comprehension question:** "What happens to this request now? Who acts next?" / «ماذا يحدث لهذا الطلب الآن؟ من الذي سيتخذ الإجراء التالي؟» Record the answer verbatim (anonymised). In the current app a pending request is decided by the direct manager (or HR in the city) and then marked *Seen by HR*. Note whether the answer matches.

---

## Task B · Find an employee

| Item | Definition |
|---|---|
| **Persona** | Synthetic city HR officer (`hr.alp@example.test` / `hr.hms@example.test` in the session copy). |
| **Target employee** | One active employee in the officer's city whose **surname is shared by at least two other employees in scope**. The session manifest names one per city (in the 2026-10-19 check build, 5 people in ALP and 9 in HMS shared the target's surname). This forces disambiguation. The card gives the full name **in the participant's interface language**, plus one distinguishing fact (position *or* center). |
| **Starting screen** | Dashboard (`/{locale}`). |
| **Expected path (current app)** | Side menu *Employee Management* / *إدارة الموظفين* → *Employees* (`/employees`) → type the name in *Search All Columns*, or in the *Name* column's own search box (both match `first_name_en/ar` and `last_name_en/ar`) → identify the right row by position → read the *Work Email* column, **or** open the profile (`/employees/{username}/show`). **Also valid:** the dashboard's *Contact The Team* card has its own search box and *View Profile* links; reaching the target's profile that way is a success too. *(Walked in EN on 2026-10-19.)* |
| **Target placement** | The target must **not** be on the first two pages of the default list (sorted by ID, 15 rows a page), so it cannot be read off without searching. `session-states.php` enforces this. |
| **Stop point** | The participant **says the work email aloud**, **or** opens the target's profile and says "this is them". |
| **Time cap** | 2 minutes. |
| **Proposed target** | Median time ≤ 15 s for unassisted successes: a **proposed target**, not a current result or release guarantee. |

**Scenario card (EN):**
> A colleague needs to email **[First Last]**, who works as **[position]**. Find this person in the system and tell me their work email address.

**Scenario card (AR):**
> يحتاج زميل إلى مراسلة **[الاسم الكامل]** الذي يعمل بوظيفة **[المسمى]**. ابحث عن هذا الشخص في النظام وأخبرني ببريده الإلكتروني الخاص بالعمل.

**End-state check (all must be true for S):**
1. The email said, or the profile opened, belongs to the **target employee's id**, not a namesake.
2. The participant did not leave the authorized HC-HR screens. For example, they did not ask the moderator.

**Task-specific errors:**
- **B-E1**: opens or names the wrong person with the same surname.
- **B-E2**: a search returns no rows (misspelling, wrong script, or a filter left on). Record the search text **pattern** only, e.g. "first name, Latin script", not the text itself.
- **B-E3**: uses the header or another non-employee search that does not find people.
- **B-E4**: scrolls or pages through the list instead of searching (note it; this is not an error unless the time cap is hit).

**Optional cross-script variant B′** (record separately): give the name in the **other** script from the interface, e.g. an Arabic name in the English UI. The current search covers both columns. This variant feeds C2's AC-C2-01 later; it is not part of the core figure.

**Comprehension question:** "If two people had this name, how would you be sure you had the right one?" / «لو كان هناك شخصان بنفس الاسم، كيف ستتأكد أنك وجدت الشخص الصحيح؟»

---

## Task C · Review a request

| Item | Definition |
|---|---|
| **Persona** | A synthetic **manager** whose active contract's position directly manages the requester (`LeaveRequest::scopeManageableBy`). Also valid: a city HR officer for a requester in their city. Record which one. |
| **Fixture state** | The requester has exactly **one pending** leave request for a stated date range. The persona's scope also holds **at least two other pending requests** from other people, so picking the right one is part of the task. |
| **Starting screen** | Dashboard (`/{locale}`). Do **not** start on the notification. If the participant chooses to use the bell, that is a valid path. |
| **Expected path (current app)** | Side menu *Leaves Management* / *ادارة الاجازات* → *All Leaves* / *جميع الإجازات* (`/leaves/all`), or the bell notification for the request → click the target's *Pending* row → *Leave Decision* / *قرار الإجازة* modal (name, dates, duration, reason) → *Action*: *Reject* → a *Reject Reason* / *سبب الرفض* field appears (required; saving it empty shows "The Reject Reason field is required." under the field) → *Save* → toast, and the row shows *Rejected*. *(Walked in EN on 2026-10-19.)* |
| **Stop point** | The decision modal closes with the success toast, **or** the participant says they are done. |
| **Time cap** | 4 minutes. |

**Scenario card (EN):**
> **[Requester full name]** has asked for leave from **[start]** to **[end]**. Your team has a field visit that week, so you cannot approve it. Please turn the request down and tell them why: "Team field visit that week".

**Scenario card (AR):**
> طلب/ت **[اسم مقدم الطلب]** إجازة من **[البداية]** إلى **[النهاية]**. لدى فريقك زيارة ميدانية في ذلك الأسبوع، لذلك لا يمكنك الموافقة. يرجى رفض الطلب وتوضيح السبب: «زيارة ميدانية للفريق في ذلك الأسبوع».

**End-state check (all must be true for S):**
1. The target request's status is **Rejected**, and its reject reason is not empty.
2. **No other** request changed status during the attempt.
3. Only one decision was recorded (no E-DUP).

**Task-specific errors:**
- **C-E1**: opens a different person's request. Counted once per wrong request opened.
- **C-E2**: picks *Accept* first, or saves without a reason. The reason shows as E-VAL.
- **C-E3**: uses *Seen by HR* (HR personas) as if it were the decision.
- **C-E4**: cannot find the pending request among the other rows or tabs. Note it if the participant changes the status tab.

**Comprehension question:** "What will [requester] see now, and does anyone else still need to act?" / «ماذا سيرى [مقدم الطلب] الآن، وهل ما زال على أحد آخر اتخاذ إجراء؟»

---

## Task D · Create a contract (composer)

**Terminology (must stay exact, SHARED RULES):** "create a contract draft" in the plan means **preparing a new contract in the contract composer and creating it**. That is different from:

- the contract **type `Draft`** (`App\Enums\ContractType::Draft = 4`), which is a position reservation made during employee creation and later *completed* by the composer (the "draft found" banner);
- an **employee lifecycle draft** (DEC-04 / B5), which does not exist yet.

The core script uses an employee **without** a Draft-type reservation. The reservation case is the optional variant D′. *(Assumption X2-AS-1, see [usability-baseline.md](../usability-baseline.md) §8. Change it if the reviewer reads the plan differently.)*

| Item | Definition |
|---|---|
| **Persona** | Synthetic city HR officer (salary-visible role). Never `super-admin`. |
| **Fixture state** | A synthetic active employee in the officer's city with **no active contract** and **no Draft-type contract**. There is a vacant position (seats left > 0) in a program and center of that city. Synthetic pay values only. |
| **Starting screen** | Dashboard (`/{locale}`). |
| **Expected path (current app)** | Side menu *Employee Management* / *إدارة الموظفين* → *Contracts* / *العقود* (`/contracts`) → *Add New* / *إضافة جديد* → composer (`/contracts/create`), three sections opened in turn: **1 *Assignment & Contract* / *التعيين والعقد*:** *Employee*, *City* (locked to the HR officer's city), *Program*, *Positions* (each option shows its center and seats, e.g. "0 / 2" or "Full"), *Contract Type* (cards: *Volunteer Contract* and *Specific Voluntary Service Contract* run to 31 Dec; *Trial Volunteer Contract* runs 3 months), *Type* (Full Time / Part Time), *Location* (Onsite / Online), *Start Date*, *End Date* (pre-filled from the contract type; at most one year after the start) → *Continue*. **2 *Schedule* / *الدوام*:** *Fixed* or *Flexible*; work hours from/to (default 09:00–17:00); *From day* / *To day* → a printed-wording preview appears. **3 *Pay* / *الأجر*:** *Currency*, *Pay* (Fixed / Flexible), *Salary*, *Salary Per* (Month / Hour / Session). Progress reads "3 of 3 sections complete" and *Create contract* / *إنشاء العقد* becomes active → redirect to the contract details page. *(Walked in EN on 2026-10-19.)* |
| **Stop point** | The contract details page (`/contracts/{id}`) of the new contract is shown, **or** the participant says they are done. |
| **Time cap** | 10 minutes. |

**Scenario card (EN):**
> **[Employee full name]** is joining the **[program]** program as **[position]** at **[center]**, starting **[start date]**. Give them a **Volunteer Contract**, full time, on site. They will work **Sunday to Thursday, 9:00 to 17:00**. Their pay is **[synthetic amount] per month**. Please create this contract in the system.

**Scenario card (AR):**
> سينضم/ستنضم **[الاسم الكامل]** إلى برنامج **[البرنامج]** بوظيفة **[المسمى]** في **[المركز]**، اعتبارًا من **[تاريخ البداية]**. يرجى منحه/منحها **عقد تطوّع**، بدوام كامل، في مكان العمل. أيام العمل **من الأحد إلى الخميس، من الساعة 9:00 حتى 17:00**. الأجر **[مبلغ تجريبي] شهريًا**. يرجى إنشاء هذا العقد في النظام.

The card gives no end date: for a Volunteer Contract the composer fills it in (31 Dec), and the participant may leave it. The AR wording of the contract type and the other field names must be checked against the AR composer in the pilot (P3/P4).

**End-state check (all must be true for S):**
1. Exactly **one** new contract exists for the target employee.
2. Its position (and so its program and center) matches the card; contract type = *Volunteer Contract*; type = Full Time; location = Onsite; start date = the card's date.
3. End date = the composer's default for that contract type and start date (31 Dec of the start year), **or** any later date the participant chose on purpose and can explain. A shorter end date counts as F.
4. Schedule: fixed, Sunday to Thursday (`work_day_from` 0, `work_day_to` 4), 09:00–17:00.
5. Pay is fixed, per month, and matches the synthetic amount. Check with the HR persona only; the figure is never copied into the observation record.
6. No other contract changed.

**Task-specific errors:**
- **D-E1**: a city/program/position dependency blocks progress, e.g. the participant cannot find the position because a prerequisite field is empty or locked.
- **D-E2**: picks a position with no seats, or reads the "no seats left" message.
- **D-E3**: enters wrong dates or a wrong type, then corrects them. If not corrected, this is F.
- **D-E4**: a pay validation hint is shown. Record the hint key only, never the amount.
- **D-E5**: leaves the composer (navigation or reload) and loses input (E-LOSS).
- **D-E6**: submits twice (E-DUP).

**Optional variant D′** (record separately): the target **has** a Draft-type reservation. Observe whether the participant notices and understands the "draft found" banner, and whether the reservation is completed rather than a second contract being made.

**Comprehension question:** "Is this contract now in effect? Is anything still needed before the person can start? Will it renew by itself?" / «هل هذا العقد ساري المفعول الآن؟ هل هناك أي شيء مطلوب قبل أن يبدأ الشخص العمل؟ هل سيتجدد تلقائيًا؟» (In the walkthrough the new contract was saved with auto-renew **on** by default, and the composer did not ask about it. Note whether the participant knows.)

---

## Change control

These scripts are the **fixed baseline instrument** for X2-R and G-C (AC-X2-R-01, AC-G-C-01). After the first real session, a change to any card, stop point, end-state check or time cap must be versioned here, with the reason. Results gathered with different versions are not pooled.

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-19 | First version (X2). Not yet piloted. |
| 1.1 | 2026-10-19 | After the moderator walkthrough ([dry-run-2026-10-19.md](dry-run-2026-10-19.md)), before any participant session, so no data is split by version. **Expected paths** A–D corrected to the real menu and screens (A: the *Time & Leave* section, not "Holidays and Leaves"; B: two search boxes and the dashboard *Contact The Team* route accepted; C: the *Leaves Management* group; D: the composer's real fields). **Task B:** the target may not be on the first two list pages. **Task D:** the card now uses the composer's terms (Volunteer Contract, full time, on site, Sunday–Thursday 9–17, pay per month, no end date); the end-state check has 6 items matching those fields; an auto-renew comprehension question was added. **§0.1:** tour and bell preparation. Caps, outcome codes and Tasks A–C cards are unchanged. Still subject to reviewer decisions RD-1/RD-2. |
