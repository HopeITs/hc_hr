# B3: leave, checklist and notification states

Task B3 in [ux-prompts-v3.md](ux-prompts-v3.md) (R-05), slice: leave, checklist, notifications.
Dashboard, employee list and contract list are another slice ([b3-employee-contract-notes.md](b3-employee-contract-notes.md)).
Status: **implemented, not reviewed, no browser or screen-reader check.** Nothing committed.

## 1. What each screen now does

| Screen | Loading | Empty | Filtered-empty | Load error |
|---|---|---|---|---|
| My leaves list (`MyLeaveRequestTable`) | table's own spinner + polite result count (HasListStates view) | "No leave requests yet"; **Request leave** button only with `leaves.create` | shared copy, or tab copy when only the status tab hides rows; **Clear filters** works | retry panel in place of the table |
| My leaves balance cards (`MyLeaveRequestModule`) | - | existing "no data" text (now `role=status`) | - | retry panel instead of the cards |
| All leaves (`AllLeaveRequestTable`) | as above | "No leave requests to review"; **Add new** only for HR/super-admin with `leaves.create` | as above | as above |
| Leave balances (`EmpLeaveBalanceTable`) | as above | "No leave balances yet", no action | as above | as above |
| Checklist: mine, pending deliver, pending return | - (synchronous) | distinct copy per screen, no action (rows come from HR) | n/a (no filters) | retry panel; count hidden |
| Checklist: done requests (table) | as leave tables | "No completed requests yet" | as leave tables | as leave tables |
| Notifications page and bell dropdown | `role=status` "Loading notifications" during refresh / mark-all / paging | "No Notifications" + help | n/a (no filters) | retry panel |

Tables get all of this from `App\Livewire\Concerns\HasScreenListStates`, which builds on the shared `HasListStates`
(`renderEmptyState`, `stateAnnouncement`, `clearAllFilters`, guarded `paginate`) used by the employee and contract lists.
It adds only what those could not express: an action that is an event rather than a link, screen-specific copy, and
a filter that lives outside the table (the status tab). Clear filters on a tab-only narrowing dispatches
`leaveStatusFilterCleared`; both leave modules answer with `resetStatusFilter` (tab back to "all").

## 2. Permissions

- Empty-state and header buttons are built from the signed-in user per render, so a role without the permission gets
  text only (no hidden-but-present button). My leaves header button was previously unconditional; it now follows `leaves.create`.
- The buttons only start a flow. `MyLeaveRequestModule::create` already ran `authorizeActivePermission('leaves.create')`.
  **`AllLeaveRequestModule::create` had no server check** (only the `@activerole` button and the middleware map); it now
  requires `leaves.create` and an HR/super-admin active role, so the new `openAllLeaveCreate` event cannot bypass it.
- Nothing new reads leave reasons or pay: states carry only counts and static copy (test asserts the reason text never renders).
- New public methods are classified in `config/access-control.php` (`livewire_exempt`, each with a reason): `retryLoad`
  (re-render only), `clearAllFilters`/`resetStatusFilter` (view filters), `renderEmptyState`/`stateAnnouncement` (text),
  `Counter::syncCount` (reads own count). `LivewireMutationCoverageTest` shows none of my components unclassified.

## 3. Notifications: B6 behavior kept, duplicates removed

Kept: request-state badges, next step, unavailable rows, mark-read-before-redirect, safe URLs.

Found and fixed:
1. `profile-card` mounted a second counter with an extra `wire:poll.10s` on top of the counter's own root poll, so the
   sidebar counter polled twice and the header counter once (three count queries per 10 s per tab).
2. The dropdown was polled every 10 s while closed (list + state queries for a hidden panel).

Now: only the header counter polls (`Counter::$poll`, locked; `mount(poll: false)` for the sidebar copy). The sidebar copy
follows the `notif-arrived` event through `syncCount()`, which never re-announces. The dropdown has no poll: it refreshes on
`notification-read`, `notification-created`, `notif-arrived` and on a new `notification-panel-opened` event fired when the bell
opens. No Echo/websocket listeners exist in the app, so there is nothing else to deduplicate.
`updateCount` keeps the shown number if the count query fails (reported; the next tick retries).

Retry safety: `retryLoad` only clears the flag and re-renders; the list loaders are read-only. Test: three retries while still
failing and one successful retry leave `read_at` null and the notification count unchanged; `markAsRead` twice keeps the
first timestamp (existing `whereKey()->unreadNotifications()->update` already made it a single write).

## 4. Accessibility and layout

- Every state is in a live region: `x-center-empty-state` is `role=status` (error `alert`); the table result count is the
  shared sr-only status; notifications page and dropdown have an sr-only status with the unread total (plural-aware, AR forms) or
  "Loading"/error; the checklist lists announce their item count.
- Empty and error panels share the shared min-height (`min-h-72` / `min-h-48`); the dropdown error panel is `min-h-40`.
- Pending checklist action buttons disable while their own request is in flight (`wire:loading.attr` scoped to that row).
- New copy: `resources/lang/{en,ar}/states.php` (EN and AR, with Arabic plural forms). Icons are `aria-hidden`.

## 5. Tests

`tests/Feature/Livewire/LeaveChecklistNotificationStatesTest.php`, 18 tests: empty vs filtered, Clear filters restoring rows,
tab clear event, action by role (button present/absent plus server refusal), forced failure and retry for leave table,
balance lookup, checklist and both notification components, repeated retry without writes, mark-read idempotence,
single-poll counter, no reason leakage.
Roles are shared by name in the fixtures, so a "no permission" user needs its own role name (`staff-readonly`).

## 6. Limits

- No browser pass: EN/AR, RTL, 375x812, 200% zoom, keyboard and screen reader are **pending**.
- Loading for the tables is the package's `wire:loading` spinner plus the sr-only status; I did not enable `deferLoading`
  (it would hide first-render rows from every existing table test) and the first paint is synchronous, so there is no
  skeleton on these screens. `x-ui.skeleton` is not used here.
- Checklist and notification lists load in `render()`, so a failure shows after the request that triggered it; there is no
  separate "retrying" state beyond the button's disabled/"Loading" label.
- `MyLeaveRequestModule` shows the retry panel for the balance cards only; failures elsewhere in that page (counts, modal) are unchanged.
- The status tab counts (`$counts`) are not guarded.
- `pint --dirty` also touched other agents' uncommitted files if they were unformatted.
