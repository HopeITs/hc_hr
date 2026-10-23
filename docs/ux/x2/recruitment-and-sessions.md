# X2 · Recruitment and session logistics (proposed)

Part of [usability-baseline.md](../usability-baseline.md). Task X2 (R-15).
Status: **prepared, not used.** Nobody has been contacted, screened or scheduled. Recruiting is an action for the UX researcher **with HR's approval**; this file only prepares it.

## 1. Participant screener

Asked by the UX researcher, in the person's preferred language, before scheduling. Answers are kept **outside the repository** with the participant key ([observations.md §1](observations.md)). Only the cell (role × language × city) is recorded here.

| # | Question (EN) | السؤال (AR) | Use |
|---|---|---|---|
| S1 | Which of these best describes your work in HC-HR today: I request my own leave only · I approve leave for people in my team · I work in HR (employee records, contracts) · other? | أيّ مما يلي يصف عملك في نظام HC-HR اليوم: أطلب إجازاتي فقط · أوافق على إجازات أشخاص في فريقي · أعمل في الموارد البشرية (سجلات الموظفين، العقود) · غير ذلك؟ | Role cell. "Other" → not eligible for the baseline. |
| S2 | In which city is your main office? | في أي مدينة يقع مكتبك الرئيسي؟ | City cell. |
| S3 | Do you usually use the system in Arabic or in English? | هل تستخدم النظام عادةً بالعربية أم بالإنجليزية؟ | Language cell. The session uses this language. |
| S4 | How long have you used HC-HR? none · less than 1 month · 1–6 months · more than 6 months | منذ متى تستخدم نظام HC-HR؟ لا أستخدمه · أقل من شهر · ١–٦ أشهر · أكثر من ٦ أشهر | Recorded as `prior_use`. Aim for a mix; not a filter. |
| S5 | Have you worked on designing, building or testing HC-HR? | هل شاركت في تصميم نظام HC-HR أو تطويره أو اختباره؟ | **Yes → exclude** (IT, developers, this project's reviewers). |
| S6 | Can you spare about 45 minutes on a computer, in person or by screen share? | هل يمكنك تخصيص نحو ٤٥ دقيقة على حاسوب، حضوريًا أو عبر مشاركة الشاشة؟ | Logistics. |
| S7 | Do you need anything to take part comfortably (larger text, a screen reader, a break, a particular time)? | هل تحتاج إلى أي شيء لتشارك براحة (نص أكبر، قارئ شاشة، استراحة، وقت معيّن)؟ | Accommodation. Assistive-tech use is noted for A5; it does not exclude. |

**Exclusions:** S5 = yes; the participant's own manager or an HR colleague who reviews them would moderate or observe; anyone who has seen these scripts.
**Not asked:** age, gender, religion, salary, health. They are not needed for X2.

## 2. Recruitment quota (role × language × city)

Session cities are those prepared in [test-environment.md](test-environment.md): **ALP** and **HMS**. A participant from another city can still take part (the data is synthetic); record the real city cell, and write `other` when the anonymization rule requires it.

| Role | Language | ALP min | HMS min | Preferred total for the row |
|---|---|---|---|---|
| Employee | EN | 1 | 0 | 2 |
| Employee | AR | 0 | 1 | 3 |
| Manager | EN | 0 | 1 | 2 |
| Manager | AR | 1 | 0 | 3 |
| HR officer | EN | 1 | 0 | 2 |
| HR officer | AR | 0 | 1 | 3 |
| **Total** | | **3** | **3** | **15** |

- **Minimum to report a baseline: 6** (each role × language cell once, each role spread across both cities). The minimum cells are staggered so each city has every role.
- **Preferred: 15** (about 5 per role), split as evenly as possible between ALP and HMS. Arabic is weighted higher on the assumption that most staff use Arabic; **the UX researcher adjusts this to HR's real language mix** (not known to X2).
- A cell that cannot be filled is recorded as a limitation, not filled with a different role.
- With small offices, role + city can identify a person: apply [observations.md §1](observations.md) rule 3 when recording.

## 3. Session run order (counterbalancing)

Each participant does only the tasks their role can do today ([task-scripts §0.7](task-scripts.md)). Orders are assigned in session order within each role, cycling through the rows.

**HR officer: A, B, C, D (balanced 4 × 4 Latin square, Williams design).** Each task appears once in each position, and each task directly follows every other task once.

| Order | 1st | 2nd | 3rd | 4th |
|---|---|---|---|---|
| H1 | A | B | D | C |
| H2 | B | C | A | D |
| H3 | C | D | B | A |
| H4 | D | A | C | B |

**Manager: A, C.** M1 = A then C · M2 = C then A.
**Employee: A only.** No order needed.
**Optional variants** (B′, D′) always come **after** the core tasks, so they cannot teach the core task first.

With fewer than 4 HR participants the square is incomplete; record which rows were used. Language is between-participant (one per person), never switched inside a session.

## 4. Facilitator guide (about 45 minutes)

**Roles:** one moderator (runs the session) and, if possible, one note-taker (timer, error codes). Neither may be the participant's manager.

| Time | Step | What the moderator does |
|---|---|---|
| −30 min | Prepare | Reset the target ([test-environment §3](test-environment.md) step 5). Fill the scenario cards for this participant's city and language from the manifest. Sign in as the persona in the participant's language (`/en` or `/ar`). Open the dashboard. Prepare the recorder but **do not start it before consent**. Write the app SHA and order row on the session sheet. |
| 0–5 | Welcome and consent | Read the consent script ([observations §2](observations.md)) word for word. Two separate yes/no answers: taking part, screen recording. No consent → thank them and stop. Recording consent → start the recorder now; no recording consent → stopwatch timing only. |
| 5–8 | Warm-up | "Tell me briefly how you use HC-HR in your work." Do not demonstrate the system. Ask them to think aloud. |
| 8–38 | Tasks | For each task in the assigned order: hand over the card, let them read it aloud, confirm understanding, say "Start" / «ابدأ». Time from first input ([task-scripts §0.2](task-scripts.md)). Stay neutral; use H1 prompts only ("What would you do next?"). Give H2/H3 only if they are stuck for about a minute and want to continue, and record it. Stop at the stop point, "done", give-up or cap. Then SEQ, then the comprehension question. Return to the dashboard before the next task, without explaining. |
| 38–42 | SUS | Hand over the SUS form in their interface language. Arabic form: the "unvalidated" label stays on it ([sus-method §1.2](sus-method.md)). Do not explain items. |
| 42–45 | Debrief | "What was the hardest part? Is there anything you expected and did not find?" Thank them. Remind them nothing real changed. |
| After | Close | Check each end state in the session database (UI or SQL) and fill the end-state checks. Copy only anonymized fields into the record. Store the recording outside the repository. Reset the target. |

**Do:** stay neutral, accept silence, note exact error moments, keep the time cap.
**Do not:** say whether an answer is right, explain the UI, point, say "click", change a card's wording during the session, or talk about the participant afterwards with their manager.
**Stop the session** if the participant is distressed, if the session target shows anything that looks like real data (report it, stop the round), or on any system failure that blocks all tasks (record E-SYS).

## 5. Pilot-run checklist (one session, not counted)

Run once with a colleague who meets the screener but is not in the sample, ideally one per interface language.

| # | Check | Pass when |
|---|---|---|
| P1 | Target builds and resets | Steps 1–5 of [test-environment §3](test-environment.md) run without error; the reset restores the target request to Pending and the Task D employee to no contract. |
| P2 | Isolation | The server log and `perf_assert_isolated()` show SQLite in `X2_HOME`; no mail leaves (`MAIL_MAILER=array`). |
| P3 | Each persona can follow its expected path | In EN **and** AR: staff → Task A; manager → Task C sees the target among other pending rows; HR → B, C, D, D′. |
| P4 | Cards match the data | Every name, date, position and center on the filled cards exists exactly so in the session data; Arabic names render correctly. |
| P5 | End-state checks are doable | The moderator can confirm each check in under 2 minutes after a task. |
| P6 | Timing works | Recording captures the first input and the stop event; recording and stopwatch agree within 2 s. |
| P7 | Caps and wording | No card is misunderstood; caps are neither always hit nor trivially loose. Any change → new scripts version in [task-scripts change control](task-scripts.md#change-control) **before** the first counted session. |
| P8 | SUS/SEQ forms | Both language forms print or display correctly; the "unvalidated" label is visible on the Arabic form. |
| P9 | Session length | Whole session fits in about 45 minutes for an HR participant (4 tasks). |
| P10 | Data handling | Session sheet, CSV row and recording storage follow [observations §1](observations.md); nothing identifying lands in the repository. |
| P11 | SHA | The SHA served matches the one written in the session sheet, and still matches [usability-baseline §1](../usability-baseline.md) or the difference is recorded. |

The pilot's own results are **not** entered in the observation record.
