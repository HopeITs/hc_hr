# X2 · SUS and post-task ease: method and scoring (proposed)

Part of [usability-baseline.md](../usability-baseline.md). Task X2 (R-15). Status: **ready, awaiting review**. No responses have been collected.

## 1. System Usability Scale (SUS): what is used

- **Instrument:** the standard 10-item SUS, unchanged in number, order and polarity of items, on a 5-point agreement scale.
- **Source:** J. Brooke, "SUS: A 'quick and dirty' usability scale", in P. W. Jordan et al. (eds.), *Usability Evaluation in Industry*, Taylor & Francis, 1996, pp. 189–194. Brooke released SUS for free use with acknowledgement of the source.
- **When:** once per participant, **after their last task**, before any debrief discussion. Participants answer about the system as they used it in the session.
- **Adaptation:** the word "system" is kept. It is not replaced by "HC-HR", so the English form stays the published one.

### 1.1 English items (standard wording)

Scale: 1 = Strongly disagree · 2 · 3 · 4 · 5 = Strongly agree

1. I think that I would like to use this system frequently.
2. I found the system unnecessarily complex.
3. I thought the system was easy to use.
4. I think that I would need the support of a technical person to be able to use this system.
5. I found the various functions in this system were well integrated.
6. I thought there was too much inconsistency in this system.
7. I would imagine that most people would learn to use this system very quickly.
8. I found the system very cumbersome to use.
9. I felt very confident using the system.
10. I needed to learn a lot of things before I could get going with this system.

### 1.2 Arabic items: **UNVALIDATED working translation**

> **Label (must appear on every Arabic form and in every report that uses it):** *Unvalidated Arabic translation prepared for HC-HR X2 on 2026-10-19. It is not the published A-SUS. Scores from it are not directly comparable to published SUS benchmarks or to Arabic studies using a validated version.*

A **validated** Arabic SUS exists: B. A. AlGhannam, S. A. Albustan, A. A. Al-Hassan, L. A. Albustan, "Towards a Standard Arabic System Usability Scale: Psychometric Evaluation using Communication Disorder App", *International Journal of Human–Computer Interaction* 34(9), 2018, pp. 799–804. Its exact item wording was **not available** to this task, so it is not reproduced here and nothing below should be presented as that version.

**Search for the validated wording (2026-10-19), result: not verified.**

| Source tried | Result |
|---|---|
| Publisher record, *IJHCI* 34(9) (Taylor & Francis) | Paywalled; item text not accessible. |
| ResearchGate entry of the paper (publication 321893814) | HTTP 403; no text read. |
| AlGhannam et al., "Perceived Usability of Arabic System Usability Scale (A-SUS): Faculty Members' Perspective of Smart PAAET Application", *IJCA* 183(37), 2021 (open page) | Cites the 2018 paper; the page does not reproduce the Arabic items. |

Wording seen in any secondary or unofficial copy is **not** used: only the paper itself, or the authors, count as a legitimate source. So the working translation below stays, labelled unvalidated.

**Recommended action before the first Arabic session:** the UX researcher obtains the published A-SUS wording (library access to the *IJHCI* paper, or a request to the authors), and replaces §1.2 with it, citing the paper and removing the "unvalidated" label. Until that is done, use the text below with the label, after the reviewer checklist in §1.3.

المقياس: 1 = لا أوافق بشدة · 2 · 3 · 4 · 5 = أوافق بشدة

1. أعتقد أنني سأرغب في استخدام هذا النظام بشكل متكرر.
2. وجدت النظام معقدًا بلا داعٍ.
3. اعتقدت أن النظام سهل الاستخدام.
4. أعتقد أنني سأحتاج إلى مساعدة شخص تقني لأتمكن من استخدام هذا النظام.
5. وجدت أن الوظائف المختلفة في هذا النظام متكاملة بشكل جيد.
6. اعتقدت أن في هذا النظام قدرًا كبيرًا من عدم الاتساق.
7. أتصور أن معظم الناس سيتعلمون استخدام هذا النظام بسرعة كبيرة.
8. وجدت النظام مرهقًا جدًا في الاستخدام.
9. شعرت بثقة كبيرة أثناء استخدام النظام.
10. احتجت إلى تعلم أشياء كثيرة قبل أن أتمكن من البدء باستخدام هذا النظام.

