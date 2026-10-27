# C0 · Task structure and interaction contract

Version: **interaction/1.4** · Date: 2026-10-26, HR answers 2026-10-26 (final, see §10) · Task: C0 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-06 to R-10)
Owner role: UX designer + HR product owner · Reviewer: QA engineer + technical lead
Consumers: **C1** (menu), **C2** (search and *New*), **C3** (work inbox), **C4** (home page), **D1-D** (profile)
Status: **Done with a recorded limitation (2026-10-26):** HR decisions recorded (§10), prototype delivered (§9), pilot run recorded; the check with real users is moved to X2-R (§11). Formal review pending.

This document changes no code, route, permission or data. It fixes the names, the place of every screen, and how people enter and leave each task, so that C1–C4 build one structure instead of four.

## 1. Starting state and prerequisites

| Item | Value |
|---|---|
| Revision inspected | `hr-ux-by-rafi` @ `1b939d81` (G-B and its follow-ups G-B-1 to G-B-8 committed) |
| G-B | [g-b-gate.md](g-b-gate.md): PASS WITH CONDITIONS; every G-B item owned before G-C is fixed (§9 there). Formal review still pending. |
| Decisions in force | [decisions.md](decisions.md): DEC-01/02 (directory), DEC-05 + revision (approvals), DEC-06 (team calendar). C3-D ([approval-source-contract.md](approval-source-contract.md)) and C5-D ([team-coverage-contract.md](team-coverage-contract.md)) are settled with HR. |
| User research | **None yet** ([usability-baseline.md](usability-baseline.md) §6). The problems in §2 are source-derived; §11 holds the scenario checks. |

## 2. What exists today (traced in source)

### 2.1 Sidebar (`livewire/layout/sidebar.blade.php`)
Grouped by **kind of data**, not by whose work it is. Visibility per item comes from active-role permissions (`@activepermission`), which is correct and stays.

| Group today (EN / AR) | Items (permission) |
|---|---|
| Main / الرئيسية | Dashboard (all) · Statistics: employees, contracts (`statistics.view`) · Organization chart (`positions.view`) |
| Management / الإدارة | Project basics: cities, contract types, leave types, annual leave rules, termination reasons, checklist items, job levels, departments, course categories (`project-basics.view`) · Program management: programs, positions, centers · Centers alone for others · Employee management: employees, default deliverables, contracts |
| Time & Leave / الإجازات | Leaves management: all leaves (`leaves.view-all`), leave balance (`leaves.manage-balances`) · My leaves (`leaves.view-own`) |
| Files & Tasks / الملفات والمهام | Archived files: my folders, shared folders · Checklist items: pending delivers, pending returns, done requests (deliver-responsible only) · My items |
| More / أخرى | News management · All badges · Activity log · Access management |

Problems (source-derived, to be confirmed in §11):
1. **An employee's own things are spread over four groups** (Dashboard in Main, My leaves in Time & Leave, My items and files in Files & Tasks).
2. **A manager has no place for "my team"**: team leaves sit under *Leaves management*, team contracts under *Employee management*, and **nothing shows what waits for their decision** except the notification bell.
3. Daily HR work (requests, contracts ending, incomplete employees) and rare setup (contract types, job levels) have the same weight.

### 2.2 Header (`livewire/layout/header.blade.php`)
Language (EN|AR) · tour · user guide, main website, feedback form (external links; inside the avatar menu on phones) · notifications · avatar menu (my profile, my account, switch role, log out). **No search and no *New*.**

### 2.3 Dashboard (`livewire/dashboard/index.blade.php`)
The **same 11 cards for every role**: my leaves, notes, team members, news, calendar, upcoming leaves, today's events, upcoming events, pending delivers, checklist items, my badges. Nothing role-specific: a manager's pending decisions and HR's open work are not on it. **C0-Q5: a role section goes on top; these cards stay below it.**

## 3. Personas (roles as they exist)

| Persona | Active role(s) | Scope |
|---|---|---|
| **Employee** (volunteer) | `employee` | Self; directory of all governorates (DEC-01) |
| **Manager** | `manager` | Self + direct reports (DEC-05 rev: direct manager only) |
| **General manager** | `general-manager` | Organisation-wide, read-mostly |
| **Regional HR** | `HR-ALP`, `HR-HMS` (HMS + RHO), `HR-DMS`, `HR-LAT`, `HR-TAR` | Own city or cities |
| **Nationwide HR** | `HR-SYR` | All cities; administrator role |
| **Administrator** | `super-admin` | Everything except pay figures (salary-blind) |

