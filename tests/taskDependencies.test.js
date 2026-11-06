import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDependencyLoader, dependencyOptions } from '../resources/js/taskDependencies.js';

test('dependency search includes done tasks, excludes itself and searches only project task names', () => {
    const tasks = [
        { id: 1, project_id: 2, name: 'API review', status: 'done' },
        { id: 2, project_id: 2, name: 'API review', status: 'todo' },
        { id: 3, project_id: 2, name: 'Current API task' },
        { id: 4, project_id: 9, name: 'API elsewhere' },
        { id: 5, project_id: 2, name: 'Other work', description: 'API' },
        { id: 6, project_id: 2, name: 'Literal 100%_match' },
    ];

    assert.deepEqual(dependencyOptions(tasks, '2', 3, ' api ').map((task) => task.id), [1, 2]);
    assert.deepEqual(dependencyOptions(tasks, 2, null, '%_').map((task) => task.id), [6]);
    assert.equal(dependencyOptions(tasks, 2, null, 'missing').length, 0);
});

test('changing projects ignores slow stale responses and keeps the current loading state', async () => {
    const pending = new Map();
    const state = {};
    const loader = createDependencyLoader((id) => new Promise((resolve) => pending.set(id, resolve)), state);
    const first = loader.load(1);
    const second = loader.load(2);

    pending.get(1)([{ id: 10 }]);
    await first;
    assert.deepEqual(state.tasks, []);
    assert.equal(state.loading, true);

    pending.get(2)([{ id: 20 }]);
    await second;
    assert.deepEqual(state.tasks, [{ id: 20 }]);
    assert.equal(state.loading, false);
});

test('failed option requests can be retried without stale errors affecting a new project', async () => {
    const state = {};
    let rejectFirst;
    const loader = createDependencyLoader((id) => id === 1
        ? new Promise((resolve, reject) => { rejectFirst = reject; })
        : Promise.resolve([{ id: 20 }]), state);
    const first = loader.load(1);
    await loader.load(2);
    rejectFirst(new Error('Old project failed'));
    await first;
    assert.equal(state.error, '');
    assert.deepEqual(state.tasks, [{ id: 20 }]);

    const retry = loader.load(1);
    rejectFirst(new Error('Try again'));
    await retry;
    assert.equal(state.error, 'Try again');
    assert.equal(state.loading, false);
    await loader.load(2);
    assert.equal(state.error, '');
    assert.deepEqual(state.tasks, [{ id: 20 }]);
});

test('clearing a project and unmounting prevent pending requests from restoring old choices', async () => {
    const state = {};
    let resolveRequest;
    const loader = createDependencyLoader(() => new Promise((resolve) => { resolveRequest = resolve; }), state);
    const request = loader.load(1);
    await loader.load('');
    resolveRequest([{ id: 10 }]);
    await request;
    assert.deepEqual(state, { tasks: [], error: '', loading: false });

    const next = loader.load(2);
    loader.cancel();
    resolveRequest([{ id: 20 }]);
    await next;
    assert.deepEqual(state.tasks, []);
});
