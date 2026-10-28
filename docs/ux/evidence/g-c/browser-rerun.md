# G-C · Browser consistency re-run (corrected SHA)

Date: 2026-10-28 · App: branch `G-C` @ `4b07f3e6` · Moderator-only run, **no participants**. No timings, SEQ or SUS. No app code was changed and nothing was committed. The only repo changes are this file and one new `.claude/launch.json` entry (`hc-hr G-C re-run (synthetic SQLite copy)`, port 8403; the other entries, including the uncommitted ones, were left as they were).

First run: [browser-walkthrough.md](browser-walkthrough.md). Fixes re-checked: [g-c-gate.md](../../g-c-gate.md) §4 (G-C-1 … G-C-13) and [decisions.md](../../decisions.md) "DEC-05 revision 2".

## 1. Environment

| Item | Value |
|---|---|
| Data | **New** fresh synthetic SQLite copy `C:/laragon/tmp/hc-hr-gc2`, built from HEAD with the X1 builder + `tests/Usability/Baseline/session-states.php` (manifest built 09:36:58; same personas and request ids 64–69 as the first run). Clean snapshot `perf.clean.sqlite` taken before any run. Operational MySQL and `.env` untouched. |
| PHP / assets | `C:/laragon/bin/php/php-8.5/php.exe`. `npm run build` re-run first: `public/build` (27 Oct 16:03) was older than the last `resources/` commit (28 Oct 09:08). |
| Server | `php -S 127.0.0.1:8403` via the new launch entry, started with `preview_start`; **stopped at the end**. Port 8403 is also used by the existing "D3 feedback" entry; that server was not running. |
| Browser | Built-in Chromium pane. Desktop = pane size (~800×600; at this width the expanded sidebar overlays the content, so it was collapsed for Task D). Phone = 375×667 emulation. |
| Sessions | Session A = `127.0.0.1:8403`, session B = `localhost:8403` (separate cookies). Personas switched by the app's own logout form + sign-in. |
| Sign-in | Scripted: fixture email and password set through the DOM. |
| Input | Real clicks and keys for navigation, menus, decisions, reasons, search, the leave form and the composer. Exceptions: date inputs set directly; Select2 options chosen by keyboard (mouse still does not register, D-09); the composer's two native day selects set with `form_input`; count collection used same-origin `fetch()` of `/`, `/inbox`, `/team-calendar?view=day`, `/leaves/all`; privacy probes used `fetch()` and `Livewire...$wire.call()` on purpose. |
| DB helper | Scratchpad `db.php` boots the harness exactly like the router (`x2-home.php` + `env.php`, `perf_assert_isolated()`, and refuses unless `X2_HOME` is the re-run copy), then runs a snippet: `deciders.php` (every pending request × every user through `LeaveDecisionAuthority::basisFor`, next-step text, `WorkInbox::snapshot` and `NotificationRequestState::unreadCount` per persona), `req.php`, `notes.php`, `remind.php`. |