A person with several roles sees the structure of the **active** role; switching role re-renders it (unchanged).

## 4. The menu: inbox + four areas (decided, C0-Q1, Q2, Q4)

Grouped by **whose work it is**. An area, sub-heading or item appears only if the active role holds the permission it already needs today; the menu never shows a page the role cannot open. **One deliberate exception (C0-Q4, answer 2): some read-only reference pages are left out of the `employee` and `manager` menus** (see the *Organisation* row). Menu visibility is never the permission: every page and action keeps its own server check, and a page left out of the menu still opens by URL.

| Place (EN / AR) | Purpose | Items → route name (permission, unchanged) |
|---|---|---|
| **Work inbox / صندوق المهام** (top item, with a count; C0-Q2) | Everything waiting on *me* | C3 inbox (new route `inbox`, C3). Shown only when the active role has at least one inbox source (C3-D §3). Until C3 ships, C1 links it to *All leaves* on the pending tab. |
| **My space / مساحتي** (C0-Q1) | My own work | Home `dashboard` · My leaves `leaves.index` · My items `checklist-items.index` · My files `my-folders.index` · Shared files `shared-folders.index` |
| ↳ **Organisation / المنظمة** (C0-Q4; **built by C1 as the last area of the menu**, see [c1-implementation.md](c1-implementation.md) §2) | Finding colleagues; reference pages for roles that read them | **Everyone:** Directory and org chart `org.chart`. **Manager:** also Centers `centers.index` and Programs `programs.index` (for the team). **General manager and other read-only roles:** also Positions and Project basics. **Left out of the `employee` menu:** centers, programs, positions, project basics; **of the `manager` menu:** positions, project basics. Closed by URL too since G-B-13 (answer 2), from the same list `access-control.reference_pages_hidden_from`; the read permission stays, because `positions.view` also opens the org chart. |
| **My team / فريقي** | People I manage | Team leaves `leaves.all` (manager scope) · Team calendar (C5) · Team contracts `contracts.index` (own reports, read-only) · Items to hand out / take back `checklist-items.pending-delivers`, `checklist-items.pending-returns` |
| **HR operations / عمليات الموارد البشرية** | Daily HR work in my cities | Employees `employees.index` · Contracts `contracts.index` · Leave requests `leaves.all` · Leave balances `leaves.balance` · Default deliverables `default-deliverables.index` · Completed checklist requests `checklist-items.done-requests` · Badges `badges.index` · News `news.index` · Statistics `statistics.general`, `statistics.contract` |
| **Settings / الإعدادات** | Setup and administration | Access management `access-management.index` · Activity log `activity-log.index` · and, for roles that **edit** them, the project basics, positions, programs and centers |

**One page, one place per role.** A page that could sit in two places is shown once, in the first place that fits the active role: the *Organisation* sub-heading holds the reference pages for roles that only **read** them; roles that **edit** them (holders of the matching `create`/`update` permission) find them in *HR operations* or *Settings* instead. *Team leaves* (manager) and *Leave requests* (HR) are the same route `leaves.all`; the scope comes from the active role.

Avatar menu (unchanged): My profile `my-profile` · My account `settings.password` · Switch role · Log out.

## 5. Role × task map and first screen (AC-C0-01)

| Persona | Menu shows | Top tasks | Where each task starts |
|---|---|---|---|
| Employee | My space (+ Organisation) | Request leave · check balance and request status · return/receive items · find a colleague | *New → Leave request* or *For you now* · My leaves · My items · **Search** or Directory |
| Manager | Inbox · My space · My team | Decide team leave · see who is off · own leave | **Inbox** (count) or *For you now* · My team → Team leaves / calendar · *New → Leave request* |
| General manager | Inbox · My space · HR operations | Overview · contracts ending · decisions as a manager | Statistics · Contracts (*Ending soon* view, B4) · Inbox |
| Regional HR | Inbox · My space · HR operations (+ Settings if it edits basics) | Seen by HR and objections · contracts ending · incomplete/awaiting employees · checklist confirmations | **Inbox** · Contracts *Ending soon* · Employees *Incomplete* / *Awaiting contract* (B5) |
| Nationwide HR | as regional HR + Settings | as regional HR, all cities | as regional HR |
| Administrator | Inbox · My space · HR operations · Settings | Access · setup · activity | Settings |

