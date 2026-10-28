# G-C · Moderated browser walkthrough evidence

Date: 2026-10-27 · App: `main` @ `aa983edc` (clean tree) · Moderator-only run, **no participants**. No timings, SEQ or SUS are reported. No app code was changed. The only repo change is a new `.claude/launch.json` entry (`hc-hr G-C (synthetic SQLite copy)`, port 8402).

## 1. Environment

| Item | Value |
|---|---|
| Data | Fresh synthetic SQLite copy `C:/laragon/tmp/hc-hr-gc` built from HEAD with the X1 builder + `tests/Usability/Baseline/session-states.php` (manifest `x2-session-manifest.json`, built 14:45:59). Clean snapshot `perf.clean.sqlite` taken before any run. Operational MySQL and `.env` untouched. |
| PHP / assets | `C:/laragon/bin/php/php-8.5/php.exe` (8.5.11). `npm run build` re-run first: `public/build` was older than the last `resources/` commit. (The older launch entries point at `php-8.5.11/php.exe`, which no longer exists on this machine.) |
| Server | `php -S 127.0.0.1:8402` via launch entry, started with `preview_start`; stopped at the end. |
| Browser | Built-in Chromium pane. Desktop runs emulated at 1238×742; phone at 375×667. |
| Sessions | Session A = `127.0.0.1:8402`, session B = `localhost:8402` (separate cookies). A third tab on `localhost` shared session B, for the role switch. |
| Sign-in | Scripted: email and fixture password filled through the DOM. Product tours were marked seen in `localStorage` before the runs. |
| English | **Works on this copy.** EN has no prefix (`/en/login` → `/login`); `/en` sets the locale and redirects to `/`. The `/en` → https problem (G-B-12) did not occur on `127.0.0.1`. The server sends an HSTS header; `localhost` was not upgraded either. |
| Input | Real clicks and keys for navigation, search, decisions and the leave form. Exceptions: date inputs were set directly; Select2 options were chosen by keyboard, because mouse clicks on the options did not register; privacy probes used `fetch()` and `Livewire.find().call()` (on purpose); the role switch used `RoleSwitcher::switchTo` from a second tab. |

Personas (from the manifest): `staff.alp` (Hala Najjar, emp 2) · `staff.hms` (Nabil Farah, emp 162) · `manager.alp` (George Khoury, emp 34, position 1) · `manager.hms` (Yara Atallah, emp 195, position 10) · `hr.alp` (HR-ALP) · `hr.hms` (HR-HMS = Homs + Homs Rural). Two extra synthetic requesters were used only to read their own request state: `synth.010` (Rana Sabbagh) and `synth.007` (Nour Halabi).

### Synthetic state changes made in the copy (copy only)

| Change | Ids | Why |
|---|---|---|
| Accepted annual leave on 27–28 Oct for **both** holders of position 1: George Khoury (balance 218) and Hadi Shami (balance 1187). `used` was not changed. | leave_requests **72, 73** | C3 on-behalf state (every direct manager on leave today) |
| `manager.alp` (user 34) given a second, inactive `employee` role; **removed afterwards** | model_has_roles | role switch on a stale page |
| Leave requests created or decided by the runs | 64, 65, 67, 70, 71 | see §2 and §6 |

## 2. Runs (X2 tasks; outcome codes per task-scripts §0.3)

The baseline is [usability-baseline.md](../../usability-baseline.md) §6: **0 participant observations**. Nothing below is a comparison with a baseline, and no improvement is claimed. "Clicks" counts the moderator's interactions from the dashboard to the stop point.