Personas (manifest): `staff.alp` (Hala Najjar, emp 2) · `staff.hms` (Nabil Farah, emp 162) · `manager.alp` (George Khoury, emp 34 / user 34, position 1) · `manager.hms` (Yara Atallah, emp 195 / user 124, position 10) · `hr.alp` (Lara Mansour, user 1, HR-ALP) · `hr.hms` (user 90, HR-HMS). **Employee-role manager-position holders** (role `employee` only): `synth.056` Maya Halabi (user 56, ALP, holds position 27 = staff.hms's direct manager) · `synth.207` Hadi Shami (user 136, HMS, second holder of position 1) · `synth.023` Dana Saliba (user 23, ALP, second holder of position 10).

### Synthetic state changes (copy only)

| Change | Ids | Why |
|---|---|---|
| Accepted annual leave 28–29 Oct for **both** position-1 holders (George Khoury, Hadi Shami), inserted directly | leave_requests **71, 72** | on-behalf state (every direct manager on leave today) |
| Request 68 `created_at` set to 2026-10-24 (4 days old) | 68 | reminder command |
| Hala Khoury (user 39, holder of position 5) given the `manager` role for the team-contracts probe, **restored to `employee`** afterwards | model_has_roles | G-C-8 probe (position 5 is the technical manager of positions 50, 105, 160) |
| Created or decided by the runs | 40, 65, 70, 73; contract 317 | §2, §6 |

## 2. Runs

Outcome codes per task-scripts §0.3. Clicks = moderator interactions from the dashboard to the stop point.

| # | Task | Persona | Locale | Device | Path | Outcome | End state (ids) |
|---|---|---|---|---|---|---|---|
| R1 | A · Request leave | staff.hms | AR | phone | لك الآن → طلب إجازة → type (keyboard) → dates → reason → Enter → حفظ | **S** | Request **70**: annual, day, 05-11-2026, «زيارة عائلية», pending. Next step now reads «بانتظار قرار مديرك مايا حلبي … وأُبلغ فريق الموارد البشرية في حمص» (was "HR team" in the first run). |
| R2 | C · Review (D-01 place) | Maya Halabi (employee role) | EN | desktop | Menu *Work inbox* (badge 2) → row Nabil Farah → Accept → Save | **S** | **70** accepted, `approved_by` 56, basis `manager`, balance 941 `used` 6.67 h. Badge 2→1, bell 3→2. |
| R3 | A · Request leave | staff.alp | **EN** | **phone** | For you now → Request leave → … → Enter → Save | **S** (new cell) | Request **73**: annual, day, 05-11-2026, "Family visit", pending; next step "HR team in Aleppo" (staff.alp has no direct manager: position 13 has no holder). The «1 colleague is already away» hint showed. |
| R4 | C · Review | Maya Halabi (employee role) | **AR** | **phone** | Drawer → صندوق المهام → row «جوزيف نجار» («بدأ دون قرار») → رفض → reason → Enter | **S** (new cell) | **40** rejected by 56, basis `manager`, reason stored. Inbox 1→0. |
| R5 | C′ · HR on behalf + race | hr.alp vs Hadi Shami | EN | desktop | see §5 | Completed (variant) | **65** accepted on behalf (one write) |
| R6 | D · Create a contract | hr.alp | **AR** | desktop | New → عقد → employee «نادين» (keyboard) → program → position (0/2) → contract type → start date → work days → متابعة ×2 → pay → إنشاء العقد | **S** (first D end state) | Contract **317**: emp 279, position 2, type 1, 11-11-2026 → 31-12-2026 (end auto-filled from the type), Sun–Thu 09–17, 40 h/week, **`auto_renew` = 1** (box checked by default, editable, with the "only HR in the governorate" note), salary stored encrypted and readable; position 2 `exist_emp` 0→1; 6 leave balances. Redirected to `/ar/contracts/317`. |
| R7 | B · Find an employee | hr.alp | **EN** | **phone** | Search button → "Mira Najjar" → ↓ → Enter | **S** (new cell) | Profile `synth.040` = target 40. No sideways scroll (375/375). |

**Coverage:** new cells A EN phone, B EN phone, C AR phone, D AR desktop. Still not run: A AR desktop, B AR desktop, C EN phone, D EN (desktop/phone), D AR phone, D′.

## 3. Defect re-check (first run D-01 … D-10)

| # | First run | Re-check (same place, same persona) | Status |
|---|---|---|---|
| D-01 | staff.hms request 71 «بانتظار قرار فريق الموارد البشرية»; in no inbox; HR-HMS Pending 9 vs inbox 2 | Same request is **70** in this copy. Requester text names «مديرك مايا حلبي». Maya (role `employee`) sees it in her inbox (badge 2) and accepted it; HR-HMS has it **only** as *To mark as seen* afterwards («The manager Maya Halabi accepted this request»), never as a decision. HR-HMS *All leaves* Pending (10) = inbox decisions 2 (19, 57: no manager) + 8 decidable by a holder (14, 48, 55 → users 156/168/180; 40, 70 → 56; 67, 68, 69 → 23/124); 0 former, 0 own, **0 undecidable**. HR-ALP: Pending 9 = inbox 8 + request 11 (holders 39/145, employee role, at work). DB sweep: every pending request in ALP/HMS/RHO/DMS/LAT has ≥ 1 decider. Only **34** (TAR) has none, because the copy has no HR-TAR account and no manager position (data gap, see N-08). | **Fixed** |
| D-02 | On-behalf decision reported as "The manager accepted" | Request 65: HR's review row reads "HR (Lara Mansour) accepted this request on behalf of George Khoury." | **Fixed** (see N-06: only one of the two absent managers is named) |
| D-03 | Already-started requests counted as "start within two days" | hr.alp: "⚠ 3 have already started" (4, 17, 62) with an *Already started* label; manager.hms "⚠ 1 has already started" (42); Maya's row 40 «بدأ دون قرار». Nothing counted as "starts soon". | **Fixed** |
| D-04 | Legacy HR decisions read "The manager rejected" | hr.alp rows 15, 6 and hr.hms rows 37, 24, 35, 61 read "Lara Mansour rejected this request." | **Fixed** (see N-07) |
| D-05 | Badge 3 vs page 2 after `already_handled` | Hadi Shami (session B) badge **4 → 3** together with the page after the refusal. | **Fixed** |
| D-06 | Manager's own notice says "your manager George Khoury" | manager.alp notices: "Annual leave request from Rana Daher · Awaiting your action · Waiting for your decision." | **Fixed** for managers; HR-as-decider variant open (N-03) |
| D-07 | Bell counts handled requests | manager.alp bell **2** with 3 unread (65 "Already handled" not counted); Maya 3→2 after deciding 70. | **Fixed**; unread "No longer available" notices are still counted (N-04) |
| D-08 | Arabic comma in English text | "You decide on behalf of George Khoury, Hadi Shami (on leave)." | **Fixed** |
| D-09 | Select2 list re-opens after a keyboard choice; mouse clicks on options do not register | Same in AR phone and EN phone (leave type). | **Still present** |
| D-10 | `/centers` 500 on SQLite | hr.alp `/centers` → **500**, laravel.log 09:59:07 "Not enough data available to satisfy format". | **Still present** (SQLite; MySQL not checked) |

## 4. Consistency matrix

**Badge** = Work inbox number in the menu. **Bell** = bell number (`unreadCount`). **Calendar today** = day view, default scope. **Card today** = Upcoming Leaves entries dated today. Read in the on-behalf state (71/72 active) unless noted; "already started" rows are present for every HR/manager persona that has them.

| Persona | Badge | Inbox (All / decide / review / items) | *For you now* | Team/All leaves Pending | Bell | Calendar today | Card today | Agree? |
|---|---|---|---|---|---|---|---|---|
| staff.alp | — (no inbox) | `/inbox` 200, "All 0" | 2 open requests → "HR team in Aleppo" (4, 73) | 403 | 0 | "Hala Najjar (You)" | Hala Najjar | yes |
| staff.hms (after 70 accepted) | — | All 0 | balance only, no open request | — | 0 | Hala Najjar (same department) | — | yes |
| manager.alp (on leave today) | 3 | 3 / 3 | "Needs my decision · 3" (36, 64, 66) | My team Pending **3** | 2 | reports: 0 of 28 | none | yes |
| manager.hms | 5 | 5 / 5 | 5, "⚠ 1 has already started" (42) | — | 3 | reports: 0 of 17 | none | yes |
| hr.alp, before the state (DB) | 9 | 9 / 4 / 2 / 3 | — | — | 8 | — | — | — |
| hr.alp, on-behalf | **13** | 13 / **8** / 2 / 3 (36, 64, 65, 66 "on behalf of George Khoury, Hadi Shami") | 13 (8 · 2 · 3), "⚠ 3 have already started" | — | 8 | — | — | yes |
| hr.alp, after R5 + R3 | 14 | 14 / 8 / 3 / 3 (65 → review; 73 added) | 14 (8 · 3 · 3) | All leaves Pending **9** (= 8 + request 11) | 9 | **4 of 60** (George Haddad, George Khoury, Hala Najjar, Yara Khoury) | **4** (same names) | yes |
| hr.hms | 7 | 7 / 2 / 5 | 7 (2 · 5) | All leaves Pending **10** (= 2 + 8 holder-decidable) | 2 | **1 of 71** (Hadi Shami) | 1 (Hadi Shami) | yes |
| **Maya Halabi** (employee role) | 2 → 1 → 0 | 2 / 2 → 1 / 1 → 0 | **nothing** (balance only) | 403 | 3 → 2 → 2 | reports | — | **no**: badge 2 vs dashboard 0 (N-01) |
| **Hadi Shami** (employee role, on leave today) | 4 → 3 | 4 / 4 (36, 64, 65, 66; still decides as `manager` while on leave) | **nothing** | — | 0 | reports: 0 of 28 | — | **no** (N-01) |
| **Dana Saliba** (employee role) | 5 | 5 / 5 | **nothing** | — | 3 → 2 | — | — | **no** (N-01) |

`ForYouNow::plan()` picks parts by role name; the default (employee) branch has no `decisions` part (nor `team-off`), so a position holder without the `manager` role sees the badge and the inbox but no decision block on the dashboard.

## 5. Concurrency (sessions A/B)

| Check | Steps | UI result | DB afterwards |
|---|---|---|---|
| Employee-role manager and HR on the same request | Both opened `inbox?open=leave:65:decide`. B (Hadi Shami, role `employee`, on leave) chose Reject and typed a reason. A (hr.alp) chose Accept and saved ("You decide on behalf of George Khoury, Hadi Shami (on leave)"). B then saved Reject. | A: "Leave request accepted."; the row moved to *To mark as seen*. B: "This request was already accepted by Lara Mansour on 28-10-2026, 09:45. Your decision was not saved."; row removed; **badge 4 → 3**. | **65**: one write, status 1, `approved_by` 1, `on_behalf`, `decided_on_behalf_of` 34, balance 62 `used` 24 h, `reject_reason` null; one `ActionLeaveRequest` to the requester. |

## 6. Privacy probes

| Persona | Probe | Result |
|---|---|---|
| Maya Halabi (employee-role manager) | Inbox lists only 40 and 70 (her position-27 reports); `/ar/contracts`, `/ar/contracts/162` (her report's), `/190`, `/7`, `/162/export-preview`, `/ar/employees`, `/ar/employees/162/edit`, `/ar/leaves/all`, `/ar/positions` | all **403**: no pay or private field reachable |
| Maya Halabi | Livewire `inbox.decide` on 67 (other manager's report), 11 (ALP, not hers), 4 (HR's), 36; `markSeen` / `object` on 70 | refused; DB unchanged (67/11/4/36 pending, 70 `hr_approved` 0) |
| Maya Halabi | Calendar `scope=city`, `scope=org&city=2` | fall back to *My direct reports* |
| Hadi Shami | Inbox lists only 36, 64, 65, 66 (position-1 reports) | yes |
| Hala Khoury as `manager` (holder of position 5) | `Contract::visibleTo` = 26, 33, 70, 198, 206, 298 (direct reports); browser `/contracts/88`, `/79`, `/186`, `/75`, `/173` (positions whose **technical** manager is position 5) | **403** (G-C-8 fixed); `/contracts/26`, `/33` 200 with «Pay details are hidden»; `/contracts/7/edit`, `/26/export-preview` 403 |
| manager.alp | `/contracts/169`, `/194` (non-reports), `/7/edit`, `/7/export-preview`, `/employees`, `/positions` | 403; `/contracts/7` 200 with pay hidden; *My team* lists only his reports |
| manager.alp | Livewire `decide` 67, 11, 69; `markSeen` 70; `object` 65 | refused; DB unchanged (65 still accepted, `hr_approved` 0) |
| hr.alp | `/contracts/194`, `/169`, `/employees/194/edit`, `/employees/162/edit` (HMS) | 403; calendar `scope=org&city=3` falls back to *My city* (4 of 60) |
| hr.alp | Livewire `decide` 67 (HMS), 19 (RHO), 11 (ALP, manager at work); `markSeen` 70 (HMS); `object` 40 (HMS) | refused; DB unchanged |
| hr.alp | Inbox snapshot | no salary, national id or leave reason text (only the `reasons[]` input) |

## 7. Decision reminders (`leaves:send-decision-reminders`)

Run through `db.php` (copy env, `perf_assert_isolated`), never `php artisan` on the real `.env`.

| Check | Result |
|---|---|
| First run | "Sent 28 leave decision reminder(s)": 68 (aged 4 days) plus every pending request already started or starting tomorrow (4, 8, 11, 14, 17, 42, 48, 55, 62). |
| Request 68 | managers **23** (Dana Saliba, employee role) and **124** (Yara Atallah) one each (`action: decide`), HR-HMS **90** one copy ("has no decision yet. The manager was reminded."). Exactly one each. |
| Second run | "Sent 0"; still 28 notifications, 28 ledger rows. |
| Link | Dana's bell → "Reminder: Leave Request Awaiting Your Decision … Karim Halabi" → opened `localhost:8403/inbox?open=leave:68:decide` with the row highlighted. The stored absolute `url` is `127.0.0.1:8391/...` because the CLI run had no `X2_PORT` (copy env only); the click went through the app and reached the right row. HR's copy links to `/leaves/all?leave=68&mode=details`. |
| No-manager requests | 4, 17, 62 (HR decides, no direct manager): only hr.alp got "**Leave Request Still Awaiting the Manager** … The manager was reminded." (N-02). 34 (TAR): nobody to remind (N-08). |

## 8. New findings

| # | Severity | Finding | Evidence |
|---|---|---|---|
| **N-01** | **Medium** | **Employee-role manager-position holders get no decision block on the dashboard.** The badge and inbox show their decisions (DEC-05 rev 2), but *For you now* shows only the balance: the default branch of `ForYouNow::plan()` has no `decisions` part (and no `team-off`). A contradictory actionable count (AC-G-C-02), and C0 T4's main entry is missing for exactly the people G-C-1 brought in; on the phone the inbox is only in the drawer. | Maya (badge 2, dashboard none), Hadi (4), Dana (5) |
| **N-02** | **Medium** | **Reminder for a request with no direct manager tells HR "The manager was reminded"** under the title "Still Awaiting the Manager", with no `decide` action and a link to *All leaves* instead of the inbox, although HR is the decider and no manager exists. So the decider never gets a "your decision" reminder. | requests 4, 17, 62 → user 1 |
| N-03 | Low | HR deciding a no-manager request sees its new-request notice from the requester's point of view: "Waiting for a decision from the HR team in Aleppo, which has been sent a notification." (D-06, HR variant) | hr.alp, request 73 |
| N-04 | Low | The bell still counts unread notices that read "No longer available" (only "Already handled" is subtracted). Mostly X1 fixture notices here (I-8), but it also happens in real use when access changes. | Maya bell 2 with 0 work; Dana 3 → 2 |
| N-05 | Low (a11y) | Contract composer: radio inputs are announced by value ("1", "5", "3"; "1"/"2"), and the employee/program/position comboboxes by their placeholder «اختيار», not by their labels. | accessibility tree of `/ar/contracts/create` |
| N-06 | Low | On-behalf summary names one absent manager ("on behalf of George Khoury") while both holders were on leave and the decide row named both. `decided_on_behalf_of` holds one id. | request 65 |
| N-07 | Info | HR is asked to mark as seen its own decisions; the row names the HR person ("Lara Mansour rejected this request") to Lara herself. | hr.alp rows 15, 6 |
| N-08 | Info (data) | Request 34 (TAR) has no possible decider and no reminder audience: no TAR HR account and no manager position in the copy. Check real data for cities without an HR account. | deciders sweep |
| N-09 | Low (wording) | «بانتظار قرار مديرك مايا حلبي، وقد أُرسل إليه إشعار» uses the masculine pronoun for a female manager. | request 70, AR |

Observations: (a) the leave confirmation shows "left after this request" from the hours accrued so far (115 h → «16 يومًا وساعة و40 دقيقة») while the balance card shows the full entitlement (172 h → «25 يومًا و5 ساعات و20 دقيقة»): same screen, two different "remaining" bases; outside G-C, worth a look by the leave-calculation owner. (b) Program names show in English in the AR composer (fixture data has no Arabic names). (c) A manager on accepted leave still decides as `manager` (Hadi, George); HR may also decide on behalf. Both by design (C3-Q1).

## 9. Not covered (limitations, not passes)

- **No participants**: no time on task, SEQ, SUS or comprehension; X2 baseline is still 0 observations, so no improvement is claimed.
- Cells: A AR desktop, B AR desktop, C EN phone, D EN desktop/phone, D AR phone, D′ (reservation upgrade).
- G-C-3 with an HR role **lacking** `leaves.review` (only a manager without it was probed); G-C-6 leavers' items (*Former employee* mark, batch confirm); G-C-7 with more than 100 rows (HR-SYR / super-admin); G-C-9 Statistics card; G-C-10 "off this week" link; G-C-13 F12/F13/F15.
- HR objection flow; Ctrl+K; screen reader (only the accessibility tree was read); real phone, on-screen keyboard, 200 % zoom; general manager and super-admin personas; DMS/LAT/TAR HR personas in the browser.
- MySQL (D-10 and data shape seen on SQLite only); one browser engine; emulated viewports.

## 10. Follow-up: G-C-17 and G-C-18 (code at `350fee96`)

Same copy `C:/laragon/tmp/hc-hr-gc2`, same launch entry (port 8403), restarted on the new code and stopped afterwards. No app code changed, nothing committed.

Synthetic state added (copy only): pending request **74** for staff.hms (26-11-2026, balance 941) so that Maya has a decision. Requests 62, 66 and 67 were aged to 4 days; the earlier ledger row for 62 was deleted (66 and 67 had none). `leaves:send-decision-reminders` was run through `db.php` with `X2_HOME`/`X2_PORT=8403` (copy env only).

| Check | Persona | Locale / device | Result | Ids |
|---|---|---|---|---|
| G-C-17 home decisions | Dana Saliba (employee role) | EN desktop | *Needs my decision · 5*, "⚠ 1 has already started"; badge 5 = inbox All 5 / decisions 5. Clicking the row "Fares Antoun" opened `inbox?open=leave:67:decide` with the row highlighted. | 10, 42, 67, 68, 69 |
| G-C-17 | Maya Halabi (employee role) | **AR phone** | «بانتظار قراري · طلب إجازة واحد بانتظارك»; badge 1 = inbox 1 = decisions 1. Tapping «نبيل فرح» opened `/ar/inbox?open=leave:74:decide`. | 74 |
| G-C-17 | Hadi Shami (employee role, on leave) | EN desktop | *Needs my decision · 3*; badge 3 = inbox 3. Clicking the row "Rana Daher" opened `inbox?open=leave:66:decide`. | 36, 64, 66 |
| G-C-17 unchanged | staff.alp (no manager position) | EN desktop | No decisions part and no inbox menu item; balance and *My open requests* only. | — |
| G-C-18 send | — | CLI | 7 sent, then **0** on the second run. 62 → user 1 `hr_decider` (action `decide`, link `/inbox?open=leave:62:decide`). 66 → users 34 and 136 (manager) and 1 (`hr_decider`, every manager on leave). 67 → users 23 and 124 (manager) and 90 (HR copy: "Leave Request Still Awaiting the Manager … The manager was reminded.", link to *All leaves*, unchanged). | 62, 66, 67 |
| G-C-18 bell | hr.alp | EN + AR desktop | EN: "Reminder: Leave Request Awaiting Your Decision … is still waiting for a decision. No direct manager can decide it now, so HR decides." · *Awaiting your action* · Pending. AR: «تذكير: طلب إجازة بانتظار قرارك … لذلك تقرّر الموارد البشرية» · «بانتظار إجراء منك». | 62 |
| G-C-18 link + decide | hr.alp | AR desktop | Click → `/ar/inbox?open=leave:62:decide`, row highlighted («لا يوجد مدير مباشر لهذا الموظف، لذا تقرر الموارد البشرية»). Rejected with a reason: toast «رُفض طلب الإجازة»; decisions 8→7, review 3→4. The notice now reads «تمت معالجته · مرفوض». DB: 62 status 2, `approved_by` 1, basis `no_manager`. | 62 |
| With-manager HR copy | hr.hms | EN desktop | Bell: "Leave Request Still Awaiting the Manager … The manager was reminded." (unchanged). | 67 |

**Result: G-C-17 and G-C-18 verified fixed** for the cases above.

Remaining notes (low):
- (a) For 66, the HR text "No direct manager can decide it now" is inaccurate. Both managers are on leave, but they still decide and got their own reminders; the inbox row's "on behalf of … (on leave)" is accurate.
- (b) The notice's state line still reads "Waiting for a decision from the HR team in Aleppo" to HR itself (N-03 open).
- (c) The title is in Title Case ("Leave Request Awaiting Your Decision"), not the sentence case in the brief.
- (d) The first-run reminder for 62 (old wording) stays in the bell list.
