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

### Implementation gap found
- `cities` has no weekend field, so the governorate fallback for volunteers without a center needs a new column (or equivalent) before A3.

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

## DEC-01 / DEC-02 — Directory and public card (settled 2026-10-16)
| Point | Decision |
|---|---|
| Public QR card contents | **Unchanged**: name, photo, position, center, city, phone, Telegram, LinkedIn, emergency number. |
| Card of a departed volunteer / lost card | Shows only "card is not valid", with no personal information. |
| Re-issued QR (replacement card) | The old printed card stops working **immediately**. |
| In-app directory (non-HR users) | Visible across **all governorates** (name, photo, position, work contact). |

## DEC-06 — Team calendar (settled 2026-10-16)
| Point | Decision |
|---|---|
| "My team" | Both views: same project/program **and** same direct manager (user can switch between them). |
| What colleagues see | The leave **with its type**. |
| Coverage | Advisory warning to the manager only; never blocks a request. |

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

## DEC-10 — Usage telemetry (settled 2026-10-16)
Yes, anonymous: no names or personal data, stored on our own server only, deleted after 90 days.
