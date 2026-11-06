import { assignees } from './assignees.js';
import { currentMonth } from './calendar.js';

const filterParameters = ['view', 'project_id', 'milestone_id', 'month', 'assignee', 'include_done'];

export function readWorkspaceState(search, defaultIncludeDone = false, defaultMonth = currentMonth()) {
    const parameters = new URLSearchParams(search);
    const projectId = positiveId(parameters.get('project_id'));
    const month = parameters.get('month');
    const includeDone = parameters.get('include_done');

    return {
        view: parameters.get('view') === 'calendar' ? 'calendar' : 'board',
        projectId,
        milestoneId: projectId ? positiveId(parameters.get('milestone_id')) : '',
        month: /^(?!0000)\d{4}-(0[1-9]|1[0-2])$/.test(month) ? month : defaultMonth,
        assignee: Object.hasOwn(assignees, parameters.get('assignee')) ? parameters.get('assignee') : '',
        includeDone: ['0', '1'].includes(includeDone) ? includeDone === '1' : defaultIncludeDone,
    };
}

export function workspaceUrl(state, currentUrl) {
    const url = new URL(currentUrl);
    for (const name of filterParameters) {
        url.searchParams.delete(name);
    }
    url.searchParams.set('view', state.view);
    url.searchParams.set('month', state.month);
    url.searchParams.set('include_done', state.includeDone ? '1' : '0');
    if (state.projectId) {
        url.searchParams.set('project_id', state.projectId);
        if (state.milestoneId) {
            url.searchParams.set('milestone_id', state.milestoneId);
        }
    }
    if (state.assignee) {
        url.searchParams.set('assignee', state.assignee);
    }

    return url.href;
}

function positiveId(value) {
    return /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value)) ? value : '';
}
