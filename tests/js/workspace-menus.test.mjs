import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const view = (path) => readFileSync(new URL('../../resources/views/' + path + '.blade.php', import.meta.url), 'utf8');
const data = (source, globals = {}) => runInNewContext('(' + source.match(/x-data="([\s\S]*?)"/)[1].replace(/\{\{--[\s\S]*?--\}\}/g, '') + ')', globals);

test('choosing a search result opens it once and closes search before navigation', () => {
    let visits = 0;
    const state = data(view('livewire/layout/global-search'), {
        Livewire: { navigate: (href) => {
            visits++;
            assert.equal(href, '/en/employees/example');
            assert.equal(state.open, false);
            assert.equal(state.mobile, false);
        } },
    });
    Object.assign(state, {
        open: true, mobile: true, active: 0,
        $refs: { list: { querySelectorAll: () => [{ dataset: { href: '/en/employees/example' } }] } },
    });
    state.choose();
    assert.equal(visits, 1);
    assert.equal(state.active, -1);
});

test('search, profile, notification and mobile navigation menus close when a workspace opens', () => {
    const cases = [
        ['livewire/layout/global-search', { open: true, active: 2, mobile: true }, { open: false, active: -1, mobile: false }],
        ['components/layouts/app/notification', { open: true }, { open: false }],
        ['livewire/layout/header', { open: true, rolesOpen: true }, { open: false, rolesOpen: false }],
        ['components/layouts/app/sidebar/profile-card', { open: true, rolesOpen: true }, { open: false, rolesOpen: false }],
        ['livewire/layout/sidebar', { open: true }, { open: false }],
        ['livewire/layout/bottom-nav', { sheet: 'new', startY: 80 }, { sheet: null, startY: null }],
    ];
    for (const [path, initial, expected] of cases) {
        const source = view(path);
        const state = path.endsWith('global-search') || path.endsWith('bottom-nav') ? data(source) : {};
        Object.assign(state, initial);
        const handler = source.match(/x-on:hc-workspace-opened.window="([^"]+)"/)[1];
        runInNewContext('with (state) { ' + handler + ' }', { state });
        for (const [key, value] of Object.entries(expected)) assert.equal(state[key], value, path + ': ' + key);
    }
});

test('desktop and mobile New menus retain their Cancel return URL and close before navigating', () => {
    for (const path of ['livewire/layout/partials/new-menu', 'livewire/layout/bottom-nav']) {
        const source = view(path);
        let destination;
        const state = data(source, {
            URL, URLSearchParams,
            location: { pathname: '/en', search: '?view=team&from=old' },
            Livewire: { navigate: (url) => { destination = url; } },
        });
        state.open = true;
        state.sheet = 'new';
        state.go({ currentTarget: { href: 'https://hr.test/en/leaves/create', dataset: { returns: '' } } });
        assert.equal(new URL(destination).searchParams.get('from'), '/en?view=team');
        assert.equal(path.endsWith('bottom-nav') ? state.sheet : state.open, path.endsWith('bottom-nav') ? null : false);
        state.go({ currentTarget: { href: 'https://hr.test/en/employees/create', dataset: {} } });
        assert.equal(new URL(destination).searchParams.has('from'), false);
        const anchor = source.match(/<a[^>]*data-new=[\s\S]*?>/)[0];
        assert.match(anchor, /data-ws-skip/);
    }
});
