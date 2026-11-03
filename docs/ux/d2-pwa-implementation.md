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

## 5. Hardening for production (2026-11-03)

Scope: the PWA core only. The install button, settings page and header menu are built separately (`resources/js/pwa-install.js`) on the contract below. No package added, no migration.

### 5.1 What changed

| Part | Change |
|---|---|
| **Install-prompt capture** `partials/head.blade.php` | <ul><li>An early inline script (with the CSP nonce, before the Vite bundle) catches `beforeinstallprompt`, calls `e.preventDefault()`, keeps the event in `window.__hopehrInstallPrompt` and dispatches `hopehr:installable` on `window`.</li><li>On `appinstalled` it sets `window.__hopehrInstallPrompt = null` and dispatches `hopehr:installed`.</li><li>`window.__hopehrInstallCapture` stops a second copy (wire:navigate) from adding the listeners twice.</li><li>Why so early: Chrome fires the event once, often before any module script has run; a listener added later would miss it.</li></ul> |
| **Maskable icons** | <ul><li>New `public/favicons/maskable-192x192.png` and `maskable-512x512.png`: the brand disc (red, white mark) scaled to 80% and centred on opaque white, so Android's circle/squircle masks never cut the logo.</li><li>Made once with PHP GD from `android-chrome-512x512.png`; that file and every other stored PNG are untouched (same SHA-1 before and after).</li></ul> |
| **Manifest** `site.webmanifest` | <ul><li>Added `lang: "en"`, `dir: "auto"`, `categories: ["business","productivity"]`, `prefer_related_applications: false`, `launch_handler: {client_mode: "navigate-existing"}` (re-opening the app reuses its window instead of stacking new ones; ignored where unsupported), and the two maskable icons.</li><li>Two `shortcuts` (long-press / right-click on the app icon): **Inbox** `/inbox` and **My profile** `/my-profile`. Both need no permission, so they open for every signed-in role. Without a locale in the URL, mcamara serves the hidden default locale or redirects to the session's locale, and a signed-out user lands on sign-in. *My leaves* was left out: it needs `leaves.view-own`, which not every role has.</li><li>Unchanged: `start_url`, `scope` and `id` `/`, `standalone`, theme `#BE1F24`. `/` signed out redirects to `/login`, inside scope (checked over HTTPS on hc-hr.test and in the test).</li><li>Not added: `display_override: ["window-controls-overlay"]`. The header does not lay itself out around a title-bar area, so `standalone` stays.</li></ul> |
| **iOS metas** | <ul><li>`apple-mobile-web-app-capable` `yes` (older iOS reads this, not the manifest) and `apple-mobile-web-app-status-bar-style` `default`: an opaque bar with dark text above the light header. `black-translucent` would put the page under the clock and need safe-area padding on every header; `black` clashes with the light theme.</li><li>`apple-touch-icon` (180 px) and `apple-mobile-web-app-title` kept.</li></ul> |
| **Worker updates** | <ul><li>`VERSION` is now `hopehr-static-v2`, so devices drop the v1 cache on activate. `skipWaiting` / `clients.claim` kept.</li><li>Registered with `{ updateViaCache: 'none' }`, and `registration.update()` runs once per full page load, so a new `sw.js` is fetched on the next visit rather than after up to 24 h.</li><li>Navigation preload: not added (pages are network-only already; it would only save a few ms of worker start-up).</li><li>Security guarantees unchanged: GET only; navigations network-only with the offline page as fallback; only `/build/assets/` stored; cache reset on sign-out and role switch.</li></ul> |
| **Web server** `public/.htaccess` | <ul><li>The year-long `immutable` rule matched every `*.js`, `sw.js` included, which would have pinned devices to an old worker. A later `FilesMatch` now serves `sw.js` and `site.webmanifest` with `Cache-Control: no-cache` and no `Expires`.</li><li>`.webmanifest` is served as `application/manifest+json`.</li><li>Checked on hc-hr.test over HTTPS: `sw.js` → `no-cache`, `application/javascript`; the manifest → `no-cache`, `application/manifest+json`; icons keep the long cache.</li></ul> |
| **Offline page** `public/offline.html` | <ul><li>Brand palette: primary `#BE1F24` (the one button), ink `#414042`, ink-2 `#6D6E71`, line-soft `#E6E7E8`, soft page `#F3F3F4`, white card. The old `#c2151c` / `#1f2937` set and the red top border are gone (red is for the action only).</li><li>Dark variant through `prefers-color-scheme` using the app's dark tokens; `color-scheme` meta; `theme-color` `#BE1F24`.</li><li>Safe-area padding on all four sides (notch, home bar, landscape).</li><li>Same bilingual text, same *Try again* behaviour, no form, no data. The icon is the precached `android-chrome-192x192.png`; the font stays the system one because Somar Sans is not precached.</li></ul> |

### 5.2 Tests (`tests/Feature/PwaInstallTest.php`, now 9 tests)

