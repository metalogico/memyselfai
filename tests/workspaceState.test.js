import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readWorkspaceState, workspaceUrl } from '../resources/js/workspaceState.js';

test('refresh restores the selected view, month and all filters from the URL', () => {
    const state = readWorkspaceState('?view=calendar&project_id=12&milestone_id=34&month=2027-02&assignee=agent&include_done=1');

    assert.deepEqual(state, {
        view: 'calendar', projectId: '12', milestoneId: '34', month: '2027-02', assignee: 'agent', includeDone: true,
    });
    const url = workspaceUrl(state, 'https://tasks.example/?source=bookmark#workspace');
    assert.deepEqual(readWorkspaceState(new URL(url).search), state);
    assert.equal(new URL(url).searchParams.get('source'), 'bookmark');
    assert.equal(new URL(url).hash, '#workspace');
});

test('clearing the project removes its milestone filter from the URL and restored state', () => {
    const state = readWorkspaceState('?project_id=12&milestone_id=34');
    state.projectId = '';

    const url = new URL(workspaceUrl(state, 'https://tasks.example/?project_id=12&milestone_id=34'));

    assert.equal(url.searchParams.has('project_id'), false);
    assert.equal(url.searchParams.has('milestone_id'), false);
    assert.equal(readWorkspaceState('?milestone_id=34').milestoneId, '');
});

test('invalid URL filters fall back to usable workspace defaults', () => {
    const state = readWorkspaceState('?view=unknown&project_id=-1&milestone_id=2&month=2026-13&assignee=human&include_done=yes', true, '2026-11');

    assert.deepEqual(state, {
        view: 'board', projectId: '', milestoneId: '', month: '2026-11', assignee: '', includeDone: true,
    });
    for (const month of ['0000-01', '2026-00', 'not-a-month', '2026-1']) {
        assert.equal(readWorkspaceState(`?month=${month}`, false, '2026-11').month, '2026-11');
    }
});

test('explicit completion settings in the URL override the configured default', () => {
    assert.equal(readWorkspaceState('?include_done=0', true).includeDone, false);
    assert.equal(readWorkspaceState('?include_done=1', false).includeDone, true);
    assert.equal(readWorkspaceState('', true).includeDone, true);
});

test('switching view keeps the project, milestone, month and assignment in the URL', () => {
    const state = readWorkspaceState('?view=calendar&project_id=12&milestone_id=34&month=2027-02&assignee=me&include_done=0');
    state.view = 'board';

    const restored = readWorkspaceState(new URL(workspaceUrl(state, 'https://tasks.example/')).search);

    assert.deepEqual(restored, {
        view: 'board', projectId: '12', milestoneId: '34', month: '2027-02', assignee: 'me', includeDone: false,
    });
});
