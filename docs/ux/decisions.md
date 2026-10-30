# UX plan decisions (answers to the "Required decisions" table in ux-prompts-v3.md)

Source of policy: "سياسة الموارد البشرية - 2026" (Christian Hope Center), section 5.7.

## DEC-03 — Leave calculation rules (settled 2026-10-16)

### From the HR policy (no decision needed)
- Official holidays that fall inside a leave are not counted as leave days.
- Hourly leave: minimum a quarter hour; accumulates and is deducted from the annual balance. Half-day leave is allowed.
- Full-time: hourly leave over 4 hours counts as a full day. Part-time: deducted by hours only.
- Entitlement is prorated monthly over the contract term (example: starting at the beginning of month 8 → 5 days until year end). Someone hired after the 15th gets no leave for that month.
- Unused leave expires at year end; no carry-over, no payout on termination.
- Annual entitlement by years of service: 1y 12d, 2y 14, 3y 18, 5y 20, 6y 22, 7y 24, 8y 26, 9y 28, 10y+ 30.
- Probation: up to 3 days with manager + HR approval; beyond that is deducted from pay.
- Sick 24d (12 full pay + 12 half pay), marriage 7, paternity 15 (within 6 months of birth), bereavement 7 (first degree) / 1 (second degree), unpaid 12, maternity 4 months (1st child) / 2 months (2nd), after 6 months of service.
- Leave is not allowed in December except in exceptional cases.

### Decided by HR
| Point | Decision |
|---|---|
| Weekend days | Taken from the **center** the volunteer works at (`centers.weekends`). A volunteer with no center (e.g. Study Zone) uses their **governorate's** weekend: some governorates are off on Sunday, others on Friday. |
| Hourly conversion (full-time, 8h) | hours ÷ 8; any request over 4 hours = one full day. |
| Pending requests | **Reserve** balance immediately; released on rejection/cancellation. |
| Historical requests | **Left as they are**; the unified rule applies to new requests only. |

### Decided by HR, A3-D follow-up (2026-10-20)
| Point | Decision |
|---|---|
| D-1 Weekend for a volunteer with no center | The volunteer belongs to a **program** in a governorate (e.g. Study Zone Homs). Take the weekend of **any center of that program in that governorate**; centers are uniform within a governorate and program. **No new governorate column is needed.** |
| D-2 Calendar-day leave types | **Maternity only.** Every other type excludes weekends and holidays. |
| D-3a Hourly rounding | **Exact**, with a **15-minute minimum**. No rounding up. |
| D-3b Hourly cap | **Cap removed.** For full-time, an hourly request over 4 hours counts as one full day. |
| D-4 Holidays | **National + the center's city + the program.** Recurring holidays repeat every year (confirm in writing if different). |
| D-5 Snapshot | **Yes.** Computed hours are stored on each request when created (needs a new column; technical lead to confirm design). |
| D-6 Non-annual over balance | **Warn**; HR decides at approval. Annual still blocks. |
| D-7 December | **Blocked** unless the requester marks it exceptional and both manager and HR approve. Needs an "exceptional" flag on the request. |
| D-8 Non-five-day schedules | Irregular schedules exist (e.g. Desk and Lab program): scattered days, **at most 5 days**, not written in the contract. The contract states only **weekly hours** (e.g. 20 h/week). Day length therefore cannot be derived for them; they are priced **in hours only** (see D-9). |
| D-9 Part-time | **Weekly hours below 40** = part-time, deducted by hours only. A center that works full time is identified by the **contract's weekly hours** (40). |
| Service agreements | A **service agreement** contract has **no paid leave and no balance**. If the volunteer apologizes for a session, HR is informed, the hours are simply not worked and not paid (pay is hourly). Nothing is deducted from any leave balance. The **direct manager also approves** the absence (HR is informed). Gap: the request form needs a balance (`leave_balance_id` is required) and service contracts have none, so this absence flow has no home today; it needs a decision on where it lives (a no-balance request type) in the implementation task. This matches the existing exemption in `LeaveBalanceService::isExemptFromLeave`. |

### D-4 refinement — who sets holidays (2026-10-21)
Official holidays are in the HR policy. They are entered by **national HR (HR-SYR)**, or by the **program manager** for the projects they are responsible for (a project = a program in one governorate). A program manager may grant a **one-off** holiday to a project. A holiday may be shared by several projects or belong to one; projects may have different holidays. A volunteer gets national holidays + their project's.
**Birthday holiday:** a holiday for everyone, each on their own birthday (`actual_dob`, else `dob`); created for a period and not necessarily repeated (e.g. this year only). On a weekend or holiday the volunteer picks another day in the same week. Shown to colleagues as "Birthday" (no year), an accepted exception to the private tier. The moved day needs the direct manager's approval; service agreements excluded; created by HR-SYR only.
**Other answers:** CYG holidays are set by HR-SYR; there is no governorate-wide holiday level, only per project; saints' days are commemorations shown in the calendar, not holidays. Design: [team-coverage-contract.md](team-coverage-contract.md) §6.1.

