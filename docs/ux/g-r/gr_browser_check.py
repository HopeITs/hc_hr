"""G-R browser gate on the synthetic SQLite copy (C:/laragon/tmp/hc-hr-gr, port 8406).
Synthetic data only. Password read from the fixture manifest at runtime."""
import sys, json, re, time, traceback, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from common import B, PW
from playwright.sync_api import sync_playwright

EV = 'C:/laragon/www/hc-hr/docs/ux/evidence/g-r/browser/'
os.makedirs(EV, exist_ok=True)
REL = 'docs/ux/evidence/g-r/browser/'
ONLY = set(sys.argv[1:])

RESULTS = []
def rec(cid, desc, status, detail='', evidence=''):
    RESULTS.append({'id': cid, 'check': desc, 'status': status, 'detail': detail, 'evidence': evidence})
    print(f'[{status.upper():7}] {cid} {desc} :: {detail} {evidence}', flush=True)

SAL = ['7319.47', '7,319', '7319', '6284.13', '6,284', '6284', '5173.29', '5,173', '5173', '4062.81', '4,062', '4062',
       '٧٣١٩', '٦٢٨٤', '٥١٧٣', '٤٠٦٢', '٧٬٣١٩', '٦٬٢٨٤']
SAL_RE = re.compile(r'(?<![\w.,٬-])(' + '|'.join(re.escape(x) for x in SAL) + r')(?![\d٠-٩])')
def sal_hits(text):
    return sorted(set(SAL_RE.findall(text)))

USERS = {
    'staff': 'synth.007@example.test',      # Nour Halabi, ALP, report of position 1 (manager.alp + Hadi Shami)
    'staff_alp': 'staff.alp@example.test',  # Hala Najjar, contract 2 (salary sentinel 7319.47)
    'staff_hms': 'staff.hms@example.test',
    'manager': 'manager.alp@example.test',
    'hadi': 'synth.207@example.test',       # second holder of position 1 (employee role)
    'hr_a': 'hr.alp@example.test',
    'hr_b': 'hr.hms@example.test',
    'sa': 'synth.060@example.test',         # given super-admin in the copy only (user 60, active ALP)
}

def login(browser, who, locale='en', vp=(1440, 900)):
    ctx = browser.new_context(viewport={'width': vp[0], 'height': vp[1]}, locale='ar' if locale == 'ar' else 'en-US')
    pg = ctx.new_page()
    pg.goto(B + ('/ar' if locale == 'ar' else '') + '/login')
    pg.fill('input[type=email]', USERS[who])
    pg.fill('input[type=password]', PW)
    pg.click('button[type=submit]')
    pg.wait_for_load_state('networkidle')
    if '/login' in pg.url:
        pg.wait_for_timeout(1500)
    if '/login' in pg.url:
        raise RuntimeError(f'login failed for {who}')
    return ctx, pg

def shot(pg, name, full=False):
    pg.screenshot(path=EV + name + '.png', full_page=full)
    return REL + name + '.png'

def go(pg, path):
    r = pg.goto(B + path)
    pg.wait_for_load_state('networkidle')
    return r.status if r else None

def hscroll(pg):
    return pg.evaluate('[document.documentElement.scrollWidth, document.documentElement.clientWidth]')

def want(name):
    return not ONLY or name in ONLY

def run(name, fn, *a):
    if not want(name):
        return
    try:
        fn(*a)
    except Exception as e:
        rec(name, 'section crashed', 'fail', f'{type(e).__name__}: {str(e)[:300]}')
        traceback.print_exc()

# ---------------------------------------------------------------- dashboards
def dashboards(br):
    for who in ['staff', 'manager', 'hr_a', 'hr_b', 'sa']:
        for loc in ['en', 'ar']:
            for vp, tag in [((1440, 900), 'desk'), ((375, 812), 'phone')]:
                ctx, pg = login(br, who, loc, vp)
                errs = []
                pg.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
                st = go(pg, '/ar' if loc == 'ar' else '/')
                attrs = pg.evaluate('[document.documentElement.dir, document.documentElement.lang]')
                sw, cw = hscroll(pg)
                ev = shot(pg, f'home-{who}-{loc}-{tag}')
                ok = st == 200 and sw <= cw + 1 and (attrs == ['rtl', 'ar'] if loc == 'ar' else attrs[0] != 'rtl')
                bn = pg.locator('[data-testid=bottom-nav]')
                bnv = bn.count() > 0 and bn.first.is_visible()
                if tag == 'phone' and not bnv:
                    ok = False
                rec(f'home/{who}/{loc}/{tag}', 'login -> home dashboard', 'pass' if ok else 'fail',
                    f'status={st} dir/lang={attrs} scrollW/clientW={sw}/{cw} bottomNav={bnv} consoleErrors={len(errs)}', ev)
                ctx.close()
    # 812x375 landscape spot-check
    ctx, pg = login(br, 'staff', 'ar', (812, 375))
    st = go(pg, '/ar'); sw, cw = hscroll(pg)
    ev = shot(pg, 'home-staff-ar-landscape-812x375')
    rec('home/staff/ar/landscape', '812x375 landscape spot-check', 'pass' if st == 200 and sw <= cw + 1 else 'fail', f'status={st} scroll {sw}/{cw}', ev)
    ctx.close()

