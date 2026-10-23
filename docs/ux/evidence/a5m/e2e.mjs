// A5-M browser evidence: My Leaves request dialog (+ department dialog for HR).
// Usage: node e2e.mjs <outDir>
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:8123';
const OUT = process.argv[2];
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const check = (name, ok, detail = '') => {
    results.push({ name, ok: !!ok, detail });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

const T = {
    en: { request: 'Request leave', discardTitle: 'Discard your changes?', keep: 'Keep editing', discard: 'Discard changes', cancel: 'Cancel', submit: 'Submit request' },
    ar: { request: 'طلب إجازة', discardTitle: 'تجاهل التغييرات؟', keep: 'متابعة التعديل', discard: 'تجاهل التغييرات', cancel: 'إلغاء', submit: 'إرسال الطلب' },
};

async function login(page, locale, email) {
    await page.goto(`${BASE}/${locale}/login`);
    await page.fill('#email', email);
    await page.fill('#password', 'password');
    await page.locator('form button[type="submit"]').first().click();
    await page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 });
}

async function run(locale, viewport, label) {
    const browser = await chromium.launch();
    const context = await browser.newContext({ viewport, locale, isMobile: viewport.width < 500, hasTouch: viewport.width < 500 });
    const page = await context.newPage();
    const t = T[locale];
    const tag = `[${label}]`;

    await login(page, locale, 'staff@e2e.test');
    await page.goto(`${BASE}/${locale}/leaves`);
    await page.waitForLoadState('networkidle');

    // Reload/navigation detectors.
    await page.evaluate(() => { window.__a5mMarker = 'alive'; });
    const docRequests = [];
    page.on('request', (r) => { if (r.resourceType() === 'document') docRequests.push(r.url()); });
    let navigations = 0;
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) navigations++; });

    const dir = await page.evaluate(() => document.documentElement.dir);
    check(`${tag} page direction`, dir === (locale === 'ar' ? 'rtl' : 'ltr'), dir);

    const dialog = page.locator('#showModal');
    const panel = page.locator('[data-testid="confirm-dialog"]');
    const opener = page.getByRole('button', { name: t.request, exact: true });
    const reason = page.locator('#showModal textarea, #showModal #reason').first();

    await page.evaluate(() => window.scrollTo(0, 120));
    const scrollBefore = await page.evaluate(() => window.scrollY);

    const open = async () => {
        await opener.click();
        await dialog.waitFor({ state: 'visible' });
        await page.waitForLoadState('networkidle');
    };
    const closed = async () => {
        await dialog.waitFor({ state: 'hidden', timeout: 5000 }).then(() => true).catch(() => false);
        return !(await dialog.isVisible());
    };
    const confirmCount = async () => (await panel.isVisible()) ? 1 : 0;
    const settle = () => page.waitForTimeout(400);

    // AC-08 labels
    await open();
    const title = (await dialog.locator('h2').first().innerText()).trim();
    check(`${tag} new-request title`, title === t.request, title);
    check(`${tag} submit label`, await dialog.getByRole('button', { name: t.submit }).isVisible());
    await page.screenshot({ path: `${OUT}/${label}-01-open.png` });

    // AC-02 pristine: Escape, header close, Cancel close immediately
    await page.keyboard.press('Escape'); await settle();
    check(`${tag} pristine Escape closes without confirmation`, (await closed()) && (await confirmCount()) === 0);
    const focusText = await page.evaluate(() => document.activeElement?.innerText?.trim());
    check(`${tag} focus returns to opener`, focusText === t.request, focusText);

    await open();
    await dialog.locator('[data-modal-close]').click(); await settle();
    check(`${tag} pristine header close closes`, (await closed()) && (await confirmCount()) === 0);

    await open();
    await dialog.getByRole('button', { name: t.cancel, exact: true }).click(); await settle();
    check(`${tag} pristine Cancel closes`, (await closed()) && (await confirmCount()) === 0);

    // AC-01 backdrop ignored when dirty (and when clean)
    await open();
    await reason.fill('Family visit — synthetic');
    await page.mouse.click(4, viewport.height - 4); await settle();
    check(`${tag} backdrop click keeps dirty dialog open`, await dialog.isVisible() && (await confirmCount()) === 0);
    check(`${tag} backdrop keeps entry`, (await reason.inputValue()) === 'Family visit — synthetic');

    // AC-03 each route: exactly one confirmation, focus on Keep editing
    for (const route of ['Escape', 'header', 'Cancel']) {
        if (route === 'Escape') await page.keyboard.press('Escape');
        if (route === 'header') await dialog.locator('[data-modal-close]').click();
        if (route === 'Cancel') await dialog.getByRole('button', { name: t.cancel, exact: true }).click();
        await panel.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
        await page.waitForTimeout(600); // the confirmation settles focus within 500 ms
        const shown = await panel.isVisible();
        const titleText = shown ? (await panel.locator('#confirm-dialog-title').innerText()).trim() : '';
        const focused = await page.evaluate(() => document.activeElement?.innerText?.trim());
        check(`${tag} dirty ${route}: one confirmation with the A5-M wording`, shown && titleText === t.discardTitle && (await page.locator('[data-testid="confirm-dialog"]:visible').count()) === 1, titleText);
        check(`${tag} dirty ${route}: initial focus on Keep editing`, focused === t.keep, focused);
        if (route === 'Escape') await page.screenshot({ path: `${OUT}/${label}-02-confirm.png` });

        // Dismiss: Escape for the first, Keep editing button for the rest.
        if (route === 'Escape') await page.keyboard.press('Escape');
        else await panel.getByRole('button', { name: t.keep }).click();
        await settle();
        check(`${tag} dirty ${route}: dismissing keeps dialog and entry`, (await dialog.isVisible()) && !(await panel.isVisible()) && (await reason.inputValue()) === 'Family visit — synthetic');
        await page.waitForTimeout(200);
        const inside = await page.evaluate(() => document.getElementById('showModal').contains(document.activeElement));
        check(`${tag} dirty ${route}: focus is back inside the dialog`, inside);
    }

    // AC-04 discard clears, reopen clean
    await page.keyboard.press('Escape');
    await panel.waitFor({ state: 'visible' });
    await panel.getByRole('button', { name: t.discard }).click(); await settle();
    check(`${tag} Discard closes`, await closed());
    await open();
    check(`${tag} reopened request starts clean`, (await reason.inputValue()) === '');

    // changed-then-restored is clean
    await reason.fill('temp'); await reason.fill('');
    const scrollOpen = await page.evaluate(() => window.scrollY);
    await page.keyboard.press('Escape'); await settle(); await page.waitForTimeout(300);
    const scrollClosed = await page.evaluate(() => window.scrollY);
    check(`${tag} scroll position kept across close`, scrollOpen === scrollClosed, `${scrollOpen} → ${scrollClosed}`);
    check(`${tag} changed-then-restored closes without confirmation`, (await closed()) && (await confirmCount()) === 0);

    // AC-05 no reload / navigation / scroll change
    const marker = await page.evaluate(() => window.__a5mMarker);
    check(`${tag} no document reload`, marker === 'alive' && docRequests.length === 0 && navigations === 0, `docs=${docRequests.length} navs=${navigations}`);

    // Phone: with the soft keyboard the visual viewport shrinks; the action row must stay reachable by scrolling.
    if (viewport.width < 500) {
        await open();
        await reason.focus();
        await page.setViewportSize({ width: viewport.width, height: Math.round(viewport.height * 0.55) });
        const cancelBtn = dialog.getByRole('button', { name: t.cancel, exact: true });
        await cancelBtn.scrollIntoViewIfNeeded();
        const box = await cancelBtn.boundingBox();
        check(`${tag} Cancel reachable with keyboard-sized viewport`, box && box.y >= 0 && box.y + box.height <= Math.round(viewport.height * 0.55), JSON.stringify(box));
        await page.screenshot({ path: `${OUT}/${label}-03-keyboard.png` });
        await page.setViewportSize(viewport);
        await page.keyboard.press('Escape');
    }

    await browser.close();
}

