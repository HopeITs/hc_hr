# D2-PWA · Installable app without private offline data

Task: D2-PWA in [ux-prompts-v3.md](ux-prompts-v3.md) · Date: 2026-10-28 · Branch `hr-ux-by-rafi`
Decision: **DEC-07** (2026-10-16): «Yes: make it installable (PWA) without offline caching of private data.»
Choices made in session (routine): the existing Hope Center icons; short name **HopeHR**.
Status: **built and tested; review pending. No migration, no data change.**

## 1. What changed

| Part | Behaviour |
|---|---|
| **Install** | <ul><li>`public/favicons/site.webmanifest` was broken: an empty name, and icon paths pointing to `/` instead of `/favicons/`.</li><li>It now has name «HopeHR — Christian Hope Center», short name «HopeHR», start `/`, `standalone`, theme `#c2151c`, and 192 and 512 px icons.</li><li>`theme-color` and the Apple title metas were added.</li></ul> |
| **Service worker** `public/sw.js` | Stores **only**: <ul><li>the offline page and three icons (precached);</li><li>Vite's fingerprinted `/build/assets/*`.</li></ul> **Never stored:** any page (HTML), Livewire traffic, `/employee-photo`, `/employee-files`, the digital ID, or any other response. Pages are network-only, with the offline page when the network is down. **Only GET is handled**: a save without a connection fails at once and is never queued or replayed (no Background Sync). |
| **Offline page** `public/offline.html` | Static and bilingual (AR/EN), with no HR data. It says that nothing is saved or sent later, and to check what you meant to save after reconnecting. *Try again* button. |
| **Clean slate** | <ul><li>The sign-in page (after logout), and any page whose signed-in user or active role differs from the last one on this device, tell the worker to empty its cache back to the static set.</li><li>The device keeps only an **opaque** SHA-256 fingerprint (no id, email or role name) in `localStorage`.</li></ul> |
| Expired session | Unchanged: the server redirects to sign-in, and that page clears the cache. |
| **CSP** | The registration script carries the CSP nonce. Without it, the browser refused to run it (found in the browser check). |

## 2. Tests (`tests/Feature/PwaInstallTest.php`, 4 tests)

| AC | Test |
|---|---|
| **AC-D2-PWA-01** Scope selected | DEC-07 (recorded above). `test_the_manifest_is_valid_and_its_icons_exist`: the fields, and each icon exists at its declared size. |
| **AC-D2-PWA-02** Nothing sensitive cached after logout or a role switch | <ul><li>`test_the_worker_stores_only_public_static_files_and_never_replays_writes`: one `cache.put`, only for `/build/assets/`; navigations network-only; no private path precached.</li><li>`test_pages_link_the_manifest_and_register_the_worker_with_a_session_fingerprint`: signed out means `signed-out`; a role switch changes the fingerprint; the fingerprint is an opaque hash; the CSP nonce is present.</li></ul> |
| **AC-D2-PWA-03** Offline writes fail clearly, no replay | <ul><li>The worker test: only GET handled, no `sync`.</li><li>`test_the_offline_page_is_static_bilingual_and_promises_no_replay`.</li></ul> |

Also updated: `BottomNavTest` (D2) had asserted that no worker was registered; it now points here.

**Full suite:** 1030 tests, 5839 assertions, 0 failures.

## 3. Browser checks (synthetic copy, `manager.alp`, 127.0.0.1)

| Check | Result |
|---|---|
| Registration | The worker is `activated` and controls the page. The manifest is served with «HopeHR», `standalone`, `/`. |
| Browse private pages (profile with photo, inbox, calendar, leaves, the photo URL) | The cache holds **12 entries, none private**: offline page, 3 icons, fonts, `app-*.js`, `vendor-*.js`, CSS. |
| **Save offline** (skills dialog, server stopped) | The save failed; the dialog stayed open with the entry kept. The G-B-2 toast appears only after a real tap or key, and the scripted submit was neither, so no toast. |
| **No replay** | The server was restarted and checked 20 s later: interests still empty, `updated_at` unchanged (2026-10-27 16:13:12). |
| **Open a page offline** | `/ar/inbox` showed the bilingual offline page; the inbox was never stored. |
| **Logout** | On `/ar/login` the cache was reset to the 4 static entries (build files removed too), and the fingerprint reads `signed-out`. |

### Chrome's own installability check (2026-10-28)

- **How:** the Chrome 156 installed on this PC, headless with throwaway profiles, driven through its DevTools protocol (`Page.getInstallabilityErrors`, `Page.getAppManifest`). No package was downloaded.
- **Target:** `http://127.0.0.1:8401/ar/login` on the test copy.

| Manifest | Installability errors | Notes |
|---|---|---|
| **New** (commit ade75db6) | **none** | Manifest parsed with no errors; name «HopeHR»; worker `activated`; cache holds only the offline page, icons and fingerprinted fonts. |
| **Old** (86c70911, restored temporarily, then put back) | `start-url-not-valid`, `manifest-missing-name-or-short-name`, `no-acceptable-icon` | Before this task the app could not be installed at all. |

## 4. Limits

- **Install prompt and home-screen icon:** not tried on a real phone. Chrome's own check says the app is installable (above), but tapping *Install* on a phone remains for QA.
- **What works without a connection:** only the offline page. Nothing of HR data is available offline, by decision.
- **Found, not changed here:** the app layout's inline `window.CustomScrollbarConfig` script, and two other inline scripts, are blocked by the same CSP (no nonce), so the custom scrollbar runs on its defaults. This predates D2-PWA.
