import { test } from 'node:test';
import assert from 'node:assert/strict';
import { calendarDropPayload, calendarHeatmapStyle, dateKey, openTaskCalendar, shiftMonth } from '../resources/js/calendar.js';

test('compact calendar counts only unfinished tasks due in the selected month', () => {
    const tasks = [
        { status: 'todo', due_date: '2026-11-10' },
        { status: 'in_progress', due_date: '2026-11-10' },
        { status: 'done', due_date: '2026-11-10' },
        { status: 'done', due_date: '2026-11-11' },
        { status: 'backlog', due_date: '2026-11-20' },
        { status: 'in_review', due_date: '2026-11-30' },
        { status: 'todo', due_date: null },
        { status: 'todo', due_date: '2026-10-31' },
        { status: 'todo', due_date: '2026-12-01' },
    ];

    const calendar = openTaskCalendar(tasks, '2026-11');

    assert.deepEqual(calendar, {
        days: [
            { date: '2026-11-10', count: 2 },
            { date: '2026-11-20', count: 1 },
            { date: '2026-11-30', count: 1 },
        ],
        total: 4,
    });
});

test('compact calendar stays empty when no unfinished tasks are due in the selected month', () => {
    const tasks = [
        { status: 'done', due_date: '2026-11-10' },
        { status: 'todo', due_date: null },
        { status: 'todo', due_date: '2026-12-01' },
    ];

    const calendar = openTaskCalendar(tasks, '2026-11');

    assert.deepEqual(calendar, { days: [], total: 0 });
    assert.deepEqual(openTaskCalendar([], '2026-11'), { days: [], total: 0 });
});

test('heatmaps keep the default green palette when no project color is selected', () => {
    assert.deepEqual(calendarHeatmapStyle(undefined), {});
    assert.deepEqual(calendarHeatmapStyle(''), {});
    assert.deepEqual(calendarHeatmapStyle('invalid'), {});
});

test('project heatmaps use tinted intensity levels and readable text on light and dark colors', () => {
    const purple = calendarHeatmapStyle('#7551ff');
    const yellow = calendarHeatmapStyle('#efb82a');

    assert.equal(purple['--calendar-accent'], '#7551ff');
    assert.equal(purple['--calendar-heat-1'], 'rgb(230, 224, 255)');
    assert.equal(purple['--calendar-heat-4'], 'rgb(117, 81, 255)');
    assert.equal(purple['--calendar-heat-1-text'], '#000000');
    assert.equal(purple['--calendar-heat-4-text'], '#ffffff');
    assert.equal(yellow['--calendar-heat-4'], 'rgb(239, 184, 42)');
    assert.equal(yellow['--calendar-heat-4-text'], '#000000');
});

test('month calculations cross year boundaries', () => {
    assert.equal(shiftMonth('2026-12', 1), '2027-01');
    assert.equal(shiftMonth('2027-01', -1), '2026-12');
    assert.equal(shiftMonth('2026-03', -1), '2026-02');
});

test('date keys use local calendar dates without UTC shifts', () => {
    assert.equal(dateKey(new Date(2026, 9, 27, 0, 0)), '2026-10-27');
    assert.equal(dateKey(new Date(2026, 9, 27, 23, 59)), '2026-10-27');
});

test('dropping a calendar task onto another day changes only its deadline', () => {
    const task = { id: 1, due_date: '2026-11-04', status: 'in_progress', assignee: 'agent' };

    assert.deepEqual(calendarDropPayload(task, '2026-11-20', '2026-11'), { due_date: '2026-11-20' });
    assert.equal(task.due_date, '2026-11-04');
});

test('calendar drops ignore the same date, adjacent months and invalid dates', () => {
    const task = { due_date: '2026-11-04' };

    for (const target of ['2026-11-04', '2026-10-31', '2026-12-01', '2026-11-31', 'invalid']) {
        assert.equal(calendarDropPayload(task, target, '2026-11'), null);
    }
    assert.equal(calendarDropPayload(task, '2026-12-02', '2026-12'), null);
    assert.equal(calendarDropPayload(undefined, '2026-11-20', '2026-11'), null);
});