### Implementation gap found
- ~~`cities` has no weekend field~~ Resolved by D-1: the weekend is read from a center of the volunteer's program in their governorate. If the program has no center there, fall back to the code default with a visible warning.
- D-5 needs a schema change on `leave_requests` (computed hours + rule version); D-7 needs an "exceptional" flag. Both belong to the implementation task, not A3-D.

## DEC-04 — Employee draft (settled 2026-10-16)
| Point | Decision |
|---|---|
| Draft state | Yes. An incomplete volunteer is saved as a draft, listed separately as "incomplete", and not counted as active. |
| Minimum to save a draft | Name + governorate + national ID (the ID prevents duplicates). |
| Login account | Created when the first contract is activated; drafts and volunteers without a contract get none. |
| Saving | Explicit Save button **plus** an automatic draft so nothing is lost if the page closes; the volunteer becomes active only on Save. |

## DEC-05 — Approvals (settled 2026-10-16)
| Point | Decision |
|---|---|
| Sources in the approvals inbox | All leave types, overtime, salary advances, contract amendments. |
| Leave approval chain | Direct manager first, then HR checks the balance and gives final approval. |
| Rejection reason | Mandatory; visible to the volunteer and kept in the history. |
| Batch approval | Simple requests only (e.g. short leave within balance). Advances and contract amendments are approved one by one. |

### DEC-05 revision — leave approval (2026-10-20, from HR's handwritten flow draft)
Replaces the "Leave approval chain" row above **for leave requests only**. Other sources (overtime, advances, amendments) keep DEC-05 as written.

| Point | Decision |
|---|---|
| Flow | Volunteer submits → notification to the **direct manager and HR** at the same time. |
| Who decides | The **direct manager** accepts or rejects. HR does not give a second approval. |
| Technical manager | **No longer exists.** Only one manager: the direct manager. |
| HR after the decision | Marks **"Seen by HR"** (existing `hr_approved`). HR may **object**: the request goes **back to the manager** as pending, with HR's note. |
| HR on behalf of the manager | Only while the manager is on an **accepted leave**. Shown as "on behalf of <manager>" in the response column. |
| Upcoming events card | Shows **accepted** leaves. |
| HR note | An **optional free-text note** HR may write on the request (e.g. with "Seen by HR" or an objection). Visible to **HR, the direct manager and the volunteer** who made the request. |

Implementation notes (not decided here): today `LeaveRequestPolicy::decide` also allows HR at any time and the technical manager (`tech_manager_id` in `manageableBy` and in notifications); both must change. An objection on an accepted request must release its hours from `used` when it returns to pending (A3-D §3.4).

### DEC-05 revision 2 — G-C decisions (settled 2026-10-27, HR product owner, from [g-c-gate.md](g-c-gate.md))

| Point | Decision |
|---|---|
| Who decides (G-C-1, replaces the escalation answer given earlier the same day) | **The holder of the direct-manager position decides. There is no escalation**: a manager who is at work has no reason not to answer. Holding the position **is** the authority: the holder sees and decides their direct reports' requests whatever their system role (e.g. `employee` only). A holder with **no login account** cannot act; HR creates the account (data task, not a rule change). |
| Reminder (G-C-1) | If the manager has not answered a pending request **3 days after it was submitted**, or **1 day before the leave starts** (whichever comes first), the manager gets **one in-app notification**, with a copy to **HR in the requester's city**. Once per request; no email, no repeat. |
| Manager on leave | **Unchanged** (C3-Q1): HR decides on behalf while every direct manager is on accepted leave today. |
| Own request (G-C-2) | Nobody decides their own request, on any basis. Built in `99529cf3`. |
| Team contracts (G-C-8) | "Team contracts" follows **direct reports only**, like team leaves, the calendar and the inbox. |
| Leavers' checklist items (G-C-6) | Hand-out / take-back items of former employees **appear in the inbox**, so the inbox and the counters agree. |