| # | Task | Persona | Locale | Device | Path from the dashboard | Clicks | Outcome | End-state evidence |
|---|---|---|---|---|---|---|---|---|
| R1 | A · Request leave | staff.alp | EN | desktop | *For you now* → **Request leave** (opens My leaves with the dialog) → type (keyboard) → dates → reason → Submit → confirm Save | 1 to the entry; 7 to the end | **S** | Request **70**: type 1 (annual), day, 04-11-2026, "Family visit", pending; one row. Toast: "Leave request sent. Status: Pending." |
| R2 | A · Request leave | staff.hms | AR | phone | لك الآن → **طلب إجازة** → … → Enter in the reason field → confirmation → حفظ | 1 to the entry | **S** | Request **71**: annual, day, 04-11-2026, «زيارة عائلية», pending; one row |
| R3 | B · Find an employee | hr.alp | EN | desktop | Header search "Mira Najjar" → ↓ → Enter → profile | 3 | **S** | Profile `synth.040` = target id 40 (manifest). One result, showing the Arabic name, position and city. |
| R4 | B · Find an employee | hr.hms | AR | phone | Search button → «كريم عيسى» → ↓ → Enter → profile | 4 | **S** | Profile `synth.194` = target id 194. The email is a `mailto:` link only, not visible as text on the phone (see I-5). |
| R5 | C · Review a request | manager.alp | EN | desktop | *Needs my decision* → row "Nour Halabi" → inbox `?open=leave:64:decide` → Reject → empty reason + Enter (error shown) → reason + Enter | 1 to the entry | **S** | **64** rejected by 34, basis `manager`, reason stored. 65, 66 and 36 unchanged. Badge 4→3. |
| R6 | C · Review a request | manager.hms | AR | desktop | بانتظار قراري → row «فارس أنطون» → inbox → رفض → reason → Enter | 1 to the entry | **S** | **67** rejected by 195, basis `manager`. 68, 69, 42 and 10 unchanged. Badge 5→4. |
| R7 | C′ · HR on behalf (accept) | hr.alp | EN | desktop | Inbox → row "Rana Sabbagh" («You decide on behalf of George Khoury، Hadi Shami (on leave)») → Accept → Save | 2 | Completed (variant) | **65** accepted by employee 1, basis `on_behalf`, `decided_on_behalf_of` = 34, balance 62 `used` = 24 h |
| R8 | D · Create a contract | hr.alp | EN | desktop | **New** → **Contract** → composer `/contracts/create?from=…` | 2 | **Entry found only** | The composer was not completed. This is **not an S**: the end state was not exercised. |

**Coverage:** A 2 runs, B 2 runs, C 2 runs (+ the HR variant), D entry point only. The 16 task × language × device cells were not all covered. Not run: A EN phone, A AR desktop, B EN phone, B AR desktop, C EN phone, C AR phone, and every D end state.

## 3. Findability: C0 scenarios on the built menu (moderator, no participants)

The outcome codes here are findability only: **F✓** = entry point reached without a URL; **F✗** = not found.

| C0 | Persona / locale / device | First click → path | Clicks | Result |
|---|---|---|---|---|
| T1 Request leave next week | staff.alp EN desktop; staff.hms AR phone | *For you now* → Request leave (also: New → Leave request; My space → My Leaves) | 1 | F✓ |
| T2 Work phone of a colleague in another city | staff.alp EN desktop | Header search "Karim Issa" → Enter → profile (Work Phone 0900000194, Telegram) | 2 | F✓. Directory tier only: no private fields. |
| T3 Has my manager answered | staff.alp EN; staff.hms AR phone | *For you now* → *My open requests* shows the state and the next actor in place | 0 | F✓ |
| T4 Respond to a team member's leave | manager.alp EN/AR; manager.hms AR | *For you now* → Needs my decision → row; or Work inbox (badge) | 1 | F✓. On the phone the inbox badge sits inside the drawer; the dashboard block is the visible entry. |
| T5 Who in my team is off this week | manager.alp EN desktop, AR phone | My space → **Team calendar** (default «فريقي المباشر», "x of 28 away"); page search «تقويم» finds it | 1 | F✓. The *For you now* "team off" part did not show, correctly: no report was off this week. Note: C1 §6 put the calendar in *My team*; it is in *My space*. |
| T6 Record that HR reviewed a manager's decision | hr.alp EN/AR; hr.hms AR | Work inbox → *To mark as seen* filter → Seen by HR | 1–2 | F✓ (wording defect, D-04) |
| T7 Contracts ending this month | hr.alp EN; hr.hms AR | *For you now* → "Contracts ending in 30 days · 8 contracts →" (HMS: 4) | 1 | F✓. The target page's count was not re-checked. |
| T8 New contract | hr.alp EN | New → Contract | 2 | F✓ |
| T9 Change role permissions (admin) | — | not covered: no super-admin persona in this gate | — | — |

