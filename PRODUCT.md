# Product
<!-- impeccable:product-schema 1 -->

## Platform
web

## Users
**Primary: city HR officers.** HR staff in each city office (HR-HMS, HR-ALP, HR-DMS, HR-LAT, HR-TAR, …) keep their city's employee records, contracts, leave and recruitment moving day to day. Headquarters HR (HR-SYR) does the same work across every city.

Other audiences, secondary to the HR officer:
- **Managers:** raise recruitment requests within their own team tree, approve leave, and take part in interviews.
- **Leadership and administrators:** the general manager and super-admin oversee all cities and manage access.
- **Candidates:** the public reach the careers site to apply for vacancies. It is a separate public surface, not the staff portal.

## Product Purpose
HopeHR is the internal human resources portal of Christian Hope Center, a Syrian NGO working across several cities. It keeps one authoritative record of the people the organisation employs or engages and the work around them:
- employees and their documents
- contracts and pay
- positions, centers and programs
- leave balances and requests
- badges and digital ID cards
- recruitment from request, through campaign, application, interview and offer, to hire

Success means each city's HR can do this work without spreadsheets, and every figure and decision is traceable to who made it.

## Positioning
HopeHR is built around how Christian Hope Center is actually organised:
- city-scoped roles, where a governorate's HR also covers the cities assigned to it
- positions with fixed capacity
- a recruitment workflow where requests follow the organisation's own approval chain
- two working languages as equals

It is not a generic HRIS configured for the NGO.

## Operating Context
- **Offices:** staff work from city offices in Syria, often on slow or unstable connections.
- **Language:** staff switch freely between Arabic (right-to-left) and English. Neither language is secondary, and every screen, message, email and PDF must be complete in both.
- **Documents:** printed contract PDFs, printed ID cards, and Excel imports and exports are part of the daily work.
- **Workflow:** recruitment decisions are recorded with who, when and why, and candidates are notified by email.

## Capabilities and Constraints
- Stack: Laravel 13, Livewire 4 (with Volt and Flux), Tailwind CSS 4, MySQL, served from Laragon locally. URLs carry the locale (`/en/…`, `/ar/…`).
- Access is role- and city-scoped (Spatie Permission). A record outside a user's scope is refused as not found, which does not reveal that it exists.
- Pay figures are encrypted at rest under their own key. Roles that may not see pay never receive it in the browser, and super-admin is deliberately blind to salaries.
- Candidate files are quarantined until scanned, and downloads are authorised per file.
- Terminology follows the code and the Arabic documentation in `docs/recruitment/`: request, campaign, applicant, application, shortlist, interview, evaluation, offer, hire.

## Brand Commitments
- Name: **HopeHR** — Christian Hope Center.
- Brand primary red `#c2151c`. Never tint it down for fills or selection, because a pale brand red reads as pink, which this UI does not allow.
- Fonts: SomarSans, with Lato as fallback.
- The sidebar logo is the mark used in emails.

## Evidence on Hand
- An internal introduction deck: `docs/HopeHR-جلسة-تعريفية.pptx`.
- Recruitment decisions, state machine, permissions and open questions: `docs/recruitment/`.
- There are no public testimonials, metrics or case studies. Do not invent any.

## Product Principles
1. **Tools inform, humans decide.** Indicators, CV reading and evaluation tallies are reading aids that always show what they are based on. No screen ranks, filters out or rejects a person automatically.
2. **Scope and privacy first.** People see only what their role and city allow. Sensitive data (pay, candidate files, sensitive notes) is withheld from the browser rather than hidden in it.
3. **Works on weak connections.** Pages stay light, avoid needless round trips, and remain usable when the network is slow.
4. **Two languages as equals.** Arabic right-to-left and English left-to-right are both first-class, including direction-sensitive glyphs, numbers and dates.

## Accessibility & Inclusion
WCAG 2.1 AA is a requirement. That covers keyboard operation with visible focus and focus that follows inline panels, labels and errors linked to their fields for screen readers, and AA contrast for all text, including small status chips.
