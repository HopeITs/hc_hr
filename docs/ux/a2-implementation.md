# A2 · Profile and digital-ID access: implementation notes

Status: **implemented 2026-10-20, awaiting review** (QA engineer + technical lead). Not committed, not deployed, no migration run on any real database.
Task: A2 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-24). Policy: [access-policy.md](access-policy.md) §4.3–§4.4 and the §9 sign-off; [decisions.md](decisions.md) "DEC-02 revision — card link". DEC-01 and DEC-02 are settled, so A2 was no longer blocked.
Baseline: `main` @ `94d3b248`, 552 tests, 543 passed, 9 failed (= [test-baseline.md](test-baseline.md) §9).

## 1. What changed

### 1.1 In-app directory and profile (AC-A2-01, AC-A2-03)

| Item | Change |
|---|---|
| `app/Support/DirectoryAccess.php` (new) | The single place for the §4.3 matrix: `canSeeDirectory`, `canSeeRecords`, the query version `constrain()`, holder constraints, `isDirectReport` (direct line only; the technical manager no longer exists, DEC-05 revision), Directory-tier column lists. |
| `EmployeePolicy` | New `viewDirectory` (denies **as not-found**, so the avatar route answers 404 like an unknown id) and `viewRecords`. `viewAvatar` now follows `viewDirectory` (A2D-Q4, fixes X1's 134 broken photos). `view` (HR record) unchanged. |
| `ProfileDrawer` | Authorization restored on `openProfile()` and on every later request (`hydrate()` + render). Malformed, unknown and not-visible ids give the same 404; no `null` dereference. Lookups no longer city-scoped (A2D-Q1/Q4), holders filtered by the Directory rule. Directory columns only. |
| `EmployeeProfile` | `viewDirectory` in `mount()` and again in `hydrate()` (stale role after a switch); records per `viewRecords` (employee sees own, manager direct reports only); a Directory-only viewer gets no history, education, experience, courses, skills, relatives, pay columns or years of service loaded. Private and pay rules unchanged. |
| `Directory` + CSV | Same row set as the policy (staff/managers never get former employees, whatever the filter); CSV has Directory columns only. |
| `OrgChart`, dashboard team card | A former employee still on a running contract, or a former CEO/deputy pinned on the card, is no longer shown to staff. |
| `EmployeeEdit` | Contract-history tab uses `viewRecords` (kept in sync with the profile). |

### 1.2 Public digital-ID card (AC-A2-02)

| Item | Change |
|---|---|
| Route | `GET /ID/{card}`, constraint `[^/]{1,64}`, `throttle:30,1`, name `digital-id.show` (noindex headers kept). The old `where('token', …)` constraint that matched nothing is gone. |
| Link | `/ID/[city 3][program 3][employee id]-[index]` (DEC-02 revision). Built and checked by `DigitalIdManager` from **live** data: `cities.code` of the employee's city and `programs.code` of the current contract's position. Codes must be 1–3 digits, zero-padded; anything else gives **no link** (and the panel says why) rather than a code that could collide. |
| Validity | Only an active employee, an enabled card and the **current index** resolve; compared as a whole with `hash_equals`. Index 0 = cardboard; issuing plastic (→1) ends it; each re-issue ends the previous index immediately. A move to another city or program ends the old link automatically. |
| Not valid | One bilingual page (AR + EN), **404 for every invalid case** (unknown, old index, wrong codes, disabled, departed, old username link, malformed input), no personal data, does not echo the URL. |
| Signed photo | Still needs a valid signature; now checks `is_active && digital_id_enabled` (the obsolete token requirement removed). Minted only after the card resolved. |
| Card management (A2D-Q5) | New panel `employee-cards.manage-digital-id` on the profile page. Re-issue / disable / enable: city HR, HR-SYR, super-admin (`DigitalIdPolicy`, re-checked on every action against the active role). The owner can only **report a loss** (notifies their city's HR, falls back to HR-SYR; one report per hour). Re-issue is a compare-and-swap on the shown index inside a transaction with `lockForUpdate`, so a double click cannot make the printed card outdated. Every change goes to the `security` log with actor and employee id. |
| Emergency number (A2D-Q7) | New nullable `cities.hr_hotline`, editable on the cities page (`project-basics.update`); the old "first HR employee's phone" rule is the fallback. |
| QR | No QR library added. The panel shows the canonical link with copy/open, for the existing print tool. |

### 1.3 Action coverage (task step 3)

Each unclassified action was classified on its own in `config/access-control.php`:

| Action | Decision | Reason |
|---|---|---|
| `contract.contracts-table::confirmToggleAutoRenew`, `toggleAutoRenewConfirmed` | mapped `contracts.update` | Write; both also run `ContractPolicy::manageAutoRenew` on the record. |
| `contract.contracts-table::updatedPaginators` | exempt | Stores the caller's own page in their own session; rows still go through `scopeVisibleTo`. |
| `position.org-chart::toggleVacancies` | exempt | View toggle; vacant positions hold no person's data. |
| `position.position-create/edit::updatedFormCityId` | exempt | Resets the center and validates the city through `validPositionFormCityId()`; writes nothing. |
| `position.profile-drawer::openProfile` | moved to "authorized inside" | Record read with its own `viewDirectory` check (no longer "component-state only"). A stale `close` entry for a method that does not exist was removed. |
| `basics.city-module::edit` | mapped `project-basics.update` | Discloses a city into the modal. |
| `basics.city-module::save` | exempt, authorized inside | In-method `project-basics.update`. |
| `employee-cards.manage-digital-id::reissue/disable/enable/reportLost` | exempt, authorized inside | Gates against the active role; `reportLost` belongs to the owner, who holds no HR permission. |

## 2. Tests changed because the signed policy changed the expectation

| Test | Old expectation | New |
|---|---|---|
| `AuthorizationRegressionTest::…_shows_another_citys_colleague_at_directory_tier_only` (was `…_refuses_an_employee_from_another_city`) | 403 for HR-ALP on a Homs employee | Opens with Directory fields only; no R/P/F in HTML or snapshot; former Homs employee → 404 (§7 #1, A2D-Q4). |
| `SecurityAuthorizationTest::test_digital_id_link_resolves_only_for_an_active_enabled_card` | unguessable token | New link resolves; username link, disabled, inactive → not valid (§9 Q6). |
| `PrivateAvatarAccessTest::test_a_disabled_digital_id_stops_resolving`, `…_reissuing_the_card_invalidates_the_printed_card`, `…_the_public_card_is_marked_noindex` | token route | Same intent on the new link. |
| `PrivateAvatarAccessTest::…_sees_active_photos_but_not_former_employees` (was `…_cannot_download_a_photo`) | other-city photo 403 | Active 200, former/unknown 404 (A2D-Q4). |
| `EmployeeProfileRecordsVisibilityTest` | employee sees no own records; every manager sees records | Employee sees own records (Q2); manager only for direct reports. |

New: `DirectoryAccessPolicyTest` (18 tests) and `DigitalIdCardPolicyTest` (26 tests), synthetic fixtures only.

## 3. Acceptance evidence

| AC | Status | Evidence |
|---|---|---|
| **AC-A2-01** Disallowed profile/field access denied on direct calls and after a role switch | **Met (tests)** | `DirectoryAccessPolicyTest`: full role matrix; drawer 404 for unknown, 9 malformed values and former employee; no national id/birthday/personal contact/courses/education/skills/salary in HTML, snapshot or effects; role switch drops the drawer and re-checks the profile. |
| **AC-A2-02** Disabled cards cannot resolve; rotation invalidates old links | **Met (tests)** | `DigitalIdCardPolicyTest`: 19 invalid cases → same 404 page; cardboard → plastic; re-issue 1→2 ends 1; disable/enable; deactivation; city/program move; stale re-issue; management roles; security log without secrets. Token mode was **not** chosen (readable link, §9 Q6), so "rotation" = re-issue of the index. |
| **AC-A2-03** Authorized directory use still works; action coverage passes | **Met (tests)** | Active colleagues visible in every city for every role; `LivewireMutationCoverageTest` passes with no exemption replacing an authorization. |

**Final run (2026-10-20, uncommitted tree on `94d3b248`):** 597 tests, **593 passed, 4 failed**. All 5 A2 failures from the baseline now pass. The 4 remaining are the baseline contract failures outside A2 (`ContractAutoRenewalWorkflowTest` same-day warning, `ContractCreatePerformanceTest` composer markup, `ContractPayClauseTest` PDF view, `ContractRenewalTest` malformed query); the card agent confirmed they fail identically on a clean HEAD. Pint clean.

**Not done:** no authenticated browser walkthrough (the dev server reads the operational database; the X2 synthetic copy was not rebuilt). A reviewer should walk the drawer, profile, card panel and not-valid page on the X2 copy in EN/AR before sign-off.

## 4. Rollout and recovery

- **Migrations (additive):** `2026_10_20_000001_add_digital_id_card_index_to_employees_table` (`unsignedSmallInteger`, default 0; `down()` refuses to drop while any card has index > 0, because re-creating the column would bring back revoked cards) and `2026_10_20_000002_add_hr_hotline_to_cities_table`. `digital_id_token` is kept and still filled, so the previous release can be redeployed.
- **No cards are printed yet** (§9 Q6), so there is no legacy link to keep alive: old `/ID/{username}` links show "not valid" by design.
- **Before printing:** check every program's `code` is 1–3 digits (sample data has duplicates 003/004/009; the panel shows "no link" for an invalid code), and set each city's HR hotline.
- **Pilot** on the X2 synthetic copy or staging first.
- **Recovery:** never set an index back or re-enable a card reported lost as a rollback shortcut; issue a new card instead.

## 5. Open points for the product owner

1. **Same city and program, new position:** the link does not change, so the old card keeps working until HR re-issues. Policy says the old link "stops"; confirm that a re-issue is the process, or ask for automatic re-issue.
2. **Two concurrent contracts in different programs:** the newest contract decides the program code, so the link can change.
3. **Birthdays on the dashboard** show every active employee's birthday to all staff, but birthday is Private tier (§4.2). Not changed; needs a decision.
4. **Program detail / center employee lists** do not filter former employees (reachable with `programs.view`); their photos now 404. Candidate for C2/D1.
5. **City hotline** is editable by anyone with `project-basics.update`, not city-scoped (same as the existing city status toggle).
6. [access-policy.md](access-policy.md) §5.3 and §7 #2–#4 describe the earlier token design; §9 supersedes them.

## 6. Handoff

G-A, B4, B5, C2, D1-D, D1, X2-I: use `DirectoryAccess` / `viewDirectory` / `viewRecords` for any colleague data; never load above the viewer's tier. Card links come from `Employee::digitalIdUrl()` only.