Bilingual and keyboard search (§4) were used for T2, B and T5.

## 4. Bilingual search (C2)

| Check | UI | Persona | Result |
|---|---|---|---|
| Arabic name in the EN UI: «ميرا نجار» | EN desktop | staff.alp | 1 result: "Mira Najjar · ميرا نجار · ICDL Trainer · Aleppo". The payload holds only name, position, city and profile URL. |
| English name in the AR UI: "Mira Najjar" | AR phone | staff.hms | «ميرا نجار · Mira Najjar · مدرب ICDL · حلب»; no page scroll sideways (375/375) |
| Spelling variant «ساره» | AR phone | manager.alp | Found both «سارة نجار» (حلب) and «سارة رزق» (دمشق) |
| Page search «تقويم», «صندوق» | AR phone | manager.alp | Team calendar (مساحتي); Work inbox |
| Arabic name in the AR UI «كريم عيسى»; Latin "Karim Issa" in the EN UI | AR phone / EN desktop | hr.hms / staff.alp | 1 result each |
| Arabic-Indic digits | — | — | **Not tested as a feature: C2 does not promise digit folding** (people search matches names only). |

## 5. Consistency matrix

**"Badge"** is the Work inbox number in the menu. **"Bell"** is the unread notifications counter. **"Calendar today"** is the day view in the role's default scope. **"Card today"** is the Upcoming Leaves card's entries dated today.

### 5.1 Before the on-behalf state (after R5/R6)

| Persona | Badge | Inbox page (All / decide / review / items) | Dashboard *For you now* | All leaves "Pending" tab | Bell | Calendar today | Card today | Agree? |
|---|---|---|---|---|---|---|---|---|
| staff.alp | — (no inbox) | `/inbox` opens and is empty ("All 0") | 2 open requests, next actor «HR team in Aleppo» | 403 | 0 | own leave only ("You · Annual") | Hala Najjar (self) | yes |
| staff.hms | — | — | 1 open request → «فريق الموارد البشرية في حمص» | — | 0 | — | — | **no**: next actor is wrong (D-01) |
| manager.alp | 4 → 3 | 4 → 3 / 3 | 4 → 3 | 3 (after R5) | **3**, unchanged after R5 (one notice now "Already handled") | reports: 0 away | none | Badge, inbox and dashboard agree. The bell counts something else (I-1). |
| manager.hms | 5 → 4 | 5 → 4 | 5 «⚠ واحد يبدأ خلال يومين» | — | 3 | — | — | Counts agree; the "starts soon" flag is wrong (D-03) |
| hr.alp | 11 | 11 / 5 / 3 / 3 | 11 (5 · 3 · 3), «⚠ 3 start within two days» | — | 14 | **3 of 60** (George Haddad, Hala Najjar, Yara Khoury) | 3 | Counts agree; D-03 |
| hr.hms | 7 | 7 / 2 / 5 / 0 | 7 (2 · 5) | **9** | 3 | 1 of 71 (after the state change) | 1 | Inbox 2 vs pending 9: 5 pending requests have no decider (D-01) |

### 5.2 After the C3 on-behalf state (requests 72/73: both position-1 holders on leave today)