Translation process used: a single forward translation by the X2 author. There was no back-translation, no bilingual review and no pilot. Before results are reported, at minimum one bilingual reviewer (the A4 Arabic content reviewer is a natural choice) should check it, and any change should be versioned in §5.

### 1.3 Checklist for the Arabic content reviewer

Passing this checklist makes the translation **reviewed**, not **validated**. The "unvalidated" label stays until the published A-SUS replaces it.

1. **Meaning:** each item says the same as the English item in §1.1, with nothing added or dropped (e.g. item 4 "technical person", item 5 "well integrated", item 8 "cumbersome").
2. **Polarity kept:** items 1, 3, 5, 7, 9 stay positive and 2, 4, 6, 8, 10 stay negative. No item is reworded into its opposite (this would break the scoring in §2).
3. **Order and count:** ten items, same order as §1.1.
4. **Register:** clear Modern Standard Arabic that staff in all cities understand; no dialect, no rare words.
5. **Gender-neutral address** (or a consistent convention) on the form, the scale labels and the SEQ.
6. **Scale labels:** 1 = «لا أوافق بشدة», 5 = «أوافق بشدة» read naturally, and the scale runs in the same direction as the English form when shown right-to-left.
7. **"System"** stays «النظام», not the product name (matches §1, Adaptation).
8. **SEQ (§3):** the Arabic question and its end labels (صعبة جدًا / سهلة جدًا) match the English meaning and direction.
9. **Scenario cards and consent script** (AR) in [task-scripts.md](task-scripts.md) and [observations.md §2](observations.md): same check for meaning and register.
10. **Record:** reviewer role (not name), date, and every change as a new version in §5. Optional but preferred: an independent back-translation to English, compared with §1.1.

## 2. Scoring (standard, Brooke 1996)

For each participant with **all 10 items answered**:

1. Odd items (1, 3, 5, 7, 9): contribution = response − 1.
2. Even items (2, 4, 6, 8, 10): contribution = 5 − response.
3. SUS score = (sum of the 10 contributions) × 2.5, which gives a range of 0–100.

**Worked synthetic example** (an illustration of arithmetic only, not a participant):
Responses 4, 2, 4, 1, 3, 2, 5, 2, 4, 3 →
odd contributions 3 + 3 + 2 + 4 + 3 = 15; even contributions 3 + 4 + 3 + 3 + 2 = 15 → (15 + 15) × 2.5 = **75.0**.

Rules:
- **Missing item:** the questionnaire is **excluded** from the SUS figures and counted as "incomplete". No imputation is done.
- A SUS score is **not a percentage**, and no single score is "a pass".
- **Reporting:** n, median, min–max, and mean ± SD **only if n ≥ 5**. Report scores **split by questionnaire language**, because the Arabic form is unvalidated. Pool the two languages only after the Arabic form is replaced by the validated A-SUS, and then say so.
- **Benchmarks:** published SUS norms (e.g. "~68 is average") may be quoted for context on English forms only. They must not be applied to the unvalidated Arabic form.
- **No statistical claims** from small samples. Report the sample size and its limits instead (see [usability-baseline.md](../usability-baseline.md) §7).

## 3. Post-task ease: Single Ease Question (SEQ)

Asked right after each task's stop point, before the comprehension question.

- **EN:** "Overall, how difficult or easy was this task to complete?" 1 = Very difficult … 7 = Very easy.
- **AR (unvalidated working translation, same label as §1.2):** «بشكل عام، ما مدى صعوبة أو سهولة إتمام هذه المهمة؟» 1 = صعبة جدًا … 7 = سهلة جدًا.
- Source: J. Sauro & J. S. Dumas, "Comparison of three one-question, post-task usability questionnaires", *CHI 2009*.
- Asked for **every** attempt, including failures and abandonments. Report the median and min–max per task, with the S/F/A outcome next to it.

## 4. Data handling

- Paper or form responses are stored **outside the repository**, linked only to the participant code (`P01`…), with the same access and retention rules as the observations ([observations.md](observations.md) §1).
- Only the per-participant **item responses and computed score** are entered in the observation record, under the participant code.

## 5. Versions

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-19 | First version. English standard SUS. Arabic working translation, **unvalidated**. |
| 1.0 (doc only) | 2026-10-19 | Recorded the A-SUS source search (§1.2) and added the reviewer checklist (§1.3). Item wording unchanged. |
