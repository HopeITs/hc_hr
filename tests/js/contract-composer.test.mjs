import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/contract-composer.js', import.meta.url), 'utf8');
let composer;
runInNewContext(source, {
    document: { addEventListener: (_event, listener) => listener() },
    window: {
        Alpine: {
            data(name, factory) {
                if (name === 'contractComposer') {
                    composer = factory;
                }
            },
        },
    },
});

const config = {
    locale: 'en',
    typeDefaults: {},
    dayNames: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
    labels: { closedDayRangeWarning: 'Center closed: :days' },
};

test('contract range warns for the selected center and clears when the conflict goes away', () => {
    const state = composer({ ...config, mode: 'create', seed: { positionId: 1 } });
    state.options.positions = [{ value: 1, placeWeekend: [0, 6] }, { value: 2, placeWeekend: [5] }];
    state.workDayFrom = '0';
    state.workDayTo = '4';

    assert.equal(state.closedDayRangeWarning, 'Center closed: Sunday');
    assert.equal(state.scheduleValid, true);

    state.selected.position = '2';
    assert.equal(state.closedDayRangeWarning, '');

    state.selected.position = '1';
    state.workDayFrom = '1';
    assert.equal(state.closedDayRangeWarning, '');
});

test('amendment range uses the existing center and warns only for a complete fixed range', () => {
    const state = composer({
        ...config,
        mode: 'amend',
        seed: { placeWeekend: [0, 6], workDayFrom: 0, workDayTo: 6 },
    });

    assert.equal(state.closedDayRangeWarning, 'Center closed: Sunday, Saturday');
    assert.equal(state.scheduleValid, true);

    state.workDayTo = '';
    assert.equal(state.closedDayRangeWarning, '');

    state.workDayFrom = '4';
    state.workDayTo = '0';
    assert.equal(state.closedDayRangeWarning, '');

    state.workDayFrom = '0';
    state.workDayTo = '6';
    state.scheduleMode = '2';
    assert.equal(state.closedDayRangeWarning, '');

    state.scheduleMode = '1';
    state.dayPickMode = 'days';
    assert.equal(state.closedDayRangeWarning, '');
});