| Persona | Badge | Inbox | Dashboard | Calendar today | Card today | Agree? |
|---|---|---|---|---|---|---|
| hr.alp | **14** | 14 / **8** / 3 / 3. Rows 36, 65, 66 read «You decide on behalf of George Khoury، Hadi Shami (on leave)» | 14 (8 · 3 · 3) | **4 of 60** (+ George Khoury) | 4 (+ George Khoury) | **yes** |
| manager.alp (session B) | 3 | 3 (still the deciding manager while on leave, basis `manager`) | 3 | — | — | yes |
| hr.hms | 7 | 7 | 7 | **1 of 71** (Hadi Shami, HMS) | 1 | yes. Hadi is in HMS, so he is absent from hr.alp's city view, correctly. |
| After R7 (hr.alp accepted 65 on behalf) | hr.alp 14 | 14 / 7 / **4** / 3: 65 moved to *To mark as seen* | — | — | — | Counts agree, but the review row says "**The manager accepted**" (D-04) |
| manager.alp tab after R7 + stale save | **3** | **2** | — | — | — | **no**: badge 3 vs page 2 after an `already_handled` outcome (D-05) |

DB check of the no-manager rule (read-only script `scratchpad/deciders.php`, using `LeaveDecisionAuthority::basisFor` for every manager or HR account, and `LeaveRequestNextStep::describe`), §7 D-01.

## 6. Concurrency and role switch (sessions A/B)

| Check | Steps | UI result | DB afterwards |
|---|---|---|---|
| Manager and HR decide the same request | Both opened `inbox?open=leave:65:decide`. Session B (manager.alp) chose Reject and typed a reason. Session A (hr.alp) saved **Accept** on behalf. Session B then saved **Reject**. | A: "Leave request accepted." B: "This request was already accepted by Lara Mansour on 27-10-2026, 15:03. Your decision was not saved." The row left B's page. | **65**: one write, status 1, approved_by 1, `on_behalf`, `decided_on_behalf_of` 34, `used` 24 h; 3 `ActionLeaveRequest` notifications; no reject reason. **No double write.** Defect D-05 (badge). |
| Role switch on a stale inbox page | Tab 1 (session B) on inbox, Accept chosen for **66**. Tab 2 (same session) switched manager.alp → `employee` with `RoleSwitcher::switchTo(5)`; tab 2's menu became the employee menu. Tab 1 then saved. | "This item is no longer available to you."; the list became empty. The stale tab still showed "Working as: Manager" and badge 2 until reload (I-3). | **66** still pending, no approver, `updated_at` unchanged. Role restored afterwards. |

## 7. Defects

| # | Severity | Finding | Evidence |
|---|---|---|---|
| **D-01** | **High** | **Requests that nobody can decide, while the employee is told HR will.** When the requester's direct-manager position is held only by people whose role is not `manager` or `general-manager`, `LeaveDecisionAuthority::basisFor` gives no manager basis (no `leaves.decide`) and no HR basis (holders exist and are not on leave). Meanwhile `LeaveRequestNextStep` drops non-manager holders and tells the employee "Waiting for a decision from the HR team in …". The request appears in no inbox, but HR's *All leaves* still counts it as pending. C3-Q1 covers "no manager / nobody holds the position", not "holder without a deciding role". | Seen in the browser: staff.hms request **71** reads «بانتظار قرار فريق الموارد البشرية في حمص»; hr.hms's inbox has 2 decisions, but *All leaves* shows Pending (9). The read-only script lists **11, 14, 40, 48, 55, 71** in ALP/HMS/RHO (plus 7, 8, 31, 41 in DMS/LAT, not walked) with `deciders=[]`. Holders: emp 56, 39/216 and 229/241/253, all role `employee`. Synthetic data makes this frequent; whether real data has such holders was not checked. |
| D-02 | Medium | **On-behalf decision reported as the manager's.** After HR accepts on behalf, HR's own *To mark as seen* row says "The manager accepted this request" / «قبل المدير هذا الطلب», and asks HR to review its own decision. The employee's details correctly say "Decided by Lara Mansour (HR) on behalf of George Khoury". | Request 65, EN desktop and AR phone, hr.alp inbox |
| D-03 | Medium | **"Starts soon" on requests that already started.** Pending requests whose start date is in the past are flagged "Starts soon" / «يبدأ قريبًا» and counted in "⚠ N start within two days" on the dashboard. | hr.alp: requests 4 (11 Oct), 17 (18 Oct), 62 (8 Oct) → "⚠ 3 start within two days". manager.hms: request 42 (7 Oct) → «⚠ واحد يبدأ خلال يومين». |
| D-04 | Medium | (Same root as D-02, kept separate for triage.) The C0 T6 task is found, but the review row's wording misstates who decided for pre-C3 rows too: legacy rows with `approved_by` = 1 (HR) and a null basis read "The manager rejected this request". | hr.alp review rows 15, 6; hr.hms review rows |
| D-05 | Low | **Inbox badge not refreshed after an `already_handled` outcome.** Menu badge 3, page "All 2" until reload. After `done` it does refresh. | manager.alp session B after the R7 race |
| D-06 | Low | **Manager notification written from the requester's point of view:** George Khoury's own bell reads "Waiting for a decision from **your manager George Khoury**". | manager.alp bell, requests 65 and 66 |
| D-07 | Low | **The bell keeps counting handled requests:** unread 3 after 64 was rejected; the item says "Already handled". The bell and the inbox both read 3 and 14 at times by coincidence, though they count different things (I-1). | manager.alp, hr.alp |
| D-08 | Low | Arabic comma in English text: "on behalf of George Khoury، Hadi Shami". | hr.alp inbox EN |
| D-09 | Low | Select2 leave-type list **re-opens after a keyboard selection** (the value is kept); seen in EN desktop and AR phone. Mouse clicks on options did not register in this pane: probably the pane's event synthesis, but not confirmed on a real browser. | Leave dialog, R1/R2 |
| D-10 | Low (env) | `/centers` returns **500** for manager.alp on the SQLite copy. `Center::getOpenAtAttribute` calls `createFromFormat('H:i:s')`, and the SQLite column default is `'09:00'`. Likely SQLite-only (MySQL `time` stores seconds), but the accessor is brittle. | laravel.log 14:57:13, user 34 |