New checks: maskable icons exist at their declared sizes, are declared `maskable`, and have opaque white corners; the new manifest fields and no `display_override`; `/` signed out ends on sign-in inside scope, and each shortcut opens for a signed-in `employee`; `VERSION` above v1 with `skipWaiting` / `clients.claim`; the `.htaccess` override comes after the immutable rule and sets `no-cache`, plus the manifest MIME; the install-capture script has the nonce, sits in `<head>` before any `/build/` asset, appears once, and keeps the exact contract above; the iOS metas; the offline page uses `#BE1F24`, none of the old colours, a dark variant, safe-area insets and a precached icon. Existing assertions kept; the registration check now expects `updateViaCache: 'none'` and `registration.update()`.

### 5.3 Installing it (for staff and QA)

The app must be opened over **HTTPS** once, signed in or not; the browser then offers to install it.

| Platform | How |
|---|---|
| **Windows** (Chrome, Edge) | Install icon at the right of the address bar, or the browser menu → *Cast, save and share* → *Install page as app* (Chrome) / *Apps* → *Install this site as an app* (Edge). It gets a Start-menu entry and its own window; uninstall from the app's own menu or *Settings → Apps*. |
| **macOS** | Chrome / Edge: as on Windows; the app appears in Launchpad and can be kept in the Dock. Safari 17+ (Sonoma and later): *File* → *Add to Dock*. |
| **Android** | Chrome: the *Install app* banner, or menu → *Install app*. Chrome builds a WebAPK, so it shows in the app drawer and system settings like any app, with the maskable icon. Long-press shows the two shortcuts. |
| **iOS / iPadOS** | Safari (and, from iOS 16.4, other browsers' share sheets): *Share* → *Add to Home Screen*. There is no automatic prompt on iOS; `beforeinstallprompt` never fires there, so the install UI must show these steps instead. |

### 5.4 Deployment requirements

- **HTTPS is mandatory.** Service workers and installation work only in a secure context (`localhost` excepted). `.htaccess` already forces TLS for non-local hosts.
- **The app must be served at the domain root.** `sw.js` is registered from `/sw.js` and controls scope `/`, and the manifest's `start_url`, `scope` and `id` are `/`. Served from a sub-folder, the worker would not control the pages and the app would not install.
- **`sw.js` and `site.webmanifest` must not be long-cached.** Apache: the `.htaccess` rule above (needs `mod_headers`; `mod_expires` and `mod_mime` are used when present). Behind nginx or a CDN, set the same `Cache-Control: no-cache` there, and serve `.webmanifest` as `application/manifest+json`.
- **Run `npm run build`** on every release: the worker caches only fingerprinted `/build/assets/*`, so a fresh build is what makes devices fetch new CSS/JS.
- **Bump `VERSION` in `sw.js`** whenever `sw.js`, its `STATIC` list or `offline.html` changes; otherwise devices keep the old offline page.

### 5.5 Native packages: assessment (no code)

The PWA already covers every platform staff use: Windows and macOS (Chrome/Edge install, Safari *Add to Dock*), Android (Chrome install as a WebAPK) and iOS (Safari *Add to Home Screen*). **No `.exe`, `.dmg`, `.apk` or `.ipa` is produced**, and none is needed to install it.

| Need | What it would take |
|---|---|
| A real desktop installer (`.exe`/`.msi`, `.dmg`), an MDM-pushed install, or OS features a browser does not expose | **Tauri** (small; uses the system webview) wrapping the live URL. Adds code signing (Windows Authenticode, Apple notarisation) and an update channel to maintain. |
| A **Google Play** listing | **Trusted Web Activity via Bubblewrap**: a thin shell over this same PWA, verified by a `/.well-known/assetlinks.json` file on the domain. Or **Capacitor**, if native plugins (push via FCM, biometrics) are wanted. Needs a Play developer account and a signing key. |
| An **Apple App Store** listing | Capacitor could build an `.ipa`, but Apple would likely **reject a pure web wrapper** under App Store Review Guideline **4.2 (Minimum Functionality)**. Only worth it with genuinely native features; it also needs a Mac, Xcode and an Apple developer account. |

None of this tooling has been added.

### 5.6 Limits (as of 2026-11-03)

- **No iOS splash screens.** iOS needs one `apple-touch-startup-image` per device size and orientation (dozens of files); without them iOS shows a plain screen while the app opens. Left out on purpose.
- **iOS install is manual** (Share → Add to Home Screen); a page cannot trigger a prompt there.
- **Not tried on real devices this round:** the install flow, maskable-icon cropping on Android launchers, the shortcuts, and `launch_handler`. Covered by the manifest tests and the earlier Chrome installability check (section 3) only; the installability check was not re-run on the new manifest.
- **Title-bar colour once installed:** the pages' `theme-color` (white / `#161617`, following the identity's page colour) overrides the manifest's red once a page has loaded; the red shows in the splash and task switcher.
- **Offline:** still only the offline page, by decision (DEC-07).
