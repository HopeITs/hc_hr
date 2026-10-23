# A2-D · Directory visibility and public digital-ID policy (proposed)

Status: **approved 2026-10-20** by the HR product owner and technical lead (§9). Public card contents stay as DEC-02 (unchanged), with the accepted card-link risks in §9 Q6.
Decision sheet to send (EN/AR, one page): [a2-d-decision-sheet.md](a2-d-decision-sheet.md).
Date: 2026-10-19. Task: A2-D in [ux-prompts-v3.md](ux-prompts-v3.md) (R-24). Owner role: HR product owner + security reviewer. Reviewer: HR product owner + technical lead.
Consumers: **A2** (implementation), C3-D, C5-D. Also read by D1-D, C2 and X1-I (§10).

This is a decision document. **No application code, route, schema or data was changed.** Every name introduced below for a future ability, column or screen is **PROPOSED** until A2 implements it.

---

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision inspected | `hr-ux-by-rafi` @ `43a4d441` (= `main` `6b96806d` + the X2 commit; X2 touched only `docs/ux/`, `tests/Usability/` and `.claude/launch.json`). No app code differs from the A1/X1 baseline. |
| P0 | [start-state.md](start-state.md): delivered, review sign-off pending. |
| A1 | [test-baseline.md](test-baseline.md): delivered, AC evidence present, review sign-off pending. Its failure register routes failures **#1–#5** here (§7). |
| Decisions | [decisions.md](decisions.md) records DEC-01 and DEC-02 as settled with HR on 2026-10-16 (quoted in §4 and §5). They answer the main questions but not every detail. The gaps are named decisions in §6. |
| Synthetic evidence | The X2 session copy ([x2/test-environment.md](x2/test-environment.md)): SQLite outside the repository, `*@example.test` personas. No real employee record was read or changed. |

**Why A2-D proceeded although A1/P0 are unreviewed:** both delivered their outputs with AC evidence, nothing in them contradicts this task, and A2-D changes no code. **Consequence:** A2-D cannot be signed off before A1 is.

## 2. Current behaviour (traced in source, `43a4d441`)

### 2.1 In-app surfaces that show colleagues