### Observations (not defects, or known)

| # | Note |
|---|---|
| I-1 | The bell counts unread notifications, not pending work. Request 36 (X1 fixture) has no notification, so the manager's bell and inbox happen to show the same number for different sets. |
| I-2 | The *Today Events* birthday card shows other-city employees' birthdays to staff. Birthday is Private tier. This is the known open item A2 §85 (not a G-C change). |
| I-3 | A stale tab keeps the old menu ("Working as: Manager", badge 2) after a role switch in another tab; actions are refused correctly. |
| I-4 | Search shows the **employee's** city ("Karim Issa · Homs", "Mira Najjar · Aleppo"); the profile shows the **position's** city (Damascus). Synthetic positions often sit in another city, so this may confuse but is data-driven. |
| I-5 | HR on the phone: the profile's work email is only a `mailto:` link and is not readable as text without expanding. |
| I-6 | "Waiting for N minutes" uses `created_at` from the fixture build, so old X1 requests read as minutes old. Fixture artifact. |
| I-7 | manager.hms's inbox holds Latakia requests (position 10 directly manages LAT staff). This is by design: direct reports regardless of city. |
| I-8 | Employees' bells hold X1 random notices that now read "no longer available". Fixture artifact (session-states clears only the staff personas). |

## 8. Privacy checks