# ---------------------------------------------------------------- leave flow
def submit_leave(pg, date, reason, dbl=False, locale='en'):
    pre = '/ar' if locale == 'ar' else ''
    go(pg, pre + '/leaves?new=1'); pg.wait_for_timeout(800)
    bal = pg.eval_on_selector('#leave_balance_id', 'e=>[...e.options].find(o=>/Annual|سنوية/.test(o.text))?.value')
    pg.evaluate(f"jQuery('#leave_balance_id').val('{bal}').trigger('change')"); pg.wait_for_timeout(1200)
    pg.fill('#start_at', date); pg.wait_for_timeout(700)
    pg.fill('#end_at', date); pg.wait_for_timeout(1000)
    pg.fill('#reason', reason); pg.wait_for_timeout(300)
    pg.locator('#reason').locator('xpath=ancestor::form[1]').locator('button.bg-primary:visible').first.click(); pg.wait_for_timeout(1500)
    btn = pg.locator('button:visible', has_text=re.compile(r'^\s*(Save|حفظ)\s*$')).last
    if dbl:
        btn.dblclick()
    else:
        btn.click()
    pg.wait_for_timeout(2500)

def leave_flow(br):
    ctx, pg = login(br, 'staff')
    submit_leave(pg, '2026-11-30', 'G-R dblclick synthetic', dbl=True)
    ev = shot(pg, 'leave-submit-staff-en-desk-dblclick')
    body = pg.inner_text('body')
    rec('leave/submit-dblclick', 'employee submits leave with double-click on confirm Save (EN desk)',
        'pending-db', 'UI after submit: ' + ('Pending row visible' if '30-11-2026' in body else 'row for 30-11-2026 NOT visible'), ev)
    ctx.close()
    # AR phone submission by staff.hms
    ctx, pg = login(br, 'staff_hms', 'ar', (375, 812))
    submit_leave(pg, '2026-11-09', 'G-R AR phone synthetic', locale='ar')
    ev = shot(pg, 'leave-submit-staffhms-ar-phone')
    body = pg.inner_text('body')
    rec('leave/submit-ar-phone', 'employee submits leave (AR phone)', 'pending-db', 'row 09-11-2026 ' + ('visible' if '09-11-2026' in body or '٠٩-١١-٢٠٢٦' in body else 'not visible'), ev)
    ctx.close()

def find_req_id():
    p = EV + '../_ids.json'
    return json.load(open(p)) if os.path.exists(p) else {}

def concurrency(br):
    rid = find_req_id().get('dbl')
    if not rid:
        rec('leave/concurrent', 'two contexts decide same request', 'not-run', 'request id unknown (run db step first)')
        return
    key = f'leave:{rid}'
    ca, pa = login(br, 'manager')
    cb, pb = login(br, 'hadi')
    for pg in (pa, pb):
        go(pg, f'/inbox?open={key}:decide'); pg.wait_for_timeout(800)
    rowa = pa.locator(f'[data-key="{key}:decide"]'); rowb = pb.locator(f'[data-key="{key}:decide"]')
    if rowa.count() == 0 or rowb.count() == 0:
        rec('leave/concurrent', 'both deciders see the row', 'fail', f'rowA={rowa.count()} rowB={rowb.count()}', shot(pa, 'conc-a-missing'))
        return
    shot(pa, 'conc-a-before'); shot(pb, 'conc-b-before')
    rowb.locator('button[role=radio]').nth(1).click()
    rowb.locator(f'input[id^=reason-]').fill('G-R race reject')
    rowa.locator('button[role=radio]').nth(0).click()
    rowa.locator('[data-testid=inbox-decide]').click(); pa.wait_for_timeout(2500)
    eva = shot(pa, 'conc-a-after-accept')
    rowb.locator('[data-testid=inbox-decide]').click(); pb.wait_for_timeout(2500)
    evb = shot(pb, 'conc-b-after-reject')
    tb = pb.inner_text('body')
    m = re.search(r'[^\n]*(already|not saved)[^\n]*', tb, re.I)
    rec('leave/concurrent-ui', 'second decider gets "already decided" message', 'pass' if m else 'fail',
        (m.group(0)[:200] if m else 'no already-decided text found'), evb)
    rec('leave/manager-decides', 'manager accepts in inbox (EN desk)', 'pass' if re.search(r'accepted', pa.inner_text('body'), re.I) else 'fail', 'see screenshot', eva)
    ca.close(); cb.close()

