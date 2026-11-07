import { test } from 'node:test';
import assert from 'node:assert/strict';
import { openTaskCalendar } from '../resources/js/calendar.js';
import { filterTaskCalendar, filterTasks } from '../resources/js/taskSearch.js';

test('search matches title and description without case sensitivity and retains task order', () => {
    const tasks = [
        { id: 3, name: 'Review API endpoints', description: null },
        { id: 1, name: 'Update docs', description: 'Describe the API response.' },
        { id: 2, name: 'Fix the calendar', description: 'Check navigation.' },
    ];

    assert.deepEqual(filterTasks(tasks, '  aPi  ').map((task) => task.id), [3, 1]);
    assert.deepEqual(filterTasks(tasks, 'calendar').map((task) => task.id), [2]);
    assert.deepEqual(filterTasks(tasks, 'missing'), []);
});

test('empty and whitespace searches retain every task including missing descriptions', () => {
    const tasks = [
        { id: 1, name: 'Undescribed task' },
        { id: 2, name: 'Another task', description: null },
    ];

    for (const search of ['', ' \t\n ']) {
        assert.deepEqual(filterTasks(tasks, search), tasks);
    }
    assert.deepEqual(filterTasks(tasks, 'absent'), []);
});

test('search treats punctuation as literal text rather than wildcards or regular expressions', () => {
    const tasks = [
        { id: 1, name: 'Review 100%_done! [API].*' },
        { id: 2, name: 'Review 100 percent done API' },
    ];

    for (const search of ['%_done!', '[API].*', '100%']) {
        assert.deepEqual(filterTasks(tasks, search).map((task) => task.id), [1]);
    }
    assert.deepEqual(filterTasks(tasks, 'done API').map((task) => task.id), [2]);
});

test('calendar search updates daily and total counts without mutating the source calendar', () => {
    const calendar = Object.freeze({
        month: '2026-11',
        include_done: true,
        days: Object.freeze([
            Object.freeze({ date: '2026-11-10', count: 2, tasks: Object.freeze([
                Object.freeze({ id: 1, name: 'Review API', status: 'todo' }),
                Object.freeze({ id: 2, name: 'Other task', status: 'todo' }),
            ]) }),
            Object.freeze({ date: '2026-11-11', count: 1, tasks: Object.freeze([
                Object.freeze({ id: 3, name: 'Documentation', description: 'Explain API', status: 'done' }),
            ]) }),
            Object.freeze({ date: '2026-11-12', count: 1, tasks: Object.freeze([
                Object.freeze({ id: 4, name: 'Unrelated', status: 'todo' }),
            ]) }),
        ]),
        total: 4,
    });

    const result = filterTaskCalendar(calendar, 'api');

    assert.equal(result.month, '2026-11');
    assert.equal(result.include_done, true);
    assert.equal(result.total, 2);
    assert.deepEqual(result.days.map((day) => [day.date, day.count, day.tasks.map((task) => task.id)]), [
        ['2026-11-10', 1, [1]],
        ['2026-11-11', 1, [3]],
    ]);
    assert.deepEqual(filterTaskCalendar(calendar, 'missing').days, []);
    assert.equal(filterTaskCalendar(calendar, 'missing').total, 0);
    assert.deepEqual(filterTaskCalendar(calendar, ' '), calendar);
    assert.equal(calendar.total, 4);
    assert.equal(calendar.days[0].count, 2);
});

test('search preserves the calendar month and Done scope while the board keeps all matching tasks', () => {
    const tasks = [
        { id: 1, name: 'API open', due_date: '2026-11-10', status: 'todo' },
        { id: 2, name: 'API completed', due_date: '2026-11-10', status: 'done' },
        { id: 3, name: 'API next month', due_date: '2026-12-10', status: 'todo' },
        { id: 4, name: 'API undated', due_date: null, status: 'backlog' },
        { id: 5, name: 'Other task', due_date: '2026-11-10', status: 'todo' },
    ];
    const calendar = { days: [{ date: '2026-11-10', count: 2, tasks: [tasks[0], tasks[4]] }], total: 2 };
    const withDone = { days: [{ date: '2026-11-10', count: 3, tasks: [tasks[0], tasks[1], tasks[4]] }], total: 3 };

    const matches = filterTasks(tasks, 'api');

    assert.deepEqual(matches.map((task) => task.id), [1, 2, 3, 4]);
    assert.deepEqual(filterTaskCalendar(calendar, 'api').days[0].tasks.map((task) => task.id), [1]);
    assert.equal(filterTaskCalendar(calendar, 'api').total, 1);
    assert.deepEqual(filterTaskCalendar(withDone, 'api').days[0].tasks.map((task) => task.id), [1, 2]);
    assert.equal(filterTaskCalendar(withDone, 'api').total, 2);
    assert.deepEqual(openTaskCalendar(matches, '2026-11'), {
        days: [{ date: '2026-11-10', count: 1 }], total: 1,
    });
});
