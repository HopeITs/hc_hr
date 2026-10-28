# D1 · Unified profile: timeline and in-place editing

Task: D1 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-11) · Date: 2026-10-27 · Branch `hr-ux-by-rafi`
Input: D1-D [profile-contract.md](profile-contract.md) (HR answers Q1–Q5, 2026-10-27).
Status: **built and tested, including the self-edit approval flow (§4); review pending. One additive migration (`profile_change_requests`, approved by the technical lead 2026-10-27).**

## 1. What changed for people

| Who | Change |
|---|---|
| Everyone with the career tier (self, direct manager, HR in city, GM, HR-SYR, super-admin) | A **timeline / السجل الزمني** at the top of the profile: joined, new contract, moved to a new position, contract ended **with its reason** (Q3 = A), and badges. A colleague with only the Directory tier sees **no timeline at all**, with nothing that hints at events. |
| The employee, on their own profile | <ul><li>Sees **their own rewards and warnings**, with the reason (Q2 = C). Never a salary increase, a figure, or HR's notes.</li><li>**Edits** personal contact (phones, email, address) and skills/interests **in place**; saved directly (Q5).</li></ul> |
| HR in the employee's city | Edits personal contact, skills and **work contact** in place. Everything else is still on the edit page, which is unchanged. |
| Salary | The timeline shows contract pay and salary increases **only** with `canViewSalary()` + HR in scope. super-admin never sees them. |
| Private files | A refused or unknown file now answers **404** instead of 403 (contract §7). |

## 2. How

| Part | Where | Notes |
|---|---|---|
| Timeline | [`EmployeeTimeline`](../../app/Services/EmployeeTimeline.php) | <ul><li>Built from the rows the profile already loads for the viewer's tiers, so a hidden event is never read.</li><li>Plain arrays, no models.</li><li>Codes: `contract.joined/started/ended`, `position.changed`, `badge.reward/warning/salary`.</li></ul> |
| Own badges | `EmployeeTimeline::ownBadges()` | Rewards and warnings only, selecting `type, date, reason`: no salary, no notes. `Badge::scopeVisibleTo` is **unchanged**, so the Badges page and others are not widened. |
| Section editing | `EmployeeProfile::openSection/saveSection/reloadSection` | <ul><li>`SECTIONS` lists each section's columns: `personal-contact`, `skills`, `work-contact`.</li><li>`canEditSection()` is checked on every call: `EmployeePolicy::update` (HR in scope), or the **active** owner for contact and skills.</li><li>A save writes **only** that section's columns; other keys sent by the browser are ignored.</li><li>Validation uses the edit page's rules; errors appear under each field and the entries are kept.</li></ul> |
| Stale edits | `sectionVersion` (locked) | <ul><li>A hash of the section's values when the dialog opened, re-checked under `lockForUpdate`.</li><li>If someone else saved meanwhile, nothing is written, the entries are kept, and the dialog shows «حفظ شخص آخر هذا القسم…» with **عرض القيم الحالية**.</li></ul> |
| Close guard | `GuardsModalClose` (A5-M) | Closing with changes asks first. |
| Deep link | `?edit=skills` | Opens the dialog when allowed; otherwise the page simply opens, with no error. |
| Audit | `Employee::getActivitylogOptions` | `skills` and `interests` added to the log, since the owner now edits them. Contact fields stay **name-only** (`RedactsSensitiveActivityLog`). |
| Security | `config/access-control.php` | `employee.employee-profile` actions listed with their checks. |
| Photo | unchanged | `Employee::avatar_url` (authorized `employees.avatar` route, default fallback). |

## 3. Tests (`tests/Feature/EmployeeProfileD1Test.php`, 10 tests)

| AC | Tests |
|---|---|
| **AC-D1-01** Photo via the authorized endpoint, with fallback | `test_the_photo_uses_the_authorized_route_with_a_fallback`: default image without media; `employees.avatar` route with media; never `/storage/`. |
| **AC-D1-02** Restricted events and their existence omitted | <ul><li>`test_a_directory_only_colleague_gets_no_timeline_and_no_trace_of_events`: no section, no `data-event`, no reason or badge text, empty timeline data.</li><li>`test_the_direct_manager_sees_career_with_the_termination_reason_but_no_pay`: also checks labels are translated, not raw keys.</li><li>`test_the_owner_sees_own_rewards_and_warnings_without_pay_or_notes`.</li><li>`test_hr_with_pay_sees_the_salary_increase_and_a_salary_blind_role_does_not`.</li></ul> |
| **AC-D1-03** One section saved, the others untouched | <ul><li>`test_the_owner_saves_skills_and_nothing_else_changes`: national ID, work phone, name and contracts unchanged; a forged extra field ignored; audit by the owner; contact logged without its value.</li><li>`test_who_may_edit_which_section`: owner, HR in city, HR of another city, colleague, former employee.</li><li>`test_a_stale_save_writes_nothing_and_keeps_the_entries`.</li><li>`test_an_invalid_entry_is_reported_on_its_field_and_nothing_is_saved`.</li><li>`test_deep_links_open_an_allowed_section_only_and_the_old_edit_page_still_works`.</li></ul> |