def hr_all_leaves(br):
    for who in ['hr_a', 'hr_b']:
        for loc in ['en', 'ar']:
            ctx, pg = login(br, who, loc)
            st = go(pg, ('/ar' if loc == 'ar' else '') + '/leaves/all')
            ev = shot(pg, f'all-leaves-{who}-{loc}')
            body = pg.inner_text('body')
            rec(f'leaves/all/{who}/{loc}', 'HR views All leaves', 'pass' if st == 200 else 'fail', f'status={st} len={len(body)}', ev)
            ctx.close()
    ctx, pg = login(br, 'staff')
    st = go(pg, '/leaves/all')
    rec('leaves/all/staff', 'employee refused All leaves', 'pass' if st in (403, 404) else 'fail', f'status={st}', shot(pg, 'all-leaves-staff-refused'))
    ctx.close()

# ---------------------------------------------------------------- profile D1
def profile(br):
    ctx, pg = login(br, 'staff')
    st = go(pg, '/my-profile')
    rec('profile/self', 'own profile opens (EN desk)', 'pass' if st == 200 else 'fail', f'status={st}', shot(pg, 'profile-self-en-desk'))
    pg.locator('button:visible', has_text='Add experience').first.click(); pg.wait_for_timeout(1200)
    pg.fill('#p_job', 'G-R synthetic job'); pg.fill('#p_company', 'G-R Synthetic Co')
    pg.fill('#p_start', '2020-01-01'); pg.fill('#p_xend', '2021-01-01')
    pg.select_option('#p_place', index=1); pg.select_option('#p_type', index=1)
    pg.locator('#p_job').locator('xpath=ancestor::form[1]').locator('button.bg-primary:visible').first.click(); pg.wait_for_timeout(2000)
    ev = shot(pg, 'profile-proposal-sent')
    rec('profile/self-edit', 'self-edit (experience proposal to HR)', 'pending-db', 'toast/body: ' + (re.search(r'[^\n]*(sent|HR)[^\n]*', pg.inner_text('body')) or [''])[0][:150], ev)
    ctx.close()
    ctx, pg = login(br, 'staff_hms', 'ar', (375, 812))
    st = go(pg, '/ar/my-profile'); sw, cw = hscroll(pg)
    rec('profile/self-ar-phone', 'own profile AR phone', 'pass' if st == 200 and sw <= cw + 1 else 'fail', f'status={st} scroll {sw}/{cw}', shot(pg, 'profile-self-ar-phone', True))
    ctx.close()

