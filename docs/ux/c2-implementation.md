# C2 · Bilingual search and the New button

Task: C2 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-07, R-08) · Date: 2026-10-26 · Branch `hr-ux-by-rafi`
Inputs: C0 [interaction-contract.md](interaction-contract.md) §6 (HR decisions C0-Q3 *New*, C0-Q6 search); C1 [`App\Support\Navigation`](../../app/Support/Navigation.php); A2 [access-policy.md](access-policy.md) (Directory tier, `DirectoryAccess`).
Status: **built and tested; review pending.**

## 1. What was built

| Part | Behaviour |
|---|---|
| **Search box** ([`GlobalSearch`](../../app/Livewire/Layout/GlobalSearch.php)) | In the header for every role; **Ctrl/Cmd+K** focuses it. Debounced 300 ms; at least 2 letters; at most 6 pages and 8 people. On phones a search button opens it as a full-width panel under the header. |
| **Pages** | The pages of the **active role's menu** (C1 `Navigation::pages()`), matched on their name in **both languages**, whatever the interface language. Each shows its menu area. A page the role does not have in its menu is not offered. |
| **People** | Matched on `first_name_en`, `last_name_en`, `first_name_ar` and `last_name_ar` **explicitly** (not `transColumn()`), every word typed must match one of them. Arabic letters typed interchangeably are folded on both sides: أ/إ/آ/ٱ → ا, ى → ي, ة → ه, tatweel and diacritics dropped (so «احمد مصطفي» finds «أحمد مصطفى», «ساره» finds «سارة»). Each result shows the name, the name in the other script, the current position and the city, so namesakes can be told apart. A former employee is marked "(former)" for the roles allowed to see one. Opens the profile page, which checks the viewer's tier again. |
| **States** | Too short · searching (`wire:loading`, also announced) · N results · nothing found · search unavailable (server error, reported) · too many searches. Live region `role="status"`. |
| **Keyboard** | WAI-ARIA combobox: ↓/↑ move through results (`aria-activedescendant`), Enter opens, Escape clears then closes. |
| **New button** (`partials/new-menu`) | WAI-ARIA menu button. Items from `Navigation::newActions()`: **Leave request** (`leaves.create` + `leaves.view-own`) · **Leave request for an employee** (HR / super-admin, `leaves.create` + `leaves.view-all`, the same rule as All leaves' Add button) · **Employee** (`employees.create`) · **Contract** (`contracts.create` **and** `User::canViewSalary()`, so never the salary-blind super-admin) · **News item** (`news.create`). Each screen checks its permission again. |
| **Leave dialogs** | `?new=1` on My leaves / All leaves opens the new-request dialog once (`startNew`, then cleared from the URL). |
| **Return** | Each New link carries `?from=<current page>`. [`SafeReturn`](../../app/Support/SafeReturn.php) accepts it only as an internal path (`/…`, not `//host`, `\`, a scheme or control characters) whose route is in `Navigation::returnRoutes()` (the role's menu pages plus dashboard, my profile, notifications and the employee/contract/program/center record pages); anything else is ignored. The screen keeps it in a `#[Locked]` property (`RemembersReturnTo`). **Cancel returns there**: the contract composer's Cancel, the employee form's Cancel, the news form's Cancel, and closing an unsent leave dialog. **After a successful save the screen behaves as before** (the new record or the result panel), which is the authorized place to show the outcome. |

## 2. Security (AC-C2-02)

- **People:** `DirectoryAccess::constrain()` on every query (former employees only for the general manager, HR-SYR, super-admin and the regional HR of their city) plus the directory's row set (a visible position); **only `DirectoryAccess::COLUMNS` are selected**, so private (national id, personal contact, birthday) and financial fields are never loaded. Contracts load only `position_id`/`start_date` and the position title. The profile page re-checks the tier on open and after a role switch.
- **No cache:** results are worked out on every render from the term and the active role. Nothing is stored between requests, so a role switch cannot show the previous role's results ("clear result caches on role changes" holds by construction).
- **Throttle:** 60 searches per user per minute (`RateLimiter`, key per user id), then a "wait a minute" state with no results.
- **LIKE input:** `%`, `_` and `\` typed by the user are removed before matching, so "%%" lists nobody.
- **Livewire surface:** `GlobalSearch::updatedQ` and the `mountRemembersReturnTo` hooks are listed in `livewire_exempt` with the reason (Livewire refuses direct calls to `updated*`/`mount*`). `$returnTo` is `#[Locked]`.
- **Menu ≠ permission:** New and the page results only list what the role may open; every target checks again.
- **C0-Q4 answer 2 (2026-10-27):** page search follows the menu, so the reference pages left out of the employee and manager menus are not offered in their search either (`test_pages_come_from_the_active_roles_menu_in_both_languages`).

## 3. Tests

[`GlobalSearchTest`](../../tests/Feature/GlobalSearchTest.php) (10 tests):

| AC | Test |
|---|---|
| **AC-C2-01** English name in Arabic UI and vice versa | `test_a_name_in_either_script_finds_the_person_in_either_interface`; `test_arabic_spelling_variants_find_the_same_person_and_page`; `test_namesakes_show_their_city` |
| **AC-C2-02** No disallowed city/private data, including after a role switch | `test_results_never_include_former_employees_out_of_scope_or_private_fields` (employee, HR-ALP, HR-SYR; national id, personal phone/email and salary absent from HTML, snapshot and effects); `test_results_follow_the_active_role_after_a_switch`; `test_pages_come_from_the_active_roles_menu_in_both_languages`; `test_short_empty_wildcard_and_throttled_searches_have_safe_outcomes` |
| **AC-C2-03** Keyboard/New start the permitted workflow and restore context | `test_new_lists_only_what_the_active_role_may_start` (per role; super-admin has no Contract; the header renders each item and each opens for the role); `test_new_leave_request_opens_the_dialog_and_cancel_returns_to_the_origin` (and an external `from` is ignored); `test_safe_return_accepts_only_internal_allowlisted_paths` |

Full suite: 956 tests, 0 failures.

## 4. Browser checks (synthetic copy `hc-hr-gb12`, `manager.alp`, Arabic)

| Check | Size | Result |
|---|---|---|
| «سار» | desktop | 2 people: «سارة نجار · Sara Najjar · … · حلب», «سارة رزق · Sara Rizk · … · دمشق»; links to their profile pages |
| "Sara" (English) in the Arabic UI | desktop | the same two; ↓↓ selects the second (`aria-activedescendant` = option 1) |
| «إجاز» | desktop | 3 pages with their areas (Team leaves · فريقي, Leave types and Annual leave rules · المنظمة) |
| New | desktop | Opens with focus on the first item; items «طلب إجازة», «خبر» (a manager); Enter → `/ar/leaves?from=%2Far` with the request dialog open; Escape → back on `/ar` |
| Phone header | 375×667 | All items inside the screen (menu button at 323–367 px); search button opens the panel; «ساره» finds both «سارة» |

**Ctrl+K** is covered by the markup (`keydown.window.ctrl.k`); the browser pane used here did not pass the key combination to the page, so it was not confirmed in the browser. English was not opened in the browser (`/en` redirects on the `php -S` copy, G-B-12).

## 5. Changes made on the way

1. **Phone header:** with search and New added, the row needed about 392 px and the menu button was pushed off a 375 px screen (the M1 problem coming back). On phones the language switch now shows only the *other* language (one button instead of two); sm and up are unchanged.
2. **Search box width:** below `lg` the "Ctrl K" hint is hidden and the box keeps at least 11 rem; the results panel has its own width (up to 28 rem), not the input's.
3. **Arabic folding** was added after the browser check: «إجاز» did not find «اجازاتي».

## 6. Limits and handoff

- **Full suite:** 956 tests, 0 failures (final code, 2026-10-26).
- **Search ranking** is alphabetical by first name in the interface language; there is no relevance ranking or fuzzy matching beyond the Arabic folding. Names with stored diacritics are matched without them only on the typed side.
- **Contracts are not searchable** as records (C2's objective mentions contracts). People are, and a contract is opened from its employee or the Contracts list. Adding contract results needs the contract's own visibility scope (`Contract::visibleTo`) and pay-free columns; left for a follow-up rather than widening this task.
- **C3** will add the inbox page to `Navigation`, and so to page search and the return allowlist, automatically.
