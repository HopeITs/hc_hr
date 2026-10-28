# D1-D · Unified employee profile: visibility, timeline and editing contract (proposed)

Version: **profile/1.0** · Date: 2026-10-27 · Task: D1-D in [ux-prompts-v3.md](ux-prompts-v3.md) (R-11)
Owner role: UX designer + HR product owner · Reviewer: QA engineer + technical lead · Consumer: **D1** (also read by D2)
Status: **HR decisions recorded (Q1–Q5) and technical lead approval of §5, §5.1, §7 (2026-10-27).** This is a design document. **No application code, route, schema or data was changed.** Every component, field and route introduced below is **PROPOSED** until D1 implements it.

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision inspected | `hr-ux-by-rafi` @ `aa983edc` (C4 committed). |
| C0 | [interaction-contract.md](interaction-contract.md): the profile is reached from search, the directory/drawer, the employees table and *My profile*; return paths §6. |
| A2 | [access-policy.md](access-policy.md) (approved 2026-10-20) and [a2-implementation.md](a2-implementation.md). Fixes the **field tiers** D, R, P, F, B (§2.2 below), including who sees each one. D1-D does not change them. |
| B5-D | [b5-d-decision-sheet.md](b5-d-decision-sheet.md): draft vs complete record, "awaiting contract" state. |
| Data | None read. Synthetic examples only (§8). |

## 2. Current behaviour (traced in source at `aa983edc`)

### 2.1 Surfaces

| Surface | Route / component | Notes |
|---|---|---|
| Profile page | `employees.show` (`/employees/{username}/show`) and `my-profile`, both `Employee\EmployeeProfile` | <ul><li>Authorized by `viewDirectory`. An unknown or forbidden record gives the same 404.</li><li>Sections are loaded only when the viewer's tier allows them.</li><li>Flags are re-computed on every request.</li></ul> |
| Drawer | `Position\ProfileDrawer` (org chart, directory, search) | Directory tier only; links to the profile page. |
| Edit page | `employees.edit` (`/employees/{employee}/edit`, `Employee\EmployeeEdit`) | <ul><li>Requires `employees.update` + the city.</li><li>12 tabs, each with its own *Save*: personal info, contact, photo, education, courses, experience, relatives, attachments, badges, items/checklist, contracts history.</li><li>A save reports "saved" or "no changes" (`persist()`).</li><li>**No conflict detection**: the last write wins.</li></ul> |
| Private files | `employee-files/{media}/download` (`EmployeeDocumentController`) | <ul><li>`viewPrivateDocuments`: self, or HR with `employees.update` in the city.</li><li>Covers attachments and education/course/experience files.</li><li>Answers **403** when refused, not 404.</li></ul> |
| Photo | `employee-photo/{employee}` via `Employee::avatar_url` | `viewAvatar` = Directory tier. |
| Timeline | — | **None exists.** Events are spread over the contracts history, badges and leave pages. |

### 2.2 Field tiers already decided (A2, unchanged here)

| Tier | Content on the profile | Who (active role) |
|---|---|---|
| **D** Directory | Header: photo, name, position, program, department, center, city, work contact, board badge, manager | Everyone, for active staff. Former staff: GM, HR-SYR, super-admin, HR of that city. |
| **R** Records | Career history (contracts without pay, type, termination reason, weekly hours), education, experience, skills/interests, courses, years of service | Self, own **direct reports** (manager), GM, HR in city, HR-SYR, super-admin |
| **P** Private | National ID, birthday, personal contact, family, private attachments | Self, HR in city |
| **F** Financial | Salary, currency, pay tiers, badge salary figures | HR in city **and** `canViewSalary()` (super-admin never) |
| **B** Badges | Warnings, rewards, salary increases (pay only with F) | `Badge::scopeVisibleTo`: `badges.view` + regional HR own city, HR-SYR/GM all, manager own reports. **The owner sees none of their own.** |

## 3. Proposed profile layout (AC-D1-D-01, sections)

One page for every viewer. Sections appear **in this order**, and only when the viewer's cell allows them. A missing section leaves **no placeholder**: "restricted" is never shown, so the existence of an event is never hinted (task step 2).

