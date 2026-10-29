// Prompt 4: the dashboard home screen's arithmetic (resources/js/dashboard/layout-math.js).
// Node's built-in runner, no dependency: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { clampSize, inlineDelta, moveStep, pack, resizeStep, stepCells } from '../../resources/js/dashboard/layout-math.js';

const bounds = {
    notes: { min: [2, 2], max: [12, 8] },
    team: { min: [3, 2], max: [12, 8] },
    news: { min: [3, 3], max: [12, 8] },
    'my-leaves': { min: [3, 1], max: [12, 2] },
    'app.nav.inbox': { min: [1, 1], max: [3, 3] },
    'app.nav.hr.employees': { min: [1, 1], max: [3, 3] },
};

const cellsUsed = (placed) => Object.values(placed).reduce((n, p) => n + p.w * p.h, 0);

test('the resize grip grows towards the inline end: right in English, left in Arabic', () => {
    // The pointer moves 40px to the right.
    assert.equal(inlineDelta(40, false), 40);
    assert.equal(inlineDelta(40, true), -40);
    // ...and 40px to the left: that grows the tile in Arabic.
    assert.equal(inlineDelta(-40, true), 40);
});

test('arrow keys on the grip add a column towards the inline end, in both directions', () => {
    assert.deepEqual(resizeStep('ArrowRight', false), [1, 0]);
    assert.deepEqual(resizeStep('ArrowLeft', false), [-1, 0]);
    assert.deepEqual(resizeStep('ArrowLeft', true), [1, 0]);
    assert.deepEqual(resizeStep('ArrowRight', true), [-1, 0]);
    assert.deepEqual(resizeStep('ArrowDown', true), [0, 1]);
    assert.deepEqual(resizeStep('ArrowUp', false), [0, -1]);
    assert.equal(resizeStep('Enter', false), null);
});

test('arrow keys on a tile move it along the reading direction', () => {
    assert.equal(moveStep('ArrowRight', false), 1);
    assert.equal(moveStep('ArrowLeft', false), -1);
    // In Arabic the next tile is to the left.
    assert.equal(moveStep('ArrowLeft', true), 1);
    assert.equal(moveStep('ArrowRight', true), -1);
    assert.equal(moveStep('ArrowDown', true), 1);
    assert.equal(moveStep('Tab', false), null);
});

test('a size stays inside its bounds and never wider than the grid', () => {
    assert.deepEqual(clampSize(bounds.notes, 8, 20, 20), [8, 8]);
    assert.deepEqual(clampSize(bounds.notes, 4, 6, 3), [4, 3]);
    assert.deepEqual(clampSize(bounds.news, 8, 1, 1), [3, 3]);
    // A minimum wider than a phone's grid still fits the grid.
    assert.deepEqual(clampSize({ min: [5, 1], max: [12, 2] }, 4, 5, 1), [4, 1]);
});

test('resizing changes the size only once the pointer is well into the next cell', () => {
    assert.equal(stepCells(3.5, 3), 3);
    assert.equal(stepCells(3.7, 3), 4);
    assert.equal(stepCells(2.5, 3), 3);
    assert.equal(stepCells(2.3, 3), 2);
});

test('packing places every tile inside the grid without overlap, filling gaps', () => {
    for (const cols of [4, 8, 12]) {
        const shown = [
            { key: 'my-leaves', size: '5x1' },
            { key: 'notes', size: '5x3' },
            { key: 'team', size: '3x4' },
            { key: 'news', size: '3x6' },
            { key: 'app.nav.inbox', size: '1x1' },
            { key: 'app.nav.hr.employees', size: '1x1' },
        ];
        const placed = pack(shown, cols, bounds);
        const seen = new Set();

        for (const { key } of shown) {
            const p = placed[key];
            assert.ok(p, `${key} placed at ${cols} columns`);
            assert.ok(p.x >= 0 && p.x + p.w <= cols, `${key} inside ${cols} columns`);
            for (let y = p.y; y < p.y + p.h; y++) {
                for (let x = p.x; x < p.x + p.w; x++) {
                    assert.ok(! seen.has(`${x},${y}`), `${key} overlaps at ${x},${y}`);
                    seen.add(`${x},${y}`);
                }
            }
            // A card grows into a gap, never past its own maximum.
            assert.ok(p.w <= Math.min(bounds[key].max[0], cols) && p.h <= bounds[key].max[1], `${key} within its maximum`);
        }
        assert.equal(seen.size, cellsUsed(placed));
    }
});

test('a card beside empty cells grows into them', () => {
    const placed = pack([{ key: 'notes', size: '5x3' }, { key: 'team', size: '3x4' }], 12, bounds);

    // Twelve columns: notes and team together take the whole width.
    assert.equal(placed.notes.w + placed.team.w, 12);
    assert.equal(placed.notes.x, 0);
});

// Ported from media-request (2026-10-29): closed tiles, icons, sizes set by hand.
test('a tile with nothing in it goes last, as one line, below everything else', async () => {
    const { pack } = await import('../../resources/js/dashboard/layout-math.js');
    const shown = [
        { key: 'notes', size: '5x3' },
        { key: 'team', size: '3x4' },
        { key: 'news', size: '3x6' },
    ];
    const placed = pack(shown, 8, bounds, new Set(['notes']));
    const bottom = Math.max(placed.team.y + placed.team.h, placed.news.y + placed.news.h);

    assert.equal(placed.notes.h, 1);
    assert.ok(placed.notes.y >= bottom, 'the closed tile starts below the others');
});

test('a widget below its card size is an icon; a shortcut always is', async () => {
    const { iconish } = await import('../../resources/js/dashboard/layout-math.js');
    const b = { notes: { min: [1, 1], max: [12, 8], card: [2, 2] } };

    assert.equal(iconish('notes', '1x1', b, 8), true);
    assert.equal(iconish('notes', '2x1', b, 8), true);
    assert.equal(iconish('notes', '2x2', b, 8), false);
    assert.equal(iconish('app.nav.inbox', '3x3', b, 8), true);
});

test('a size set by hand is shown exactly; the others grow into the gaps', () => {
    const shown = [
        { key: 'notes', size: '3x2', fixed: true },
        { key: 'team', size: '3x2' },
    ];
    const placed = pack(shown, 8, bounds);

    assert.deepEqual([placed.notes.w, placed.notes.h], [3, 2]);
    assert.ok(placed.team.w > 3, 'the other card grew into the gap');
});
