---
version: 1
slug: "re-recruitment-indicators-rules-blade-php-08f2f913"
primary_target: "resources/views/livewire/recruitment/indicators/rules.blade.php"
related_targets: []
---

## Scope
Matching rules editor (`recruitment.indicator-rules`). Mode: Operate. Inside the established HopeHR world (DESIGN.md unchanged).

## Audience & job
HR officers who edit the rules, and managers (projects / fundraising) who need to understand them at a glance. Both must see how an applicant's form answers AND their uploaded CV turn into Met / Partly / Not met, and try the rules before saving.

## Constraints
Tools inform, humans decide. Everything computed on the client from the unsaved values (weak connections); save is the only round trip. EN/AR mirrored. Outcome hues come from RecruitmentIndicatorOutcome::classes().

## Direction contract
THESIS: Show every outcome at once instead of one field at a time: a truth table per criterion, recoloured live as the rules change. Refuses the stacked-settings-form default.
OWN-WORLD: Canvas grey, paper surfaces, hairlines; outcome cells in the enum's green/amber/red/neutral tones; Hope Red only on Save, focus and the required-years marker; tabular numerals.
STORY: The reader learns which sources feed each criterion (form, CV, record), sees how every experience answer and every CV reading lands for 0–10 required years, and tests the qualification words on real text before saving a new criteria version.
FIRST VIEWPORT: Title with the version and state, and the page intro naming the three sources; the criteria table with switches, source columns, a per-criterion source description, what each criterion can show, and a live "count toward the summary" figure. At 1440x900 about three and a half criteria rows are in view; the experience matrix (range inputs in its row headers, 0-10 axis, CV row) follows on the next screen.
FORM: Truth table / outcome matrix, position 4 of 6 on the ranked list, seed key 853a52ab.
FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

## Decisions
- 2026-09-30: the user locked the "truth-table" (outcome matrix) card on the structure decision page (question key 7f529bfe, seed 853a52ab, code-led), over "flow-board" (dealt lead) and "instrument-bench".
- 2026-09-30: asked whether to hide the per-criterion source descriptions so all six criteria and the top of the experience matrix fit at 1440x900; the user chose to keep the descriptions visible. The FIRST VIEWPORT block above records that answer.
