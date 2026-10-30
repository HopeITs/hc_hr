/*
 * The clock tile (livewire/dashboard/clock-card.blade.php): Damascus time,
 * whatever the device's own time zone, with Latin digits in both languages.
 * It ticks here only, never on the server, and stops while the page is
 * hidden. The HR line ("Your day ends in 2:15") is worked out from the day
 * the server sent (App\Support\Dashboard\WorkdayClock::today), with the same
 * rules as WorkdayClock::line, and disappears at midnight rather than guess
 * about a day the server has not described. A big clock also draws a bar of
 * how far through the working day it is (workProgress, the same rules as
 * WorkdayClock::progress).
 *
 * The arithmetic is exported for tests/js/clock.test.mjs; the Alpine part is
 * registered as x-data="hcClock(config)" when there is a window.
 */

export const ZONE = 'Asia/Damascus';

const partsFormat = new Intl.DateTimeFormat('en-GB', {
    timeZone: ZONE, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
});

/** The wall clock in Damascus: { date: 'Y-m-d', h, m, s }. */
export function damascus(now) {
    const p = Object.fromEntries(partsFormat.formatToParts(now).map(({ type, value }) => [type, value]));

    return { date: `${p.year}-${p.month}-${p.day}`, h: +p.hour % 24, m: +p.minute, s: +p.second };
}

/** Degrees for each hand of an analogue face. */
export function angles({ h, m, s }) {
    return { h: (h % 12 + m / 60) * 30, m: (m + s / 60) * 6, s: s * 6 };
}

/**
 * What the day means at `minute` (minutes since midnight, may be fractional):
 * { key: starts | resumes | ends | break | outside | off, left: whole minutes to go or null }.
 * Null when there is no day, or the day is not `date` (past midnight).
 */
export function workLine(day, minute, date) {
    if (! day || day.date !== date) return null;
    if (day.off) return { key: 'off', left: null };

    const last = day.periods.length - 1;

    for (let i = 0; i <= last; i++) {
        const [start, end] = day.periods[i];
        if (minute < start) return { key: i === 0 ? 'starts' : 'resumes', left: Math.ceil(start - minute) };
        if (minute < end) return { key: i === last ? 'ends' : 'break', left: Math.ceil(end - minute) };
    }

    return { key: 'outside', left: null };
}

/**
 * How far through the working day `minute` is, for the big clock's bar (the
 * same rules as WorkdayClock::progress): { done: whole working minutes so far,
 * breaks not counted, total, pct: 0 to 100, state: before | on | after }.
 * Null on a day off, without a day, or past midnight.
 */
export function workProgress(day, minute, date) {
    if (! day || day.off || day.date !== date || ! day.periods.length) return null;

    let total = 0;
    let done = 0;

    for (const [start, end] of day.periods) {
        total += end - start;
        done += Math.max(0, Math.min(minute, end) - start);
    }

    const first = day.periods[0][0];
    const last = day.periods[day.periods.length - 1][1];

    return {
        done: Math.floor(done),
        total,
        pct: total > 0 ? Math.round(done / total * 1000) / 10 : 0,
        state: minute < first ? 'before' : minute >= last ? 'after' : 'on',
    };
}

/** 80 → "1:20". */
export const formatLeft = (minutes) => Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0');

const fill = (text, values) => Object.entries(values).reduce((out, [name, value]) => out.replaceAll(':' + name, value), text || '');

function clock(config) {
    const time = new Intl.DateTimeFormat(config.locale, { timeZone: ZONE, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    const weekday = new Intl.DateTimeFormat(config.locale, { timeZone: ZONE, weekday: 'long' });
    const date = new Intl.DateTimeFormat(config.locale, { timeZone: ZONE, day: 'numeric', month: 'long', year: 'numeric' });
    let timer = null;
    let onVisibility = null;

    return {
        now: new Date(),
        init() {
            const tick = () => {
                this.now = new Date();
                // On the next whole second, so the minute turns when it should.
                timer = setTimeout(tick, 1000 - (Date.now() % 1000) + 10);
            };
            const stop = () => {
                clearTimeout(timer);
                timer = null;
            };
            onVisibility = () => {
                stop();
                if (! document.hidden) tick();
            };
            this.stop = stop;
            document.addEventListener('visibilitychange', onVisibility);
            if (! document.hidden) tick();
        },
        destroy() {
            this.stop?.();
            document.removeEventListener('visibilitychange', onVisibility);
        },
        t() {
            return time.format(this.now);
        },
        wd() {
            return weekday.format(this.now);
        },
        d() {
            return date.format(this.now);
        },
        hand(name) {
            return 'rotate(' + angles(damascus(this.now))[name] + ' 50 50)';
        },
        label() {
            return fill(config.text.aria, { time: this.t(), weekday: this.wd(), date: this.d() });
        },
        line() {
            const now = damascus(this.now);
            const line = workLine(config.day, now.h * 60 + now.m + now.s / 60, now.date);

            return line ? fill(config.text[line.key], { time: line.left === null ? '' : formatLeft(line.left) }) : null;
        },
        // The big clock's bar: { pct, state, done: "3:15" } or null (day off, no schedule, past midnight).
        progress() {
            const now = damascus(this.now);
            const p = workProgress(config.day, now.h * 60 + now.m + now.s / 60, now.date);

            return p ? { ...p, done: formatLeft(p.done) } : null;
        },
        // The words under the bar, in the page's language.
        progressText() {
            const p = this.progress();
            if (! p) return '';
            if (p.state === 'before') return config.text.notStarted;
            if (p.state === 'after') return config.text.over;

            return fill(config.text.done, { time: p.done });
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('hcClock', clock);

    // On a full load Alpine is there but not started; after wire:navigate it is running.
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register);
    }
}
