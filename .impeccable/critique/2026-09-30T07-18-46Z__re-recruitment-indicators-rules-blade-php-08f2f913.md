---
target: matching rules
total_score: 23
max_score: 40
na_heuristics: 
p0_count: 1
p1_count: 2
target_identity: "file:C:\\laragon\\www\\hc-hr\\resources\\views\\livewire\\recruitment\\indicators\\rules.blade.php"
target_fingerprint: "sha256:83c02f869f96b9c954b3235686d19d24457de7a715b0f510b61f13d78b370c54"
target_path: "C:\\laragon\\www\\hc-hr\\resources\\views\\livewire\\recruitment\\indicators\\rules.blade.php"
timestamp: 2026-09-30T07-18-46Z
slug: re-recruitment-indicators-rules-blade-php-08f2f913
---
# Critique: Matching rules editor (dual-agent, source-only; browser unreachable)
Score 23/40 (Acceptable). H1 2, H2 3, H3 2, H4 3, H5 2, H6 2, H7 2, H8 3, H9 2, H10 2.
Detector: 0 findings (rules.blade.php, forms/input-label, primary-button).
P0 Save has no impact preview/diff/confirm (view :103, component :150).
P1 CLI artisan command in HR-facing copy (en lang :42).
P1 Errors not linked to fields (no aria-invalid/describedby, range errors only under Max :65, no focus-to-error).
P2 Range table allows gaps/overlaps (view :53-68).
P2 Keyword textareas: hidden comma syntax, no test box (view :91-99).
P3 Version/customised status nearly invisible (:5-8).
Also: Reset wipes all with native English wire:confirm, no loading guard (:104); range labels fall back to raw keys (:56); rule scope (global vs city) unstated.
