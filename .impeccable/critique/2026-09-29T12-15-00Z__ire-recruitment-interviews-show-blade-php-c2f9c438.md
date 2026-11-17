---
target: interview page
total_score: 26
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
target_identity: "file:C:\\laragon\\www\\hc-hr\\resources\\views\\livewire\\recruitment\\interviews\\show.blade.php"
target_fingerprint: "sha256:47ad53cf7d50b971837b916c21a6460d3a738525c37edc8443a7dc2219b8c65b"
target_path: "C:\\laragon\\www\\hc-hr\\resources\\views\\livewire\\recruitment\\interviews\\show.blade.php"
timestamp: 2026-09-29T12-15-00Z
slug: ire-recruitment-interviews-show-blade-php-c2f9c438
---
Method: dual-agent (design review + detector). Source-only; login-gated, no browser tool.

## Design Health Score: 26/40 (Acceptable)

| # | Heuristic | Score | Key Issue |
|---|---|---|---|
| 1 | Visibility of System Status | 3 | No loading on panel open; toast-only results |
| 2 | Match System / Real World | 3 | "Mark as held" → "Completed"; "Version N" system talk |
| 3 | User Control and Freedom | 2 | "Cancel interview" beside "Cancel" (AR إلغاء المقابلة / إلغاء); Reload discards edits |
| 4 | Consistency and Standards | 2 | Hand-rolled buttons; two radio patterns; two confirm patterns; off-spec status badge |
| 5 | Error Prevention | 3 | Stale locks, time-gated outcomes; ambiguous destructive pair |
| 6 | Recognition Rather Than Recall | 3 | Labels/hints/history in place |
| 7 | Flexibility and Efficiency | 1 | No shortcuts, no jump to my evaluation, form 4th block |
| 8 | Aesthetic and Minimalist Design | 3 | Duplicated date line, doubled advisory copy |
| 9 | Error Recovery | 3 | Evaluation stale Reload excellent; interview error above card, no button |
| 10 | Help and Documentation | 3 | Thorough inline hints |

## Design Specificity Verdict
Behaviour authored (gated meeting link with explanation, internal-only notes, email contents listed, advisory evaluations, timezone shown). Layout generic stacked cards, same order for every viewer. Detector: 0 findings, also 0 with --no-config; blind to enum-supplied status/recommendation classes (bg-red-50 pink tints).

## Priority Issues
1. [P1] Destructive confirm beside identical-sounding dismiss, show.blade.php:154-155; dismiss unstyled, no focus ring. Fix: "Keep interview"/"الإبقاء على المقابلة" as x-secondary-button; same at :169 and summary back. → clarify
2. [P1] Action bar has no hierarchy, spends red (Edit outline, Cancel trigger text), ~32px targets, no focus-visible on :109/:112/:116. Fix: "Mark as held" primary when started, others secondary, min-h-11 sm:min-h-9. → quieter + polish
3. [P1] Inline panels silent: no focus move, no aria-expanded/controls, @error('interview') above card (show:19); same in summary. Fix: focusable panel heading, aria-expanded, in-panel error, focus return. → harden
4. [P2] Interviewer phone path buried and fragile: form 4th, wire:model.live radios (eval-form:46), no draft, Reload discards, toast + "Version 1" only. Fix: "evaluation due" anchor, CSS has-[:checked], localStorage draft, "Submitted 14:20 · editable until HR closes". → adapt + clarify
5. [P2] Contrast/RTL: removed participant text-secondary-soft ~1.4:1 (show:183); &rarr; unmirrored (show:220); Y-m-d H:i Latin digits x5; ltr:ml-1 rtl:mr-1 (eval-form:39). → harden

## Cognitive Load
Moderate (3/8 failed: single focus, hierarchy, minimal choices). Cancel reason 6 options; action bar 4.

## Persona Red Flags
- Alex: no shortcuts/skip link, missing focus rings, no focus move, round-trips.
- Sam: unannounced panels, toast-only confirmation, 1.4:1 removed rows, borderline withdrawn pill.
- Interviewer on phone: form buried, per-tap round-trips, no draft, Reload loses edits, easy-to-miss toast, raw meeting URL.

## Minor Observations
Hand-rolled pills; bg-red-50 on No-show/No; Close evaluation default tone; strong_yes/strong_no residue; redundant reschedule count; withdrawn pill full colour; no hint when outcome buttons appear.

## Questions to Consider
- Reorder by viewer and time?
- Recommendation first and required despite "never the decision"?
- Cancellation via shared danger modal for consistency?