| # | Section (EN / AR) | Tier | Edit (where, who) |
|---|---|---|---|
| 1 | Header / الرأس: photo, name, position, status chips (active, on leave today, board) | D | Name: HR only. Photo: HR, or **self through HR approval** (§5.1) |
| 2 | Summary / ملخص: years of service, contract status, city, manager | D (+R for years) | — |
| 3 | Work contact / تواصل العمل | D | HR |
| 4 | Timeline / السجل الزمني (§4) | per event | — (read-only; each event links to its own page) |
| 5 | Employment overview + career history / الوظيفة والمسار | R (+F pay) | Contracts are edited in the contract composer, never here |
| 6 | Education · Experience · Courses · Skills / التعليم والخبرة والدورات والمهارات | R | HR; **self**: skills directly, education/experience/courses through HR approval (§5.1) |
| 7 | Badges / الشارات | B (+F) | HR (badges page/tab) |
| 8 | Personal information · Personal contact · Family / المعلومات الشخصية | P | HR; **self**: personal contact directly, family through HR approval (§5.1). Identity fields (name, national ID, birth date) HR only. |
| 9 | Documents / المستندات | P | HR |

## 4. Timeline (AC-D1-D-01, events)

### 4.1 Events that exist in the data

| Code (PROPOSED) | Event | Source | Sensitive part |
|---|---|---|---|
| `contract.started` | Joined / new contract / renewal | `contracts.start_date`, type, position | Pay (F) |
| `position.changed` | Moved to another position | consecutive contracts, different `position_id` | — |
| `contract.ended` | Contract ended / terminated | `end_date`, `terminated_at`, termination reason | **Termination reason** |
| `badge.reward` · `badge.warning` · `badge.salary` | Reward, **warning (disciplinary)**, salary increase | `badges` | **Warning** existence; salary figures (F) |
| `leave.taken` | Accepted leave | `leave_requests` | **Type** (sick, maternity…); reason never |
| `items.delivered` · `items.returned` | Equipment handed out / returned | `checklists` | — |
| `record.created` · `record.completed` · `account.activated` | Record created, completed, login account created (B5) | employees, users | — |
| `card.disabled` · `card.reissued` | Digital ID card events | `security` log | — |

**Never in any timeline, payload or export:** leave **reasons**, reject reasons, HR notes on leave, badge **notes** below HR level, badge **reason** text to anyone but HR and the badge's owner (Q2), pay without F, the activity log's field diffs (who changed which field stays in *Activity log*, HR-SYR only), national ID.

### 4.2 Visibility matrix (Q1–Q3 answered)

Rule: **an event a viewer may not see is not loaded, not counted, and leaves no gap.** The timeline is built on the server per request from the active role, never from one full list that is filtered in the browser.

| Event | Self | Direct manager | Other colleague (D only) | GM | HR in city | HR-SYR | super-admin |
|---|---|---|---|---|---|---|---|
| `contract.started`, `position.changed` | ✓ | ✓ | — | ✓ | ✓ (+pay if F) | ✓ (+pay) | ✓ (no pay) |
| `contract.ended` (date) | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ |
| ↳ termination **reason** | ✓ (Q3 = A) | ✓ (Q3 = A) | — | ✓ | ✓ | ✓ | ✓ |
| `badge.reward` | ✓ (Q2 = C) | ✓ if `badges.view` | — | ✓ | ✓ | ✓ | as B |
| `badge.warning` | ✓ (Q2 = C) | ✓ if `badges.view` | — | ✓ | ✓ | ✓ | as B |
| `badge.salary` | — | — | — | — | ✓ with F | ✓ with F | — |
| `leave.taken`, `items.*` | not in the timeline (Q1 = B); they stay on *My leaves*, the team calendar and *My items* | | | | | | |
| `record.*`, `account.activated`, `card.*` | not in the timeline (Q1 = B: career and badges only); they stay in the activity and security logs | | | | | | |

A colleague at Directory tier sees **no timeline at all**, because the timeline is career history (R) or higher.

## 5. Editing: save, validation, conflicts (AC-D1-D-02)