| Persona | Probe | Result |
|---|---|---|
| staff.alp | `/employees`, `/contracts`, `/contracts/7`, `/contracts/194`, `/leaves/all`, `/positions`, `/termination-reasons`, `/employees/194/edit` | all **403** |
| staff.alp | `/team-calendar?scope=city`, `?scope=org&city=3`, `?scope=reports` | 200, but each **falls back to the colleague set** (same entries as the default: self, Zeina Haddad, Tarek Tannous). No other-city, pending or unrelated rows. |
| staff.alp | Calendar and card content | Accepted leave only for colleagues; type by name; no reason, status or request id. Tarek Tannous (HMS) is shown because he is in the same department (department 2, any governorate), which is allowed. Own pending leave is shown to self. |
| staff.alp | Search payload and profile of another city's employee | Snapshot holds only `q`; rows hold name, position, city and profile URL. Profile: work contact only (no national id, birthday, personal contact or pay). |
| manager.alp | `/contracts/169`, `/contracts/194` (not his reports), `/contracts/7/edit`, `/contracts/7/export-preview`, `/employees`, `/positions` | **403**. `/contracts/7` (a report's): 200, with the pay clause shown as «تفاصيل الأجر محجوبة / Pay details are hidden». |
| manager.alp | Livewire `inbox.decide('leave:67:decide')`, `decide('leave:69:decide')`, `markSeen('leave:67:review')`, `object('leave:61:review')` | Each returned `not_allowed` ("This item is no longer available to you."); DB unchanged (67 decided later only by manager.hms; 61 `hr_approved` 0) |
| manager.alp | Livewire `AllLeaveRequestModule.openDecision(67)` | **403** page |
| manager.alp | Calendar `scope=city`, `scope=org&city=3` | 200, fell back to an allowed scope |
| hr.hms | `/ar/contracts/7`, `/ar/contracts/40`, `/ar/employees/7/edit` (ALP) | **403**. Own city (194): 200. |
| hr.hms | Calendar `scope=org&city=2` | Fell back to «مدينتي» (HMS + RHO): 1 of 71, no ALP rows |
| all | *For you now* and the inbox | No salary, pay tier or national id seen in any block. Leave reason is not in inbox rows. |

## 9. Request-state comprehension (state + next actor per surface)

| State (id) | Requester view | Dashboard *For you now* | Inbox row | Notification |
|---|---|---|---|---|
| Pending, no manager (70) | My open requests: "Waiting for a decision from the HR team in Aleppo…" ✓ | ✓ (staff) | HR: "This employee has no direct manager, so HR decides." ✓ | — |
| Pending, holder without a role (71) | «بانتظار قرار فريق الموارد البشرية في حمص» ✗ (nobody can decide, D-01) | ✗ | in no inbox ✗ | — |
| Pending with a manager (65, 66) | "Waiting for … your manager George Khoury … HR informed" ✓. Does not mention that HR may decide while the manager is on leave (contract §4.5 `manager_absent` is about the leave's range, so acceptable). | manager: row, no state text (dashboard rows show name and dates only) ~ | manager: "Leave request to decide" ✓; HR: on-behalf line ✓ | manager's bell: wrong point of view (D-06) |
| Rejected by the manager (64) | Details: status «مرفوض», reason, «اتخذ جورج خوري القرار … الخطوة التالية: تؤشّر الموارد البشرية …» ✓ | — | HR: "The manager rejected this request." ✓ | «تم رفض الإجازة … سبب الرفض» ✓ |
| Accepted on behalf (65) | "Decided by Lara Mansour (HR) on behalf of George Khoury … Next: HR marks the decision as seen" ✓ | — | HR review row: "The manager accepted" ✗ (D-02) | "Your leave request has been approved: Annual": no decider ~ |
| Accepted, legacy (1) | Shown "Accepted" in My leaves ✓ | — | — | — |

## 10. Not covered (limitations, not passes)

- **No participants**: no time on task, SEQ, SUS or comprehension answers. The X2 baseline is still "0 observations", so **no improvement can be claimed**.
- **Task D end state** (composer completion, seats, auto-renew box, D′) was not run.
- Missing cells: A EN phone, A AR desktop, B EN phone, B AR desktop, C EN phone, C AR phone. HR-HMS was not run in EN; HR-ALP was not run on an AR desktop.
- C0 T9 (admin); general manager and super-admin personas.
- Requester leave-form hint («N colleagues off») and `manager_absent` info with real overlap data. The manager overlap warning **was** seen (inbox rows 36 and 64).
- Checklist items (inbox *Items*, batch confirm) and the HR objection flow.
- Ctrl+K, screen reader, real phone and on-screen keyboard, 200 % zoom.
- Requests in DMS/LAT/TAR and their HR personas (D-01 listed them from the DB only).
- MySQL: D-10 and data-shape effects were seen on SQLite only.
- Only one browser engine; clicks run in an emulated viewport.
