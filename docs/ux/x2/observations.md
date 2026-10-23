# X2 · Observations: template and record (anonymized)

Part of [usability-baseline.md](../usability-baseline.md). Task X2 (R-15).

> **Record status (2026-10-19): PENDING USER RESEARCH. No participant sessions have been run.**
> The tables in §4 are intentionally empty. Every figure that will appear here must come from a real, moderated session with the scripts in [task-scripts.md](task-scripts.md) v1.0. Nothing in this file is, or may be filled with, an estimate, a simulation or an example presented as data.

## 1. Privacy and anonymization rules

1. **Participant code only.** Use `P01`, `P02`, …, assigned in session order. The key that maps codes to people is kept **outside the repository** by the UX researcher, readable only by them, and destroyed when X2-R is complete.
2. **No names, emails, usernames, employee ids, phone numbers or photos** of participants anywhere in the repository.
3. **City:** record the city slug (SYR/ALP/HMS/DMS/LAT/TAR) **only if at least two participants share that role and city**. Otherwise write `other`. Small offices make role + city identifying.
4. **Synthetic data only.** Participants work on synthetic personas. Never copy pay figures, even synthetic ones, into this record. Record only whether the end-state check passed.
5. **Free text** (comments, comprehension answers): paraphrase, and remove anything that identifies a person, a colleague or a real case. Quotes are short and anonymized.
6. **Recordings** (screen/audio) only with explicit consent (§2). They are stored outside the repository on an access-controlled location, with **no** cloud transcription service. They are deleted when X2-R's analysis is done, or at 12 months, whichever comes first. *(Proposed default. The HR product owner and privacy owner confirm it before the first session.)*
7. Participation is voluntary and **is not an evaluation of the person**. Results are never reported per named individual, and never to the participant's manager.

## 2. Consent script (read before the session)

**EN:**
> Thank you for helping. We are testing the HR system, not you. There are no wrong answers. If something is hard, that is the system's problem, and it is exactly what we need to learn. You will use a test copy of the system with made-up people. Nothing you do changes real records. I will time the tasks and take notes without your name. With your permission I will also record the screen. The recording stays inside our team and is deleted after the analysis. You can stop at any time without giving a reason. Do you agree to take part? Do you agree to screen recording?

**AR:**
> شكرًا لمساعدتك. نحن نختبر نظام الموارد البشرية، وليس أنت. لا توجد إجابات خاطئة؛ إذا كان شيء ما صعبًا فهذه مشكلة النظام، وهذا تمامًا ما نحتاج إلى معرفته. ستستخدم نسخة تجريبية من النظام فيها أشخاص وهميون، ولن يغيّر أي شيء تفعله السجلات الحقيقية. سأقيس وقت المهام وأدوّن ملاحظات دون ذكر اسمك. وبإذنك سأسجّل الشاشة أيضًا؛ يبقى التسجيل داخل فريقنا ويُحذف بعد التحليل. يمكنك التوقف في أي وقت دون ذكر السبب. هل توافق على المشاركة؟ هل توافق على تسجيل الشاشة؟

## 3. Session sheet (one per participant; copy this block)

```text
Participant: P__        Date: ____-__-__     Moderator: (role only, e.g. "UX researcher")
Role in session: employee | manager | HR | general-manager      City: SYR/ALP/HMS/DMS/LAT/TAR/other
Interface language: EN | AR        Device profile: desktop (W×H ____) | phone 375×812
Browser/OS: ______________         Target: build/snapshot id ________   App SHA: ________
Scripts version: 1.0               Consent: participate Y/N   recording Y/N
Task order: __ __ __ __
Prior HC-HR use: none | <1 month | 1–6 months | >6 months   (self-reported)

Per task:
  Task: A | B | C | D | B′ | D′
  Start (mm:ss in recording or clock): ____   Stop: ____   Time on task (s): ____   Timing source: recording | stopwatch
  Stop reason: stop point | said done | gave up | cap
  Outcome: S | SA | F | A
  Help: H1 x_  H2 x_  H3 x_   (what was hinted: ________)
  Errors: E-NAV x_  E-VAL x_ (fields: ____)  E-LOSS x_ (what: ____)  E-DUP x_  E-SYS x_
          task-specific codes: ________
  End-state check: 1 ☐ 2 ☐ 3 ☐ 4 ☐ 5 ☐
  SEQ (1–7): __
  Comprehension answer (paraphrased, anonymized): ________   Matches current behaviour: Y | N | partly
  Notable observation (one line, anonymized): ________

After last task:
  SUS form language: EN | AR (unvalidated v1.0) | AR (validated A-SUS)
  SUS items 1–10: _ _ _ _ _ _ _ _ _ _     SUS score: ____ (or "incomplete")
```

The same fields as a flat file, for analysis: [observations-template.csv](observations-template.csv), one row per participant × task.

## 4. Record

### 4.1 Sessions

| Participant | Date | Role | City | Language | Device | SHA | Scripts | Tasks done |
|---|---|---|---|---|---|---|---|---|
| *(none yet: pending user research)* | | | | | | | | |

### 4.2 Task attempts

| Participant | Task | Outcome | Time (s) | Stop reason | Help (H2/H3) | Errors | SEQ | Comprehension matches |
|---|---|---|---|---|---|---|---|---|
| *(none yet: pending user research)* | | | | | | | | |

### 4.3 SUS

| Participant | Form language | Items 1–10 | Score |
|---|---|---|---|
| *(none yet: pending user research)* | | | |

### 4.4 Summary per task (fill only from §4.2)

For each task, by language and by device. Completion is unassisted successes, shown as `S / attempts`. Time is the median and min–max over **S only**. The table also lists SA, F and A counts, the total errors by code, the median SEQ, and how many comprehension answers matched.

| Task | n attempts | S | SA | F | A | Median time S (min–max) | Errors | Median SEQ |
|---|---|---|---|---|---|---|---|---|
| A · Request leave | 0 | — | — | — | — | — | — | — |
| B · Find an employee | 0 | — | — | — | — | — | — | — |
| C · Review a request | 0 | — | — | — | — | — | — | — |
| D · Create a contract | 0 | — | — | — | — | — | — | — |

"—" means **not measured**. It does not mean zero.