async function runHr(locale) {
    const browser = await chromium.launch();
    const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 }, locale })).newPage();
    const t = T[locale];
    const tag = `[hr-departments-${locale}]`;
    await login(page, locale, 'hrmanager@mail.com');
    await page.goto(`${BASE}/${locale}/departments`);
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => { window.__a5mMarker = 'alive'; });
    const dialog = page.locator('#showModal');
    const panel = page.locator('[data-testid="confirm-dialog"]');
    await page.locator('main button, button').filter({ hasText: locale === 'ar' ? /إضافة|جديد/ : /Add/ }).last().click();
    await dialog.waitFor({ state: 'visible' });
    await page.waitForLoadState('networkidle');
    await page.fill('#showModal #name', 'Synthetic dept');
    await page.mouse.click(4, 796);
    await page.waitForTimeout(400);
    check(`${tag} backdrop ignored`, await dialog.isVisible());
    await dialog.getByRole('button', { name: t.cancel, exact: true }).click();
    await panel.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    check(`${tag} dirty Cancel asks`, await panel.isVisible());
    await panel.getByRole('button', { name: t.discard }).click();
    await dialog.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    check(`${tag} discard closes, no reload`, !(await dialog.isVisible()) && (await page.evaluate(() => window.__a5mMarker)) === 'alive');
    await browser.close();
}

for (const [locale, vp, label] of [
    ['en', { width: 1280, height: 800 }, 'en-desktop'],
    ['ar', { width: 1280, height: 800 }, 'ar-desktop'],
    ['en', { width: 390, height: 844 }, 'en-phone'],
    ['ar', { width: 390, height: 844 }, 'ar-phone'],
]) {
    try { await run(locale, vp, label); } catch (e) { check(`[${label}] run`, false, e.message.split('\n')[0]); }
}
for (const locale of ['en', 'ar']) {
    try { await runHr(locale); } catch (e) { check(`[hr-${locale}] run`, false, e.message.split('\n')[0]); }
}

fs.writeFileSync(`${OUT}/results.json`, JSON.stringify(results, null, 2));
const failed = results.filter((r) => !r.ok).length;
console.log(`\n${results.length - failed}/${results.length} passed`);
process.exit(failed ? 1 : 0);
