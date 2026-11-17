---
target: applicants/show
total_score: 24
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 3
target_identity: "file:C:\\laragon\\www\\hc-hr\\resources\\views\\livewire\\recruitment\\applicants\\show.blade.php"
target_fingerprint: "sha256:3ae8b0efca0b6855c4fd2d99e83a1b33574fca1386a9999a84437d19bcae5d56"
target_path: "C:\\laragon\\www\\hc-hr\\resources\\views\\livewire\\recruitment\\applicants\\show.blade.php"
timestamp: 2026-09-29T12-05-50Z
slug: ire-recruitment-applicants-show-blade-php-9e16ce51
---
Method: dual-agent (design review + detector). Source-only; page is login-gated, no browser tool.

## Design Health Score: 24/40 (Acceptable)

| # | Heuristic | Score | Key Issue |
|---|---|---|---|
| 1 | Visibility of System Status | 3 | No loading state on decision buttons; toast "Status changed." names nothing |
| 2 | Match System / Real World | 3 | "Accept" button → Selected; "Accepted" also an offer state |
| 3 | User Control and Freedom | 3 | Cancel restores focus; rejection reopenable with reason |
| 4 | Consistency and Standards | 1 | CV met/not-met identical; Hired red vs green enum; hand-rolled pills; native confirm vs shared modal |
| 5 | Error Prevention | 3 | Strong guards; apology email defaults on |
| 6 | Recognition Rather Than Recall | 2 | Table row lands on applicant not application; interview evidence below decision |
| 7 | Flexibility and Efficiency | 1 | No prev/next, no shortcuts, double confirm |
| 8 | Aesthetic and Minimalist Design | 2 | Three-deep boxes, ~10 red touches per article, repeated disclaimers |
| 9 | Error Recovery | 3 | Inline aria-described errors, service errors mapped to fields |
| 10 | Help and Documentation | 3 | Field hints good; "criteria version / snapshot outdated" unexplained |

## Design Specificity Verdict
Ethics and copy authored for this product; visual composition is a category-standard ATS template of nested hairline boxes, and it breaks DESIGN.md's Rationed Red and No-Pink rules. Detector: 0 findings (blind to enum-built classes and app.css).

## Priority Issues
1. [P1] Colour contradicts meaning: cv-analysis.blade.php:6-7 met and not_met share bg-primary/5 text-primary; Hired badge red in application-offer.blade.php vs green in RecruitmentApplicationStatus:63; tag hues overlap status hues. Fix: use RecruitmentIndicatorOutcome::classes(), status enum classes for Hired, restrict tag palette. → colorize
2. [P1] Decision buried and out of order: decisions after files/indicators/open CV/tags, interview tallies after decision; ApplicationTable:144 links to applicant not application. Fix: decision in article header or sticky rail, evidence order indicators→interviews→CV→files, collapse CV, deep-link #application-{id}. → layout
3. [P1] Double confirmation with generic native wire:confirm (show.blade.php:182) while x-recruitment.confirmation-modal exists. Fix: drop for benign moves; consequence summary for Reject/Accept. → clarify
4. [P2] Red spent everywhere: links, outline buttons, badges, pink panels (show.blade.php:132). Fix: ink links, neutral secondary buttons, red only on confirm, sunken-well panels. → quieter
5. [P2] Always-open 5-control tag creation per application, placeholder-as-label. Fix: single "+ Tag" combobox. → distill

## Cognitive Load
High (4/8 failed: single focus, chunking, hierarchy, minimal choices). >4-option points: tag toolbar (5 controls, 6 colours), rejection reasons (8), article action surface (~10). Inverted disclosure: CV open, indicators closed.

## Persona Red Flags
- Alex: no prev/next, three steps per move, no shortcuts, repeated tag toolbars.
- Sam: context-free decision button names; indicators summary loses marker; opacity-75 12px tag text contrast risk; 24px remove target.
- HR screener: lands on wrong application, misreads CV pills, pink panel + pre-ticked email, double confirm, vague toast, evaluations below decision.

## Minor Observations
Border token secondary-soft vs hairline; no shadow-e1; raw text-sm/xs; oversized status pill; Latin digits in /ar; no timezone label; no max width; wire:model.live on reason select; notify-on default policy.

## Questions to Consider
- Why two evidence systems with two colour languages?
- Applicant page or application page?
- What if rejection were the most careful moment in the product?