| Topic | Proposed rule |
|---|---|
| **Where** | <ul><li>Each editable section of §3 gets an **Edit** button for those allowed.</li><li>It opens that section's form **in a dialog on the profile**, with the same fields and save as today's tab.</li><li>The old `employees.edit` page and its tabs stay until D1 is verified (rollout), then become a redirect to the profile with the section open (`?edit=education`).</li></ul> |
| **Who** | Server-checked per section, never by button visibility: `EmployeePolicy::update` (HR in city), or the new **self** rules of §5.1. The GM keeps today's `canEdit` (it shows the button; `update` still decides). |
| **Validation** | Field errors under the field; the summary list on failure; focus on the first error (A5/G-B-3). The dialog keeps the entries on error (B2). |
| **Save feedback** | One toast: "saved", or "no changes" when nothing was written (today's `persist()` rule). After saving, the section re-renders in place; no full reload. |
| **Conflicts** | <ul><li>**New:** each section form carries the section's version (the newest `updated_at` of its rows).</li><li>On save, a changed version means someone else saved first. Nothing is written, the dialog stays open with the entries kept, and it says «حدّث شخص آخر هذا القسم. راجع القيم الحالية ثم احفظ مجددًا.» with *Show current values*.</li><li>The same outcome vocabulary as C3 (`changed`).</li></ul> |
| **Delete a row** | Confirm first (A5). A saved row is deleted on confirm, as today. |
| **Close with changes** | Asks first (A5-M close guard). |
| **Completeness** | <ul><li>The header shows the B5 state for HR only: *draft*, *ready, awaiting contract*, *active*.</li><li>Each section HR may edit shows a small "missing: …" line for its own required fields.</li><li>Completeness is computed from the same rules as B5's create form, never a second list.</li></ul> |

### 5.1 Self-editing (Q5 = 4)

| What the employee edits | How it is saved | Rule |
|---|---|---|
| Personal phone, personal email, address; skills and interests | **Directly**, with the same validation and "saved / no changes" toast | Policy (PROPOSED) `EmployeePolicy::updateOwnContact`: self only, active record. |
| Education, courses, experience (with their files), photo, family | **Proposed change, waiting for HR.** The profile shows the current values plus a «بانتظار موافقة الموارد البشرية» note on the changed section, for the employee only. | The change becomes visible only when HR in the employee's city approves it. Rejected → the employee sees the reason, and the old values stay. |
| Name, national ID, birth date, position, contract, pay, badges, work contact | **Never** by the employee | HR only, as today. |

**Approval flow (PROPOSED, for D1):**
- **Storage:** a new table `profile_change_requests` (employee, section, proposed values as JSON, uploaded files in a private media collection, status, decided by/at, reason). This is the **one schema change** this contract asks of D1. It is additive and new-table only, and `down()` drops it. Approved data is written by the same section save HR uses, inside one transaction, with the conflict check of §5: if the section changed since the proposal was made, HR sees the current and the proposed values side by side.
- **HR's work** arrives in the C3 **work inbox** as a new source `profile:{id}:review` (approve / reject with a required reason; never in a batch), scoped to HR of the employee's city, the same way checklists are.
- **Who is notified:** HR on submission (the inbox count); the employee on decision (B6 notification).
- **Limits:** one open request per section; a new proposal replaces the open one (the employee is told). Files keep the existing private download rules (self, HR; Q4).
- **Logging:** every direct save and every approval is in the activity log with the actor. Pay and identity never pass through this flow.

## 6. Questions for HR (AC-D1-D-01) — answered 2026-10-27

| # | Question | Options | Recommendation |
|---|---|---|---|
| **Q1** ✅ | What does the timeline include? | **A.** Career only (contracts, position changes, end) · **B.** Career + badges · **C.** Career + badges + accepted leave + items · **D.** No timeline; keep the sections | **Answered B (2026-10-27):** career + badges, each by the viewer's rights. No leave or items events. |
| **Q2** ✅ | May the employee see **their own** badges (rewards, warnings) on their profile? Today they see none. | **A.** No (as today) · **B.** Rewards only · **C.** Rewards and warnings | **Answered C (2026-10-27):** own rewards and warnings, with the reason; never salary figures or HR's internal notes. A change to `Badge::scopeVisibleTo` for the owner, implemented in D1. |
| **Q3** ✅ | Who sees the **termination reason**? Today: everyone with R (incl. the employee and the direct manager). | **A.** As today · **B.** HR and GM only; the employee and manager see "ended" with the date | **Answered A (2026-10-27):** unchanged: everyone with R (self, direct manager, HR, GM). |
| **Q4** ✅ | Should a manager be able to download their reports' **certificate files** (education, courses)? Today no: only self and HR. | **A.** No (as today) · **B.** Yes, for direct reports | **Answered A (2026-10-27):** unchanged; the manager sees the list, not the files. HR added: the **employee must be able to edit their own data** (see Q5). |
| **Q5** ✅ | What may an employee **edit on their own profile**? Today only HR edits. | 1. Personal contact + skills, saved directly · 2. + education, courses, experience, photo, saved directly and HR notified · 3. as 2, but education/courses/experience/photo wait for HR approval · 4. as 3 + family, with HR approval | **Answered 4 (2026-10-27):** personal contact and skills/interests saved directly; **education, courses, experience, photo and family** wait for HR approval (§5.1). |

## 7. Links, documents and photos (AC-D1-D-03)

| Item | Contract |
|---|---|
| Profile URL | `employees.show` by **username** stays canonical; `my-profile` stays. No new URL. |
| Section deep link | `employees.show?section=education` scrolls to the section; `?edit=education` opens its edit dialog when allowed. **When it is not allowed, the page simply opens**: no error that would reveal the section exists. |
| Legacy edit route | `employees.edit` (`/employees/{id}/edit`) keeps working during rollout. Afterwards it redirects to `employees.show?edit=personal` with the same authorization. Bookmarks and notification links keep working. |
| Return | <ul><li>Opened from search, the drawer, the employees table or the inbox, the profile's back control returns there through `SafeReturn` (C2).</li><li>A saved edit returns to the profile section, not to the table.</li></ul> |
| Photo | Always `Employee::avatar_url` (the authorized route), never `getFirstMediaUrl` or a storage path. |
| Documents | <ul><li>Always `employee-files/{media}/download` (`viewPrivateDocuments`), never a public URL.</li><li>**Change for D1:** a refused or unknown file answers **404** like an unknown one, not 403 (A2 §4.4 existence rule).</li><li>Education, course and experience files: self and HR only (Q4 = A).</li></ul> |
| Pay | Never in the timeline, a payload, the dialog or an export unless F. `super-admin` is salary-blind. |

## 8. Synthetic examples

Personas: Lina (employee, Homs) reports to Sami (manager); Rana is a Homs colleague in another department; Huda is HR-HMS; Karim is HR-ALP. Lina has a contract renewal (2026-01-01), a reward (2026-03) and a warning (2026-06); an older contract ended with reason "relocation".

| # | Viewer | Expected (with the recommendations) |
|---|---|---|
| P1 | Rana (colleague) | Header, summary, work contact. **No timeline, no sections below.** The same page shape as for someone with no history. |
| P2 | Sami (direct manager) | + career, education…; timeline: renewal, contract ended **with** its reason (Q3 A), reward and warning only if Sami holds `badges.view`. |
| P3 | Lina (self) | + personal sections; timeline: renewal, reward, **warning** with its reason (Q2 C), "contract ended" with the reason (Q3 A); Edit on personal contact and skills (saved at once); a new course goes to Huda's inbox and shows "waiting for HR" to Lina only (Q5 4). |
| P4 | Huda (HR-HMS) | Everything, with pay if `canViewSalary()`; Edit everywhere; completeness lines. |
| P5 | Karim (HR-ALP) | Directory tier only (other city): as P1. |
| P6 | Lina becomes inactive | Rana and Sami: 404 (A2D-Q3). Huda: full profile. |

## 9. Acceptance criteria

| AC | Status | Evidence |
|---|---|---|
| **AC-D1-D-01** Profile and timeline have an approved field/event visibility matrix | **Met** (HR answers Q1–Q5, 2026-10-27) | §2.2 (fields, A2-approved), §4.2 (events), §5.1 (self-editing), §6 |
| **AC-D1-D-02** Each editable section has a save/validation/conflict design | **Met (design)** | §5, §5.1 |
| **AC-D1-D-03** Authorized avatar/document paths and legacy route compatibility are specified | **Met (design)** | §7 |

Verification: source trace of the files in §2 at `aa983edc`. No application test was run or added, and none is claimed from this document.

## 10. Sign-off record

| Item | Decision | Decided by (role) | Date |
|---|---|---|---|
| Q1 | **B**: career + badges | HR product owner | 2026-10-27 (in session) |
| Q2 | **C**: own rewards and warnings (reason shown; no pay, no HR notes) | HR product owner | 2026-10-27 (in session) |
| Q3 | **A**: termination reason as today (R tier) | HR product owner | 2026-10-27 (in session) |
| Q4 | **A**: certificate files as today (self and HR only) | HR product owner | 2026-10-27 (in session) |
| Q5 | **4**: self-editing, direct for contact/skills, HR approval for education, courses, experience, photo, family | HR product owner | 2026-10-27 (in session) |
| §5, §5.1 (incl. the new `profile_change_requests` table), §7 | **Approved** | Technical lead (in session) | 2026-10-27 |