# ---------------------------------------------------------------- calendar / search / help / feedback / nav
def misc(br):
    for who, loc, vp, tag in [('staff', 'en', (1440, 900), 'desk'), ('manager', 'ar', (1440, 900), 'desk'), ('staff', 'ar', (375, 812), 'phone')]:
        ctx, pg = login(br, who, loc, vp)
        st = go(pg, ('/ar' if loc == 'ar' else '') + '/team-calendar'); sw, cw = hscroll(pg)
        rec(f'calendar/{who}/{loc}/{tag}', 'team calendar', 'pass' if st == 200 and sw <= cw + 1 else 'fail', f'status={st} scroll {sw}/{cw}', shot(pg, f'calendar-{who}-{loc}-{tag}'))
        ctx.close()
    # help panel + feedback (desktop EN, staff)
    ctx, pg = login(br, 'staff')
    go(pg, '/leaves')
    pg.locator('[data-help-button]:visible').first.click(); pg.wait_for_timeout(800)
    hp = pg.locator('[data-testid=help-panel]')
    vis = hp.count() > 0 and hp.first.is_visible()
    rec('help/panel/en/desk', 'help panel opens', 'pass' if vis else 'fail', f'visible={vis}', shot(pg, 'help-panel-en-desk'))
    pg.keyboard.press('Escape'); pg.wait_for_timeout(400)
    pg.evaluate("Livewire.dispatch('open-feedback')"); pg.wait_for_timeout(1000)
    if pg.locator('#feedback-category').count():
        pg.select_option('#feedback-category', index=1)
    pg.fill('#feedback-message', 'G-R synthetic feedback message from the browser gate.')
    pg.locator('#feedback-message').locator('xpath=ancestor::form[1]').locator('button.bg-primary:visible').first.click(); pg.wait_for_timeout(2000)
    rec('feedback/submit', 'feedback form submits (D3)', 'pending-db', '', shot(pg, 'feedback-submitted'))
    ctx.close()
    # help on phone AR via account menu or ? trigger
    ctx, pg = login(br, 'staff', 'ar', (375, 812))
    go(pg, '/ar/leaves')
    pg.evaluate("window.dispatchEvent(new CustomEvent('help-open'))"); pg.wait_for_timeout(800)
    hp = pg.locator('[data-testid=help-panel]'); vis = hp.count() > 0 and hp.first.is_visible()
    rec('help/panel/ar/phone', 'help panel on AR phone (opened by help-open event)', 'pass' if vis else 'fail', f'visible={vis}', shot(pg, 'help-panel-ar-phone'))
    pg.keyboard.press('Escape'); pg.wait_for_timeout(300)
    # D2 bottom nav: follow each link
    go(pg, '/ar')
    links = pg.eval_on_selector_all('[data-testid=bottom-nav] a', 'as=>as.map(a=>[a.dataset.testid,a.href])')
    bad = []
    for tid, href in links:
        r = pg.goto(href); pg.wait_for_load_state('networkidle')
        sw, cw = hscroll(pg)
        if not r or r.status != 200 or sw > cw + 1:
            bad.append([tid, r.status if r else None, sw, cw])
    rec('nav/bottom/ar/phone', 'D2 bottom nav links load, no sideways scroll', 'pass' if links and not bad else 'fail', f'links={[l[0] for l in links]} bad={bad}', shot(pg, 'bottom-nav-last-ar-phone'))
    ctx.close()
    ctx, pg = login(br, 'manager', 'en', (375, 812))
    go(pg, '/')
    links = pg.eval_on_selector_all('[data-testid=bottom-nav] a', 'as=>as.map(a=>[a.dataset.testid,a.href])')
    rec('nav/bottom/manager/en/phone', 'D2 bottom nav for manager', 'pass' if links else 'fail', f'links={[l[0] for l in links]}', shot(pg, 'bottom-nav-manager-en-phone'))
    ctx.close()

def search(br):
    out = {}
    for who, term in [('hr_a', 'Rizk'), ('hr_a', 'Issa'), ('staff', 'Tannous'), ('hr_b', 'Tannous'), ('sa', 'Hanna')]:
        ctx, pg = login(br, who)
        go(pg, '/')
        if not pg.locator('#global-search-input').is_visible():
            pg.locator('[data-testid=search-open]').first.click()
        pg.locator('#global-search-input').click()
        pg.locator('#global-search-input').press_sequentially(term, delay=60); pg.wait_for_timeout(2000)
        hrefs = pg.eval_on_selector_all('[data-testid=search-person]', 'ls=>ls.map(l=>l.dataset.href)')
        users = [h.rstrip('/').split('/')[-2] for h in hrefs]
        out[f'{who}:{term}'] = users
        ev = shot(pg, f'search-{who}-{term}')
        # open every result: must be 200 (no result the viewer cannot open)
        bad = []
        for h in hrefs[:8]:
            r = pg.goto(h); pg.wait_for_load_state('networkidle')
            if not r or r.status != 200:
                bad.append([h, r.status if r else None])
        rec(f'search/{who}/{term}', 'global search results are all openable', 'pass' if not bad else 'fail', f'results={users} unopenable={bad}', ev)
        ctx.close()
    json.dump(out, open(EV + '../_search.json', 'w'))

