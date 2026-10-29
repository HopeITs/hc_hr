// Prompt 5: the clock tile's arithmetic (resources/js/dashboard/clock.js).
// Node's built-in runner, no dependency: `npm run test:js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { angles, damascus, formatLeft, workLine } from '../../resources/js/dashboard/clock.js';

const day = { date: '2026-10-29', off: false, periods: [[540, 990]] };
const split = { date: '2026-10-29', off: false, periods: [[540, 780], [900, 1080]] };

test('Damascus time, whatever the device time zone', () => {
    // 04:40:05 UTC is 07:40:05 in Damascus.
    assert.deepEqual(damascus(new Date(Date.UTC(2026, 9, 29, 4, 40, 5))), { date: '2026-10-29', h: 7, m: 40, s: 5 });
    // 22:30 UTC is already the next day there.
    assert.equal(damascus(new Date(Date.UTC(2026, 9, 29, 22, 30))).date, '2026-10-30');
});

test('the hands point where they should', () => {
    assert.deepEqual(angles({ h: 15, m: 30, s: 15 }), { h: 105, m: 181.5, s: 90 });
    assert.deepEqual(angles({ h: 0, m: 0, s: 0 }), { h: 0, m: 0, s: 0 });
});

test('countdowns are hours:minutes', () => {
    assert.equal(formatLeft(80), '1:20');
    assert.equal(formatLeft(5), '0:05');
    assert.equal(formatLeft(135), '2:15');
});

test('the line follows the day, rounding the time left up to the minute', () => {
    assert.deepEqual(workLine(day, 7 * 60 + 40, day.date), { key: 'starts', left: 80 });
    assert.deepEqual(workLine(day, 539.5, day.date), { key: 'starts', left: 1 });
    assert.deepEqual(workLine(day, 540, day.date), { key: 'ends', left: 450 });
    assert.deepEqual(workLine(day, 14 * 60 + 15, day.date), { key: 'ends', left: 135 });
    assert.deepEqual(workLine(day, 990, day.date), { key: 'outside', left: null });
});

test('a split day has a break, then resumes', () => {
    assert.deepEqual(workLine(split, 720, split.date), { key: 'break', left: 60 });
    assert.deepEqual(workLine(split, 840, split.date), { key: 'resumes', left: 60 });
    assert.deepEqual(workLine(split, 960, split.date), { key: 'ends', left: 120 });
});

test('a day off says so; no day, or another day, says nothing', () => {
    assert.deepEqual(workLine({ ...day, off: true }, 600, day.date), { key: 'off', left: null });
    assert.equal(workLine(null, 600, day.date), null);
    // Past midnight the server has not described the new day: no guess.
    assert.equal(workLine(day, 10, '2026-10-30'), null);
});