**First screen (C0-Q5): a short section for the active role at the top of the home page (*For you now / لك الآن*), then the existing cards below it, unchanged.**

| Persona | Role section, in this order |
|---|---|
| Employee | 1. My leave balance + *Request leave* · 2. My open requests and their next step · 3. Items I must hand back or receive |
| Manager | 1. **Needs my decision** (count + first rows, opening the inbox) · 2. Who in my team is off today / this week · 3. My balance |
| General manager | 1. Needs my decision (only if any) · 2. Key figures + contracts ending in 30 days |
| Regional / nationwide HR | 1. **Work inbox** (seen by HR, objections, confirmations) · 2. Contracts ending in 30 days · 3. Incomplete / awaiting-contract employees (B5) |
| Administrator | 1. Work inbox (only if any) · 2. Shortcuts to Settings and Activity log (no pay) |

Rules for C4:
- The section is **one ordered block**, not new equal-weight cards next to the old ones.
- Every figure comes from the same scoped query as its page (inbox: C3-D §5; contracts: `Contract::visibleTo`; employees: the B5 views). No pay, ever (`super-admin` is salary-blind).
- A part with nothing to show collapses (for example *Needs my decision* with zero rows).
- Each part links to the page or inbox row it summarises and returns to Home (§6).

## 6. Header: search, *New*, inbox and return paths (AC-C0-02)

| Element | Contract |
|---|---|
| **Search** (C2; C0-Q6) | One box in the header, for every role. Finds **pages of the active role's menu** by their menu name in EN and AR, and **people** by name in EN or AR. A person result shows **only the directory tier** the viewer may see (A2 [access-policy.md](access-policy.md) §4.3: name, photo, position, center, city, work email/phone; all governorates, DEC-01). Opening it uses the same drawer/profile as the directory; HR's profile view keeps its city scope. **Never pay, never private fields, never former employees for roles that may not see them (A2D-Q3)**, in the results, the payload or the count. |
| **New / جديد** (C2; C0-Q3) | One button on every page. Lists only what the active role may create, by task name: **Leave request** (everyone with `leaves.create`; HR also on behalf of an employee) · **Employee** (`employees.create`) · **Contract** (`contracts.create` **and** `User::canViewSalary()`, so never the salary-blind `super-admin`) · **News item** (`news.create`). Each opens the existing create screen; the server re-checks the permission there. |
| **Work inbox / صندوق المهام** (C3; C0-Q2) | One name everywhere (menu, home section, notification links, page title). The count uses the same scoped query as the list (C3-D §5). A notification about a request opens the same inbox row / dialog. |
| **Terminology** | One word per thing in both languages, taken from the menu: page titles, *New* items, search results, breadcrumbs and the home section use the menu's name (§8). |
| **Return** | Every screen opened from the inbox, search, *New* or the home section returns to where it came from (B1 back behaviour; tables keep search and filters, B4). Closing a dialog returns focus to the control that opened it (A5). Cancelling a *New* form with entries asks first (A5-M/B2). |

## 7. Stable IDs and legacy access

- **Route names and URLs do not change.** Bookmarks, notification links, the guided tour and the user guide keep working. C1 changes only where links sit and what they are called.
- **Menu item IDs**: `nav.<area>.<item>`, e.g. `nav.inbox`, `nav.my-space.leaves`, `nav.my-team.leaves`, `nav.hr.contracts`, `nav.settings.access`. The existing `data-tour="nav.*"` hooks are mapped in C1 so the tour keeps its steps.
- **Protected legacy access**: every page stays protected by its own route middleware and policies exactly as today. The menu grants and removes no permission. The pages C0-Q4 leaves out of a menu are refused by URL too (G-B-13, answer 2), by `reference_page` route middleware reading the same list as the menu.

## 8. Glossary (EN / AR)

| Concept | EN | AR |
|---|---|---|
| Areas | My space · My team · HR operations · Settings | مساحتي · فريقي · عمليات الموارد البشرية · الإعدادات |
| Reference sub-heading | Organisation | المنظمة |
| Things waiting on me | Work inbox | صندوق المهام |
| Home role section | For you now | لك الآن |
| Create anything | New | جديد |
| Leave a person asks for | Leave request | طلب إجازة |
| Manager's answer | Decision (accept / reject) | قرار (قبول / رفض) |
| HR after the decision | Seen by HR · Objection | راجعته الموارد البشرية · اعتراض |
| Header search | Search people and pages | ابحث عن شخص أو صفحة |