Updated, each citing its decision:
- `EmployeeProfileBadgesVisibilityTest`: the owner now sees their own badges (Q2 = C); a salary increase, the figure and notes are still hidden.
- `SecurityAuthorizationTest`: a refused file is 404 (§7).

**Found by the tests and the browser, and fixed:**
- A forged key in the dialog state broke the view, which listed keys from the browser. It now lists the section's own columns.
- The close-guard scanner misread a `=>` inside the dialog tag.
- Event labels showed raw keys, because the translator reads the dot in `contract.joined` as nesting. Keys now use a dash, and the test checks the labels.

**Full suite:** 1021 tests, 5754 assertions, 0 failures (with the approval flow).

## 4. Self-editing with HR approval (contract §5.1, Q5 = 4)

| Part | Where | Rule |
|---|---|---|
| Table | migration `2026_10_28_000001_create_profile_change_requests_table` | <ul><li>New table only; nothing existing is altered, and SQLite rebuilds nothing.</li><li>`down()` drops it; approved entries already live in their own tables.</li><li>**Run `php artisan migrate`** on each database.</li></ul> |
| Writer | [`ProfileChangeRequests`](../../app/Services/ProfileChangeRequests.php) | <ul><li>`submit`: the **active owner** only. It is validated with the edit page's rules. Files go to the private disk; a photo is re-encoded once here, exactly like an HR upload.</li><li>One open proposal per section: a newer one marks the old one `replaced`.</li><li>`approve` writes the entry to its own table and copies the file without re-encoding, in one transaction under a row lock.</li><li>`reject` needs a reason.</li><li>Missing, or out of scope, is answered as *not allowed*. A second decision is `already_handled`, so nothing is written twice.</li></ul> |
| What the employee proposes | profile «إضافة» on education, courses, experience, family; «تغيير الصورة» on the photo | <ul><li>A **new** entry or a new photo.</li><li>The owner alone sees «بانتظار موافقة الموارد البشرية» and the last refusal's reason.</li><li>Family shows for the owner even when empty.</li></ul> |
| HR's work | C3 work inbox: source `profile:{id}:approve`, filter «تعديلات الملفات» | <ul><li>HR of the employee's city (`EmployeePolicy::update`).</li><li>The row shows **every value that will be written**, plus the file through the private download route (owner or HR).</li><li>Approve, or reject with a required reason (a real `<form>`).</li><li>Also counted on the home page's «صندوق المهام».</li></ul> |
| Employee told | `ProfileChangeDecided` (database notification) | Approved, or not approved with the reason; opens *My profile*. |
| Audit | `ProfileChangeRequest` activity log | Who proposed, the status, who decided. The proposed values are **not** copied into the log (family details). |

**Tests:** `tests/Feature/ProfileChangeRequestsTest.php`, 7 tests:
- Waits for HR, and a colleague or HR of another city sees nothing.
- Approval writes **once**, copies the file and notifies.
- Rejection needs a reason, writes nothing, and the employee reads it.
- A newer proposal replaces the open one.
- A photo becomes the avatar with no other field touched.
- Only the active owner proposes; HR of another city is refused.
- Invalid entries are reported on their fields.

**Not in this version:**
- **Changing or deleting an existing** education/course/experience/family entry. The employee proposes new entries; corrections to existing ones still go through HR on the edit page.
- Moving the list sections and personal information into profile dialogs for HR (they stay on `employees.edit`).
- Per-section completeness lines (contract §5).

## 5. Browser checks (synthetic copy `hc-hr-gb12`, Arabic, `manager.alp` on their own profile)

| Check | Result |
|---|---|
| Photo | `src=/ar/employee-photo/34?v=…` (authorized route) |
| Sections and Edit | Timeline, work contact, personal contact, skills. Edit only on **personal contact** and **skills**, not work contact. |
| Edit skills | The dialog «تعديل المهارات والاهتمامات» opens. Saving gives «تم الحفظ.». After reload: «قيادة الفريق», «الإسعافات الأولية». |
| Timeline | «الانضمام · الرئيس التنفيذي · عقد تطوّع» (after fixing the label bug) |
| Phone 375×812 | No sideways scroll; Edit buttons 44 px and visible on the collapsed sections. |

**Approval flow, browser** (copy migrated with the new table):

| Who | Check | Result |
|---|---|---|
| `manager.alp` (own profile) | «إضافة» on Education → «جامعة حلب», «فيزياء», degree, status → «إرسال إلى الموارد البشرية» | «أُرسل إلى الموارد البشرية للموافقة.»; after reload «بانتظار موافقة الموارد البشرية: التعليم» |
| `hr.alp` | Inbox | New filter «تعديلات الملفات 1»; the row shows every proposed value |
| `hr.alp` | «موافقة» | «تمت الموافقة، وأصبح التعديل ظاهرًا في ملف الموظف.»; row gone. Database: request approved, one education row, one notification. |

**Not checked in the browser:** English (G-B-12), HR editing work contact, the stale-save message, a rejection, and the photo proposal. All are covered by the tests.