## DEC-01 / DEC-02 — Directory and public card (settled 2026-10-16)
| Point | Decision |
|---|---|
| Public QR card contents | **Unchanged**: name, photo, position, center, city, phone, Telegram, LinkedIn, emergency number. |
| Card of a departed volunteer / lost card | Shows only "card is not valid", with no personal information. |
| Re-issued QR (replacement card) | The old printed card stops working **immediately**. |
| In-app directory (non-HR users) | Visible across **all governorates** (name, photo, position, work contact). |

### DEC-02 revision — card link (2026-10-20)
| Point | Decision |
|---|---|
| Card link | `ID/[city 3 digits][program 3 digits][employee_id]-[index]`, readable, no secret part. |
| Cardboard card | Index always `0`; temporary. It **stops when the plastic card is issued**. Lost before that: not revoked. |
| Plastic card | Starts at `1`; re-issue increments the index; only the latest index is valid. |
| Purpose | Lets other centers scan the QR to see whether the volunteer is **active** and reach the **emergency number** when needed. |
| Move to another governorate / new position | A **new card is printed**; the old link (old city/program code) stops. |
| Accepted risk | The link can be guessed, so all public cards can be collected by anyone. Details in access-policy.md §9 Q6. |

## DEC-06 — Team calendar (settled 2026-10-16)
| Point | Decision |
|---|---|
| "My team" | Both views: same project/program **and** same direct manager (user can switch between them). |
| What colleagues see | The leave **with its type**. |
| Coverage | Advisory warning to the manager only; never blocks a request. |

### DEC-06 detail (2026-10-21, C5-D)
Full record: [team-coverage-contract.md](team-coverage-contract.md) §3.1.

| Point | Decision |
|---|---|
| Colleague set (replaces "same project/program") | Same **department** in any governorate + same **center** + same **direct manager** (the manager included) + **project managers** (job level grade 3) of the same program see each other. |
| Service departments | Leave of a volunteer in CT (`Comm`), IT, HR, PR (`Fund`), Logistic (`Logi`), Financial (`Fin`), MEAL or Legal Affair consultant (`LegA`) is also visible to the **project managers of every program in the same governorate** (e.g. Logistic Aleppo → Study Zone Aleppo PM). Visibility only, no warning. |
| Sensitive types | Sick, maternity, paternity, bereavement show to colleagues as "On leave"; manager and HR see the type. |
| Pending | Colleagues see accepted only; manager and HR coverage counts pending, marked. |
| Warning | Manager: on any overlap. Requester: anonymous count before submitting. Never blocks. |
| HR scope | Regional HR: own city. super-admin: as national HR. |

## DEC-07 — Installable app (settled 2026-10-16)
Yes: make it installable (PWA) without offline caching of private data.

## DEC-08 — In-app feedback (settled 2026-10-16)
| Point | Decision |
|---|---|
| Who reads it | The development (IT) team only. |
| Content | Text only, no screenshots (they could expose pay or personal data). |
| Retention | Deleted after one year. |

## DEC-09 — First lifecycle journey (settled 2026-10-16)
Onboarding a new volunteer: draft → contract → login account → ID card → orientation session.

### DEC-09 detail (2026-10-30, D4)
Decided by the product owner for the first slice. Full record: [journeys.md](journeys.md) §6; built as D4-I ([d4-i-implementation.md](d4-i-implementation.md)).

| Point | Decision |
|---|---|
| Orientation session | HR **sets a date**, then **marks it held**: the record is the date plus done / not done. |
| Orientation owner | HR of the volunteer's **city**. |
| ID card | The **digital card activates automatically** at the first contract. HR then marks **"printed card handed over"**. |
| Orientation due date | **14 days after the first contract's start date**. |
| Overdue | The step shows a red **"Late"** tag, and city HR gets **one** in-app reminder. |
| Renewal and exit journeys | ~~Deferred~~ **Superseded 2026-10-30 (product owner): build both now** (see DEC-09 increment 2). |

### DEC-09 increment 2 (2026-10-30, product owner)
Asked and answered in the D4 review. Full design: [journeys.md](journeys.md) §5.

| Point | Decision |
|---|---|
| Renewal and exit journeys | **Build both now**, before release, as linked journeys like onboarding. |
| Last contract ended | **Automatic deactivation.** The system deactivates a volunteer whose last contract has ended (this revokes their access and disables their card, as manual deactivation does). Implementation default, reversible: a **7-day grace period** after the end date, with one heads-up to city HR at the end date, so a renewal entered a few days late does not cut someone off. |
| Where unfinished journeys show | In HR's **work inbox** (onboarding, renewal and exit steps that wait on HR, with the Late tag). |

## DEC-10 — Usage telemetry (settled 2026-10-16)
Yes, anonymous: no names or personal data, stored on our own server only, deleted after 90 days.