| Surface | Who can open it | What it shows | Check that applies today |
|---|---|---|---|
| Org chart + **profile drawer** (`Position\OrgChart`, `Position\ProfileDrawer`) | Every role with `positions.view`, which includes `employee` and `manager` (`config/access-control.php`) | Photo, name, position title, board-member badge, program, department, center, city, **work phone**, **work email**, manager (name, photo), direct reports, link to the full profile | **None.** Both `Gate::denies('view', …)` checks in `ProfileDrawer` are commented out (§3). Position lookups are city-scoped for HR roles only, so an HR officer sees a thinner drawer for other cities. `openProfile()` with an unknown id dereferences `null` (a 500 error). |
| **Directory** list + CSV export (`Position\Directory`) | Same as above; CSV needs `positions.export` | Name, position, city, work phone, work email; filters by city/program/center/job level. The **default status filter is empty**, so **former (inactive) employees are listed too**. | None on rows; export permission only |
| **Profile page** `/employees/{username}/show` (`Employee\EmployeeProfile`) | **Any signed-in user, any employee, any city.** The route has no permission middleware. | Header and work contact for everyone. **Career history** (contracts without pay), **education, experience, skills, courses** for every role except `employee` (`canViewRecords`), in any city. **Private** (national ID, birthday, personal contact, family) only to self or in-scope HR. **Pay** only to in-scope HR with `canViewSalary()`. Badges via `Badge::scopeVisibleTo`. | Section flags inside the component |
| Internal photo `employee-photo/{employee}` (`EmployeeAvatarController::internal`) | `viewAvatar`: self, or the employee is in the viewer's active city. Roles without a city scope pass. | Photo | City-scoped for HR roles, so an Aleppo HR officer gets 403 for other cities' photos (X1: 134 broken photos on that officer's dashboard team card) |
| Dashboard *Contact The Team* card | Every signed-in user | Name, photo, position, quick contact; a search box and *View Profile* links | Photo through `viewAvatar` (above) |

`EmployeePolicy::view` = self, **or** `employees.view` (HR, general-manager, super-admin) **and** the employee is in the viewer's active city. `activeCityIds()` is `null` (no city limit) for every role except the regional `HR-<city>` roles; `HR-SYR` is nationwide.

### 2.2 Public digital-ID card

| Item | Today | Evidence |
|---|---|---|
| Route | `GET /ID/{username}` → `ShowDigitalId`, throttle 30/min. The route's constraint is written for a **`token`** parameter, so it matches nothing and constrains nothing. | `routes/web.php:33-36` |
| Lookup | `username` **and** `is_active` **and** `digital_id_enabled`; otherwise 404 | `ShowDigitalId::mount()` |
| Guessability | The username is the local part of the work email (`<username>@hcsyria.org`, `PersonalInfoForm::buildWorkEmail`). The work email is shown in the directory, the drawer, the profile and on the card itself. **Any card URL can be derived from a colleague's email.** | |
| Token | Every employee gets a 64-character `digital_id_token` on creation (`Employee::booted`). **No route reads it any more.** `Employee::digitalIdUrl()` still builds `route('digital-id.show', ['token' => …])`, which no longer matches the route's `{username}` parameter. | `Employee.php:114-117, 524-527` |
| History | 2026-06-23 `/ID/{username}` (original) → 2026-07-31 `db77b9f1` changed it to `/ID/{token}` → 2026-09-23 `af2bba8b` changed it back to `/ID/{username}` ("fix digital id to take usename…"). The likely reason is that cards printed with username QR codes stopped working. | `git log -G"'/ID/\{" -- routes/web.php` |
| QR codes | **Not generated in the app** (no QR library in `composer.json`/`package.json`, no QR view). Cards are produced outside it and encode the URL that was current when they were printed. | |
| Disable / rotate / enable | `DigitalIdManager` exists and logs to the `security` log without the token value. **Only `disable()` has a caller**: `EmployeeObserver` calls it when an employee becomes inactive. **There is no screen** for HR to disable a lost card or re-issue one. **`rotate()` has no effect on a printed card** because the URL does not contain the token. | `app/Services/DigitalIdManager.php`, `app/Observers/EmployeeObserver.php:31` |
| Not valid | A disabled or inactive card returns a plain **404**. DEC-02 asks for a "card is not valid" page. | |
| Contents | Name (EN), photo (short-lived signed URL), position title, center, city, work phone, Telegram (work), LinkedIn, city emergency number (the first active HR-department employee's work phone in that city) | `ShowDigitalId::render()` |
| Indexing | `X-Robots-Tag: noindex, nofollow, noarchive, noimageindex` on `digital-id.*` | `SecurityHeaders` |

## 3. Why the profile-drawer checks were disabled (task step 1)

- **Commit:** `21e73581` (2026-09-17, "fix show profile from orgn chart") commented out both checks in `ProfileDrawer`: the deny on `openProfile()`, and the re-check on every render that guards against a role switch.
- **Cause:** the drawer used **`EmployeePolicy::view`**, which is the **HR record** ability (`employees.view` + city). The org chart is open to **every** role. So the check refused every click by an employee or manager, and every other-city click by a regional HR officer. The commit removed the check instead of adding a narrower one.
- **Reproduced on synthetic data** (X2 session copy, the app's own gates, 2026-10-19):

| Persona (role) | Same-city colleague `view` / `viewAvatar` | Other-city colleague | Same-city former employee |
|---|---|---|---|
| staff.alp (`employee`) | **N** / Y | **N** / Y | N / Y |
| manager.alp (`manager`) | **N** / Y | **N** / Y | N / Y |
| hr.alp (`HR-ALP`) | Y / Y | **N** / **N** | Y / Y |
| hr.hms (`HR-HMS`) | N / N | Y / Y | N / N |

With the checks enabled, the legitimate org-chart use case (a colleague looks up anyone's role, manager and work contact) **fails for every non-HR user**. That is the "original legitimate use case" the task asks to preserve. DEC-01 approves it explicitly.

## 4. Proposed in-app visibility policy (task step 2)

### 4.1 HR's decision (DEC-01, 2026-10-16)

> In-app directory (non-HR users): visible across **all governorates** (name, photo, position, work contact).

### 4.2 Information tiers

| Tier | Fields | Basis |
|---|---|---|
| **D: Directory** | Photo; name (AR/EN); position title; program, department, center, city of the current position; work email; work phone; work Telegram/WhatsApp; board-member badge; manager and direct reports **as directory entries** (the same tier, recursively) | DEC-01 names name, photo, position, work contact. Program/department/center/city and the reporting line are what the org chart is made of, so they are proposed as part of "position". **Confirm: A2D-Q1.** |
| **R: Colleague records** | Career history (contract periods, contract type, position, weekly hours: **never pay**), education, experience, skills/interests, courses | Not covered by DEC-01. Today open to every non-`employee` role in every city. **Decide: A2D-Q2.** |
| **P: Private** | National ID, birthday, personal email/phone, family/relatives, private documents (`attachments`) | Unchanged: self, or HR for the employee's city (`EmployeePolicy::viewPrivateDocuments`, `EmployeeProfile::$canViewPrivate`) |
| **F: Financial** | Salary, currency, pay tiers, badge salary figures | Unchanged: HR in scope **and** `User::canViewSalary()` (super-admin blind). Never sent to other roles in HTML, Livewire payloads, search, events, timeline, notifications or PDF (SHARED RULES). |
| **B: Badges** | Badges, without pay unless F | Unchanged: `Badge::scopeVisibleTo` |

### 4.3 Field and action matrix (proposed)

"Own city" means the employee's `city_id` is in the viewer's `activeCityIds()`. Roles without a city scope (everything except `HR-<city>`) have no city boundary.

| Viewer's active role | Self | Same-city colleague | Other-city colleague | Former (inactive) employee |
|---|---|---|---|---|
| `employee` | D + P (own); R is hidden from the `employee` role today, even on its own profile (A2D-Q2) | D | D | none (A2D-Q3) |
| `manager` | D + R + P | D + R for **own reports** (A2D-Q2); D for others | D (+ R for own reports, any city) | none (A2D-Q3) |
| `general-manager` | D + R + P | D + R | D + R | D + R (A2D-Q3) |
| `HR-<city>` (regional) | D + R + P | **D + R + P + F** (+ edit) | **D only** (A2D-Q4) | own city: D + R + P + F; other city: none |
| `HR-SYR` (nationwide) | D + R + P | D + R + P + F (+ edit) | D + R + P + F (+ edit) | D + R + P + F |
| `super-admin` | as HR-SYR **without F** | as HR-SYR without F | as HR-SYR without F | as HR-SYR without F |

**Actions:**

| Action | Allowed to |
|---|---|
| Open the profile drawer / Directory row | anyone whose matrix cell is at least **D** |
| Open the profile page | same as the drawer; the page then shows only the tiers in the viewer's cell |
| Export the Directory CSV | holders of `positions.export`; **Directory-tier columns only**, and the same row set the viewer can see in the list |
| Edit, deactivate, documents | unchanged: `EmployeePolicy::update/delete/viewPrivateDocuments` (HR in city scope) |
| Disable or re-issue a digital-ID card | **A2D-Q5** (proposed: HR for the employee's city, HR-SYR, super-admin) |

### 4.4 Deny behaviour and record existence

| Situation | Response (proposed) |
|---|---|
| The id or username does not exist | **Same response as the denied cases below**: no data, no difference in status, timing class or message. |
| The record exists but the viewer's cell is empty (e.g. a former employee for a non-HR viewer under A2D-Q3) | Drawer: does not open, generic "not available" toast, Livewire not-found (404). Profile page: 404. **No "restricted" placeholder**, because the existence of a former employee's record is itself not shown to non-HR staff. |
| The viewer's cell is D only | Drawer and profile show **only the D fields**; higher tiers are **not loaded or sent** (not hidden in the browser). |
| Active role switched while a drawer is open | The next render re-checks and drops back to the new cell, or closes (the re-check on render that `21e73581` commented out must come back). |
| Unknown or malformed id in `openProfile()` | Handled like "does not exist" (today: a 500 error from a `null` dereference). |
| Directory and CSV | Never contain rows outside the viewer's row set (A2D-Q3), and never fields above D. |

**Existence policy (task step 2):** for **active** staff, existence is **not** confidential. DEC-01 makes them visible to all staff in all cities. For **former** staff, existence is withheld from non-HR roles (A2D-Q3, recommended). Private events (contracts, disciplinary notes, leave reasons) follow D1-D/C5-D; this document adds no event visibility.

## 5. Public digital-ID card policy (task steps 3–4)

### 5.1 HR's decision (DEC-02, 2026-10-16)

> Public QR card contents **unchanged**: name, photo, position, center, city, phone, Telegram, LinkedIn, emergency number. Card of a departed volunteer / lost card: shows only "card is not valid", with no personal information. Re-issued QR (replacement card): the old printed card stops working **immediately**.

### 5.2 Options compared

| | **A. Token only** (`/ID/{token}`, username URLs return "not valid") | **B. Public minimal card by username** (today) | **C. Token cards + per-card legacy username link, ended on re-issue** |
|---|---|---|---|
| Can a stranger load a card without holding it? | No (64-character random token) | **Yes**: derive the username from any work email | Only for cards still on the legacy username link; never once re-issued |
| "Old card stops working **immediately** on re-issue" (DEC-02) | Yes (rotate) | **Impossible**: the replacement card has the same username URL | Yes: re-issue ends the legacy link and rotates the token |
| "Lost card shows not valid" (DEC-02) | Yes (disable or rotate) | Only by disabling the person's card entirely, including the replacement | Yes |
| Cards already printed with username QR codes | **All stop working at once**; everyone needs a reprint before the switch | Keep working | Keep working until that person's card is re-issued, or until the sunset date (A2D-Q6) |
| Guessable-URL risk | none | permanent | temporary, shrinking as cards are re-issued; must be **accepted** by the security reviewer until the sunset date |
| Work | route + not-valid page + HR screen | none (but DEC-02 cannot be met) | route + legacy flag + not-valid page + HR screen + reprint process |

**Rejected outright:** an **unauthenticated username → token redirect**, i.e. keep `/ID/{username}` and redirect to `/ID/{token}`. It hands the token to anyone who knows the username, so the token protects nothing (task step 4). The legacy link in option C **renders** the card; it never reveals or redirects to the token.

### 5.3 Recommendation: **option C**, as specified below

1. **Token URL is canonical.** `GET /ID/{token}` with `token` constrained to `[A-Za-z0-9]{64}`. All new and re-issued cards encode this URL. `Employee::digitalIdUrl()` already builds it.
2. **Legacy username link, per card.** `GET /ID/{username}` renders the card **only if** the employee is active, the card is enabled, **and** the card is still a legacy card. Proposed column: `digital_id_legacy_link_allowed`, default **true** for existing employees and **false** for employees created after rollout. It **renders** the card. It never redirects to and never prints the token.
3. **Re-issue** (= `DigitalIdManager::rotate()` + legacy flag off) makes the old printed card, token **or** username, show "not valid" **immediately**.
4. **Lost card:** HR re-issues (3). **Departed volunteer:** the existing `EmployeeObserver` → `disable()` path, so all of that person's URLs show "not valid". A card that may come back can be **disabled** and later **enabled**.
5. **"Card is not valid" page:** one page, in **Arabic and English**, with no name, photo, city or other personal data. It is returned for unknown tokens, unknown usernames, disabled cards, inactive employees, ended legacy links and malformed input, with the **same status code** (404 recommended) for all, so the page cannot be used to test whether a username exists. The signed photo route keeps its own checks.
6. **Expiry:** no time-based expiry of the token (cards are printed). Instead: rotate on loss, re-issue or departure, plus the **legacy sunset date** (A2D-Q6), after which every username link shows "not valid".
7. **Logging:** keep `security` log entries for rotate/disable/enable/legacy-off with actor and employee id, **never the token**. Card **views** are not logged per visitor (no personal data about who scanned a card). The existing throttle stays.
8. **Who may manage cards:** A2D-Q5.
9. **Contents** unchanged (DEC-02). The emergency number's source is A2D-Q7.
10. **Indexing:** keep `noindex` headers on every card and not-valid response.

**Compatibility and migration (for A2, not executed here):** an additive column with a backfill to `true` for existing rows. No token is shown to anyone during the migration. Reprints use a QR code produced from `digitalIdUrl()` by an authenticated HR export (proposed), never by a public endpoint. Rollback: turning the legacy flag back on for a re-issued card **must not** be used as a rollback shortcut, because it would revive a card that was reported lost (SHARED RULES: never restore revoked tokens).

## 6. Named open decisions (AC-A2-D-03)

Implementation of each affected part stays **conditional** until the decision is recorded in §9.

| ID | Question | Recommendation | Blocks |
|---|---|---|---|
| **A2D-Q1** | Does the Directory tier include program, department, center, city, board badge and the reporting line (manager/direct reports), in addition to DEC-01's "name, photo, position, work contact"? | **Yes.** These are the org chart's content, already shown to all staff today, and none is private. | Drawer/Directory field list |
| **A2D-Q2** | Who sees the **colleague records** tier (career history without pay, education, experience, skills, courses)? Today: every non-`employee` role, any city. | The manager's **own direct/technical reports**; general-manager; HR in city scope; HR-SYR. **Not** other managers across cities. Also decide whether an `employee` may see their **own** records (hidden today by `EmployeeProfile::$canViewRecords`); recommended: yes, own only. | Profile page sections |
| **A2D-Q3** | Do **former** (inactive) employees appear in the Directory, org chart and drawer for non-HR roles? Today the Directory lists them by default. | **No** for `employee` and `manager`; yes for general-manager and HR (own city / nationwide). Their existence is withheld (§4.4). | Directory, drawer, profile |
| **A2D-Q4** | Does a **regional HR officer** see other cities' staff at the **Directory** tier, like every other colleague? DEC-01 speaks of non-HR users only. | **Yes, Directory tier only.** It would be odd for HR to see less of the directory than an employee. It also fixes X1's 134 broken photos (`viewAvatar` follows the Directory rule). HR data (R/P/F, edits) stays city-scoped. | Avatars, drawer, dashboard team card |
| **A2D-Q5** | Who may disable, enable and re-issue digital-ID cards? May an employee report their own card lost? | HR for the employee's city, HR-SYR and super-admin. Self-report creates a request to HR rather than acting directly. | Card management screen |
| **A2D-Q6** | **Legacy username link sunset date**, the reprint plan, and the security reviewer's acceptance of the guessable-URL risk until then. | A date that leaves time for one reprint round (e.g. the next ID-card printing cycle). Re-issue anyone whose card is reported lost first. | Option C rollout |
| **A2D-Q7** | Source of the card's **emergency number**: "first active HR-department employee's work phone in the city" today. | A configured per-city HR hotline, with the current rule as the fallback. Optional: DEC-02 says contents are unchanged, so this is a source question only. | Card render (minor) |

**DEC-02 option choice:** this document recommends **option C** (§5.3). The HR product owner + security reviewer must record A, B or C in §9. **Option B cannot satisfy DEC-02's "stops immediately"** and is listed only for completeness.

## 7. Effect on the failures A1 routed here

> **Superseded for #2–#4 (2026-10-20):** the §9 decision (readable card link, no token) replaces the token/legacy design of §5.3 and the rows below. What A2 actually implemented and tested: [a2-implementation.md](a2-implementation.md).

A1 found these failures; it did not change them. The **intent** of each stays; the **assertion** must follow the signed policy.

| A1 # | Test | Today | Under this policy | For A2 |
|---|---|---|---|---|
| 1 | `AuthorizationRegressionTest::test_the_profile_drawer_refuses_an_employee_from_another_city` (expects 403 for HR-ALP opening a Homs employee) | 200, full drawer | DEC-01 + A2D-Q4: HR-ALP **may** see a Homs colleague at the **Directory** tier | **Expectation changes** (documented): the drawer opens with Directory fields only; no R/P/F fields in HTML or Livewire payload. Add: former employee for non-HR → not found; unknown id → not found (no 500); role switch → re-check. |
| 2 | `SecurityAuthorizationTest::test_digital_id_uses_an_unguessable_token_and_hides_inactive_employees` | `/ID/{username}` 200; token route missing | Option C: a **legacy** card still resolves by username until re-issue or sunset; a **non-legacy** card does not | Expectation split: username 404 / "not valid" when the legacy flag is off; token route 200 when active; inactive → not valid on both. |
| 3 | `PrivateAvatarAccessTest::test_a_disabled_digital_id_stops_resolving` | token route missing | Kept as written (token route) | Passes once the token route exists |
| 4 | `PrivateAvatarAccessTest::test_rotating_the_token_invalidates_the_printed_card` | token route missing | Kept as written; also assert the legacy username link is ended | Passes once the token route exists |
| 5 | `LivewireMutationCoverageTest` (6 unclassified actions) | — | Not a policy question | A2 classifies each action individually (task A2 step 3). `position.profile-drawer::openProfile` is today listed as a route-gated read; under this policy it is a **record read with its own check**. |

## 8. Proposed contract for A2 (all names PROPOSED)

- **An ability for the Directory tier** (e.g. `EmployeePolicy::viewDirectory`). Active employee and any signed-in user, per §4.3, including A2D-Q3. **`viewAvatar` follows it.** `view` stays the HR record ability.
- **One projection** for the Directory tier used by the drawer, the Directory list and CSV, the dashboard team card and the profile header, so that no surface loads more than D for a D-only viewer.
- `ProfileDrawer`: authorize `viewDirectory` on `openProfile()` **and** on every render. Uniform not-found; no `null` dereference. Position/manager/reports lookups follow the same projection.
- `EmployeeProfile`: authorize `viewDirectory` in `mount()` (404 otherwise); `canViewRecords` per A2D-Q2; `canViewPrivate` and `canViewFinancial` unchanged.
- `Directory`: default row set per A2D-Q3; export columns limited to D.
- Card: token route, legacy flag, not-valid page (EN/AR), HR card-management actions per A2D-Q5, each authorized on the server and logged without the token.
- **Tests (A2):** same/other city, own record, active/inactive, role switch, card disable/enable, rotation, legacy-link end, unknown id, payload inspection for absent R/P/F fields. Synthetic fixtures only (`CreatesHrFixtures`).

## 9. Sign-off record (AC-A2-D-01, AC-A2-D-02)

| Item | Decision | Decided by (role) | Date |
|---|---|---|---|
| Field/action matrix §4.3 and deny behaviour §4.4 | **Approved**, with two adjustments from the answers: (1) `employee` sees R on their own profile (Q2); (2) `manager` gets R for **direct reports only**, since the technical-manager role no longer exists (DEC-05 revision). | HR product owner + technical lead | 2026-10-20 (in session) |
| A2D-Q1 Directory fields | **A: yes** (program, department, center, city, manager/reports visible to all staff) | HR product owner | 2026-10-20 (in session) |
| A2D-Q2 Colleague records tier | **A:** manager for own team, general manager, city HR, national HR; employee sees own | HR product owner | 2026-10-20 (in session) |
| A2D-Q3 Former employees | **A:** hidden from staff and managers; visible to general manager and HR | HR product owner + security reviewer | 2026-10-20 (in session) |
| A2D-Q4 Regional HR at Directory tier for other cities | **A: yes**, directory tier only | HR product owner + security reviewer | 2026-10-20 (in session) |
| A2D-Q5 Card management roles | **A:** city HR, national HR, super-admin; staff report a loss, HR acts | HR product owner | 2026-10-20 (in session) |
| DEC-02 option (A / B / C) | **A: all cards switch to secure links at once.** Not the recommended C. Everyone gets a reprinted card first; old username links stop on switch day. No legacy period after that day; the only exposure is today's links until switch day (see Q6). | HR product owner + security reviewer | 2026-10-20 (in session) |
| A2D-Q6 Card URL (replaces legacy sunset) | No cards printed yet, so no legacy links or switch day. **Decided format:** `ID/[city 3 digits][program 3 digits][employee_id]-[index]`, no secret part. Index `0` = cardboard, temporary: it stops when the plastic card is issued (lost before that: not revoked). Plastic starts at `1`; a lost plastic card is re-issued with the next index (`-2`, `-3`…) and **only the latest plastic index is valid**; older ones show "card is not valid". **Risks accepted by HR, against the recommendation:** (1) the link is enumerable, so anyone on the internet can collect every volunteer's public card (name, photo, position, center, city, phone, Telegram, LinkedIn, emergency number); (2) a cardboard card lost before the plastic one is issued cannot be stopped (a narrow exception to DEC-02). Purpose of the card: show whether the volunteer is active, and the emergency number. A move to another governorate or a new position prints a new card and the old link stops. | HR product owner + technical lead | 2026-10-20 (in session) |
| A2D-Q7 Emergency number source | **A:** fixed HR hotline per city, set by HR | HR product owner | 2026-10-20 (in session) |

## 10. Acceptance criteria and handoff

| AC | Status | Evidence |
|---|---|---|
| **AC-A2-D-01** HR/security record a chosen field/action matrix and deny behaviour for each role/city. | **Met** (§9, 2026-10-20) | §4.3 (role × same/other city × former), actions, §4.4 deny behaviour. Built on DEC-01. HR + security record their choice in §9. |
| **AC-A2-D-02** Existing card compatibility is decided without pretending a guessable redirect preserves secrecy. | **Met** (2026-10-20): no legacy cards; URL format and accepted risks recorded in §9 Q6 | §5.2 (A/B/C, redirect rejected and why), §5.3 (legacy link renders, never redirects; re-issue ends it immediately), A2D-Q6 sunset. |
| **AC-A2-D-03** Open questions are named decisions; implementation stays conditional. | **Met** | §6 A2D-Q1…Q7, each with the part it blocks; §9 record. |

Verification performed: source trace at `43a4d441` (files cited per row), `git log` for the drawer and the card route, and the app's own gates run against synthetic personas on the X2 session copy (§3). No application test is claimed from this review. **Formal review: done 2026-10-20 (HR + technical lead).** A2-D is marked done.

**Handoff:**

| Consumer | Takes from this document |
|---|---|
| **A2** | §4.3–§4.4 matrix and deny rules, §5.3 card spec, §7 test expectations, §8 contract. Implement only the parts whose §9 decisions are recorded; leave the rest conditional. |
| **C3-D** | Approval screens show requesters at the Directory tier at most, unless the approver's own scope grants more (§4.3). Rejection reasons and leave reasons are **not** Directory fields. |
| **C5-D** | Team-calendar names and photos follow the Directory tier (A2D-Q4 for cross-city HR); absence type visibility is C5-D's own decision (DEC-06), not widened here. |
| **D1-D** | The unified profile's sections map to tiers D/R/P/F/B; A2D-Q2 decides R. |
| **C2** | Search results across cities return Directory fields only, from the same projection (§8). |
| **X1-I** | The 134 broken team photos (X1 §5) are a policy result; they resolve when `viewAvatar` follows A2D-Q4, not through a performance change. |