# ---------------------------------------------------------------- privacy / cross-city
def privacy(br):
    probes = [
        ('hr_a', '/contracts/171', 'refuse'), ('hr_a', '/contracts/171/edit', 'refuse'), ('hr_a', '/contracts/171/export-preview', 'refuse'),
        ('hr_a', '/employees/171/edit', 'refuse'), ('hr_a', '/employees/synth.223/show', 'refuse'),
        ('hr_b', '/contracts/3', 'refuse'), ('hr_b', '/contracts/2/edit', 'refuse'), ('hr_b', '/employees/3/edit', 'refuse'),
        ('hr_b', '/employees/synth.063/show', 'refuse'),
        ('staff', '/contracts/2', 'refuse'), ('staff', '/employees/40/edit', 'refuse'), ('staff', '/employees/synth.061/show', 'refuse'),
        ('staff', '/contracts', 'refuse'), ('staff', '/employees', 'refuse'),
        ('manager', '/contracts/3', 'refuse'), ('manager', '/contracts/171', 'refuse'),
    ]
    ctxs = {}
    for who, path, exp in probes:
        if who not in ctxs:
            ctxs[who] = login(br, who)
        ctx, pg = ctxs[who]
        st = go(pg, path)
        html = pg.content()
        leak = sal_hits(html) + [s for s in ['NNSENT'] if s in html]
        ok = st in (403, 404) and not leak
        rec(f'xcity/{who}{path}', f'{who} direct URL must be refused', 'pass' if ok else 'fail', f'status={st} leaks={leak}',
            shot(pg, 'probe-' + who + path.replace('/', '_')))
    # other-employee profile: directory tier only, no private fields
    for who, path, sent in [('staff', '/employees/synth.040/show', 'NNSENT0040'), ('hr_a', '/employees/synth.171/show', 'NNSENT0171'),
                            ('hr_b', '/employees/synth.003/show', 'NNSENT0003'), ('staff_alp', '/employees/synth.040/show', 'NNSENT0040')]:
        if who not in ctxs:
            ctxs[who] = login(br, who)
        ctx, pg = ctxs[who]
        st = go(pg, path); html = pg.content()
        phone = '0999' + str(int(re.sub(r'\D', '', sent))).zfill(6)
        leak = [x for x in [sent, phone] if x in html] + sal_hits(html)
        rec(f'profile-other/{who}{path}', 'other employee profile shows directory tier only (no national no./personal phone/pay)',
            'pass' if st in (200, 404) and not leak else 'fail', f'status={st} leaks={leak}', shot(pg, 'other-profile-' + who + path.replace('/', '_')))
    # positive control: HR-A sees salary on own-city contract (proves the grep works)
    ctx, pg = ctxs['hr_a']
    st = go(pg, '/contracts/3'); hits = sal_hits(pg.content())
    rec('salary/control-hr-a', 'positive control: HR-ALP sees pay on ALP contract 3', 'pass' if st == 200 and hits else 'fail', f'status={st} hits={hits}', shot(pg, 'salary-control-hr-a-contract3'))
    # own profile of staff.alp: own private fields visible, pay hidden (employee is not a salary role)
    ctx, pg = login(br, 'staff_alp'); ctxs['staff_alp2'] = (ctx, pg)
    st = go(pg, '/my-profile'); html = pg.content()
    rec('salary/staff-own-profile', 'employee own profile: no pay figure', 'pass' if not sal_hits(html) else 'fail', f'status={st} hits={sal_hits(html)} ownNN={"NNSENT0002" in html}', shot(pg, 'salary-staff-own-profile'))
    for c in ctxs.values():
        c[0].close()