## 9. Prototype

[c0/prototype.html](c0/prototype.html): a static, clickable page (no app code, synthetic names). Switch **language** (EN/AR, with RTL), **role** (the six personas of §3) and **width** (desktop / phone). It shows the menu of §4 for that role, the header with search and *New* of §6, the inbox entry with a count, and the home page: the *For you now* section of §5 on top, the existing cards below. Brand tokens (`--color-primary` #c2151c, the secondary greys) and Somar Sans (from `resources/fonts/`, by relative path: open the file from the repository folder, or the fonts fall back to Lato).

Checked in the browser (2026-10-26), Arabic and English at phone width: manager menu (inbox 3, My space + Organisation, My team); regional HR menu (inbox 5, HR operations); *New* lists *Leave request* and *News item* for a manager; search «عقو» finds *Contracts* under HR operations; the manager's home section opens the inbox; the inbox rows wrap on a phone.

## 10. Decisions (HR product owner, 2026-10-26)

| # | Question | Before | **Decision (final)** |
|---|---|---|---|
| **C0-Q1** | Names of the menu areas | Groups by kind of data (Main, Management, Time & Leave, Files & Tasks, More) | **My space / My team / HR operations / Settings** (مساحتي / فريقي / عمليات الموارد البشرية / الإعدادات) |
| **C0-Q2** | One place for what waits on me | Notifications only | **"Work inbox" (صندوق المهام) as the top menu item, with a count**, for roles that have inbox sources |
| **C0-Q3** | A *New* button | None | **Yes**: Leave request · Employee · Contract · News item, each only for roles allowed to create it |
| **C0-Q4** | Reference pages (project basics, centers, programs, positions, org chart) for employee and manager | Visible, read-only | **Answer 2: the directory and org chart for everyone; centers and programs stay for the manager (needed for the team); everything else leaves the employee and manager menus.** Menu only: the read permission is not withdrawn (§4). |
| **C0-Q5** | Role-specific first screen | Same 11 cards for all | **What matters to each role first (§5), then the existing cards below, unchanged.** |
| **C0-Q6** | Header search | None | **Pages and people for every role**, people limited to the directory tier the viewer may see |

Answer history, same day: Q5 was first "keep the home page as it is" and changed to the above; Q1 and Q3 were briefly changed to "keep the current menu" and "no *New* button", then restored; Q4 went from "keep everything visible" to answer 1, back to 4, and on 2026-10-27 (after C1–C3 were built) to answer 2; C1 and C2 were updated for it (c1-implementation.md §2). The table holds the final answers.

## 11. Scenario checks (AC-C0-03)

**Status: LIMITATION ACCEPTED (product owner, 2026-10-26). Only a pilot by the project team exists; the check with real users is moved to X2-R.** The guide and test mode below are ready for it. No participant has used the prototype yet. Recruiting and contacting staff are for the UX researcher with HR's approval (as in X2); nobody was contacted for C0.

**How to run it:**
- **Moderator guide (Arabic):** [c0/scenario-check.md](c0/scenario-check.md). It covers consent wording, steps, the tasks with their correct answers, the results sheet and what to do with the results.
- **Test mode in the prototype:** *Start user test* / «ابدأ اختبار المستخدم». The moderator enters a participant code (P1, P2 … never a name) and the persona; each task is shown above the prototype.
  - Every click inside the prototype is recorded. A task ends when the participant reaches one of its targets (below) or the moderator presses *Can't find it*.
  - *Copy results* gives tab-separated lines: code, persona, language, width, task, first click, found (Y/N), seconds, clicks, click path.
  - Results stay in the page; nothing is sent anywhere.
- **Tool check (2026-10-26, not a participant):** the moderator role ran the employee tasks with code `T0`. T1 was found with `page:myLeaves`; T2 was ended with *Can't find it* after `page:centers`; T3 was found with `focus:requests`. The copied lines were correct. **This run is not counted as user evidence.**

Script (first click on the prototype, about 10 minutes per person; 1–2 people per persona, Arabic first). Record per task: first click, success (Y/N), time, and the words the person used if they looked for another label.

| # | Persona | Task ("Where would you click to …") | Expected first click |
|---|---|---|---|
| T1 | Employee | … ask for a day off next week? | *New → Leave request*, *For you now → Request leave*, or *My space → My leaves* |
| T2 | Employee | … find the work phone of a colleague in another city? | Search, or *Organisation → Directory* |
| T3 | Employee | … see whether your manager answered your request? | *For you now* (open requests), or *My space → My leaves* |
| T4 | Manager | … answer a leave request from your team? | *Work inbox*, or *For you now → Needs my decision* |
| T5 | Manager | … see who in your team is off this week? | *For you now*, or *My team → Team leaves* (calendar once C5 ships) |
| T6 | Regional HR | … mark a decided request as seen? | *Work inbox* |
| T7 | Regional HR | … see contracts ending this month? | *For you now*, or *HR operations → Contracts* (*Ending soon*) |
| T8 | Regional HR | … add a new contract? | *New → Contract* |
| T9 | Administrator | … change what a role may do? | *Settings → Access management* |

Labels are revised only from these results; any change is recorded here with the task that caused it.

**Pilot run (2026-10-26, project team, codes `T0` and `P?`; one person ran all four personas).** It checks the tasks and the tool and gives early signals. **It is not user evidence**: the person knew the design, so it does not count towards AC-C0-03.

| Task | Persona | First click | Found | Seconds | Clicks | Path |
|---|---|---|---|---|---|---|
| T1 | Employee | `open:new` | Y | 36.9 | 6 | open:new > open:new > other > open:search > other > focus:leave |
| T2 | Employee | `open:search` | Y | 20.2 | 2 | open:search > page:directory |
| T3 | Employee | `focus:requests` | Y | 6.9 | 1 | focus:requests |
| T4 | Manager | `focus:inbox` | Y | 9.9 | 1 | focus:inbox |
| T5 | Manager | `focus:teamOff` | Y | 8.0 | 1 | focus:teamOff |
| T6 | Regional HR | `focus:inbox` | Y | 20.3 | 1 | focus:inbox |
| T7 | Regional HR | `focus:contractsEnding` | Y | 4.2 | 1 | focus:contractsEnding |
| T8 | Regional HR | `open:new` | Y | 4.2 | 2 | open:new > new:new.contract |
| T9 | Administrator | `page:access` | Y | 10.8 | 1 | page:access |

Pilot signals (to watch in the real sessions, no label changed yet):
- **9/9 found; 7 of 9 on the first click.** *For you now* was the first click in 5 tasks (T3–T7): the home section is where this person looked first.
- **T1 (request leave) was the slowest, 37 s and 6 clicks.** *New* was opened and closed without choosing *Leave request*, then search, then *For you now → Request leave*. The *New → Leave request* item was checked and responds to a click, so this is a finding, not a tool fault. Question for the real sessions: is *New* read as the place to ask for leave, or does *Request leave* need to be more visible?
- **T2:** search was tried first, then the directory: both paths work.
- **T8:** *New → Contract* was found at once.

| Real sessions | Participants | First-click success | Notes / revised label |
|---|---|---|---|
| T1–T9 | 0 | — | Pending |

## 12. Acceptance criteria and handoff

| AC | Status | Evidence |
|---|---|---|
| **AC-C0-01** Each role has an agreed task/permission map and first-screen priorities. | **Met (decided)** | §3–§5; HR decisions C0-Q1, Q2, Q4, Q5 (§10) |
| **AC-C0-02** Search/New and inbox/dashboard use consistent terminology and entry/return behaviour. | **Met (decided)** | §6–§8; C0-Q2, Q3, Q6 |
| **AC-C0-03** Evidence records what users could find and any revised labels. | **Limitation, accepted by the product owner** | §11: pilot by the project team (9/9 found, T1 slow), no real participants. Real check moved to **X2-R** with [c0/scenario-check.md](c0/scenario-check.md); no label revised yet. |

**Handoff:**
- **C1** builds §4 and §7 (menu areas, inbox entry, `nav.*` IDs, tour mapping). No permission change.
- **C2** builds search and *New* to §6 (C0-Q3, Q6).
- **C3** names its page *Work inbox / صندوق المهام* and supplies the count used by the menu and the home section.
- **C4** builds the *For you now* section of §5 on top of the existing cards (C0-Q5), after C3 (inbox) and C5 (who is off).
- **D1-D**: the profile opened from search, directory and inbox is the same page with the same tiers.
- **X2-R** runs the C0 scenario check with real participants (P1, P2 …, 1–2 per persona) using [c0/scenario-check.md](c0/scenario-check.md) and the prototype's test mode, on the built menu or the prototype. It watches T1 (*New → Leave request*) first. Any label change it causes is recorded in §11 here.