def salary_blind(br):
    ctx, pg = login(br, 'sa')
    bodies = []
    def on_route(route):
        if route.request.resource_type in ('document', 'xhr', 'fetch'):
            resp = route.fetch()
            try:
                bodies.append([route.request.url, resp.status, resp.text()])
            except Exception:
                bodies.append([route.request.url, resp.status, ''])
            route.fulfill(response=resp)
        else:
            route.continue_()
    ctx.route('**/*', on_route)
    rows = []
    for path in ['/contracts', '/contracts/3', '/contracts/2', '/contracts/171', '/contracts/8', '/contracts/3/edit', '/employees/synth.003/show',
                 '/employees/synth.002/show', '/employees/3/edit', '/contracts/3/export-preview', '/contracts/3/renew']:
        st = go(pg, path); pg.wait_for_timeout(500)
        html = pg.content()
        rows.append([path, st, sal_hits(html), 'LOGIN-PAGE' if '/login' in pg.url else ('shows Zeina Hanna' if 'Zeina' in html else '')])
        shot(pg, 'sa' + path.replace('/', '_'))
    # interact with a Livewire component on the contract page to elicit update payloads
    for path in ['/contracts/3', '/contracts/3/edit', '/contracts/2', '/employees/synth.003/show']:
        go(pg, path)
        try:
            pg.evaluate("Livewire.all().forEach(c=>c.$wire.$refresh())"); pg.wait_for_timeout(1500)
        except Exception:
            pass
    net_hits = []
    lw_posts = sum(1 for u, st, t in bodies if 'livewire' in u)
    for u, st, t in bodies:
        h = sal_hits(t)
        if h:
            net_hits.append([u, st, h])
    page_hits = [r for r in rows if r[2] or r[3] == 'LOGIN-PAGE']
    rec('salary/super-admin-pages', 'super-admin (salary-blind): no pay figure in page HTML', 'pass' if not page_hits else 'fail',
        json.dumps(rows)[:900], REL + 'sa_contracts_3.png')
    rec('salary/super-admin-network', 'super-admin: no pay figure in any document/Livewire response', 'pass' if not net_hits else 'fail',
        f'responses scanned={len(bodies)} (livewire updates={lw_posts}) hits={net_hits[:5]}')
    # export as super-admin: must be refused
    resp = ctx.request.get(B + '/contracts/3/export', timeout=60000, max_redirects=0)
    ct = resp.headers.get('content-type', '') + ' location=' + str(resp.headers.get('location'))
    rec('pdf/super-admin', 'super-admin contract PDF export refused', 'pass' if resp.status in (403, 404) else 'fail', f'status={resp.status} type={ct} bytes={len(resp.body())}')
    ctx.close()
    # manager also salary-blind
    ctx, pg = login(br, 'manager')
    st = go(pg, '/contracts/3'); h = sal_hits(pg.content())
    rec('salary/manager', 'manager on non-report contract: refused, no pay', 'pass' if st in (403, 404) and not h else 'fail', f'status={st} hits={h}')
    ctx.close()

def pdf_hr(br):
    ctx, pg = login(br, 'hr_a')
    t0 = time.time()
    resp = ctx.request.get(B + '/contracts/3/export', timeout=180000)
    body = resp.body(); ct = resp.headers.get('content-type', '')
    dt = round(time.time() - t0, 1)
    if 'pdf' in ct and body[:4] == b'%PDF':
        open(EV + 'contract-3-export-hr-alp.pdf', 'wb').write(body)
        rec('pdf/hr-a', 'HR-ALP contract PDF export', 'pass', f'status={resp.status} {len(body)} bytes in {dt}s', REL + 'contract-3-export-hr-alp.pdf')
    else:
        snippet = re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', body[:4000].decode('utf8', 'replace')))[:300]
        rec('pdf/hr-a', 'HR-ALP contract PDF export', 'fail-env', f'status={resp.status} type={ct} bytes={len(body)} in {dt}s; body: {snippet}')
    st = go(pg, '/contracts/3/export-preview')
    rec('pdf/hr-a-preview', 'export-preview answers 404 outside APP_ENV=local (copy runs in production mode)', 'pass' if st == 404 else 'fail', f'status={st} payShown={bool(sal_hits(pg.content()))}', shot(pg, 'export-preview-hr-a'))
    resp = ctx.request.get(B + '/contracts/171/export', timeout=60000)
    rec('pdf/hr-a-xcity', 'HR-ALP export of HMS contract 171 refused', 'pass' if resp.status in (403, 404) else 'fail', f'status={resp.status}')
    ctx.close()

with sync_playwright() as p:
    br = p.chromium.launch()
    run('dash', dashboards, br)
    run('leave', leave_flow, br)
    run('conc', concurrency, br)
    run('all', hr_all_leaves, br)
    run('profile', profile, br)
    run('misc', misc, br)
    run('search', search, br)
    run('privacy', privacy, br)
    run('salary', salary_blind, br)
    run('pdf', pdf_hr, br)
    br.close()

out = EV + 'results.json'
old = json.load(open(out, encoding='utf-8')) if os.path.exists(out) else {'results': []}
keep = [r for r in old['results'] if r['id'] not in {x['id'] for x in RESULTS}]
json.dump({'gate': 'G-R browser', 'sha': '81c5d28f', 'branch': 'g-r', 'base_url': B, 'data': 'C:/laragon/tmp/hc-hr-gr (synthetic SQLite)',
           'run_at': time.strftime('%Y-%m-%dT%H:%M:%S'), 'results': keep + RESULTS}, open(out, 'w', encoding='utf-8'), indent=1, ensure_ascii=False)
