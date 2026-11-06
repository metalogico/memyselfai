<script setup>
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, toRefs, watch } from 'vue';
import Icon from './components/Icon.vue';
import MiniCalendar from './components/MiniCalendar.vue';
import TaskCard from './components/TaskCard.vue';
import TaskDependencySelect from './components/TaskDependencySelect.vue';
import AppSelect from './components/AppSelect.vue';
import { assignees } from './assignees';
import { readWorkspaceState, workspaceUrl } from './workspaceState';
import {
    calendarDropPayload,
    currentMonth,
    formatDate,
    monthCells,
    monthLabel,
    openTaskCalendar,
    priorities,
    shiftMonth,
    statuses,
} from './calendar';

const logoUrl = '/logo.svg?v=mmai-circles-1';
const ready = ref(false);
const authenticated = ref(false);
const configured = ref(true);
const username = ref('');
const csrf = ref(document.querySelector('meta[name="csrf-token"]').content);
const login = reactive({ username: '', password: '' });
const error = ref('');
const formError = ref('');
const saving = ref(false);
const loading = ref(false);
const workspace = reactive(readWorkspaceState(window.location.search));
const { projectId, milestoneId, month, includeDone, assignee, view } = toRefs(workspace);
const board = ref({ tasks: [], projects: [], milestones: [], calendar: { days: [], total: 0 } });
const dragStatus = ref('');
const dragDay = ref('');
const draggedTaskId = ref(null);
const dialog = ref(null);
const modalType = ref('task');
const editingId = ref(null);
const form = reactive({});
const formDependency = ref(null);
const initialFormSnapshot = ref('');
const openingDependency = ref(false);
const modalOpen = ref(false);
let latestDependencyNavigation = 0;
let latestBoardRequest = 0;
let workspaceLoaded = false;
let restoringHistory = false;
let defaultIncludeDone = false;

const resourcesByType = {
    task: 'tasks',
    project: 'projects',
    milestone: 'milestones',
};

const deleteMessages = {
    task: 'Permanently delete this task?',
    project: 'Delete this empty project?',
    milestone: 'Delete this milestone? Its tasks and their due dates will be kept.',
};

const projectColors = [
    { name: 'Purple', value: '#7551ff' },
    { name: 'Blue', value: '#2d86f5' },
    { name: 'Pink', value: '#ed5991' },
    { name: 'Orange', value: '#f78a36' },
    { name: 'Yellow', value: '#efb82a' },
    { name: 'Green', value: '#2eb884' },
    { name: 'Gray', value: '#95a1b8' },
];

const assigneeOptions = Object.entries(assignees).map(([value, label]) => ({ value, label }));
const priorityOptions = Object.entries(priorities).map(([value, label]) => ({ value, label }));
const statusOptions = statuses.map((status) => ({ value: status.key, label: status.name }));
const projectOptions = computed(() => board.value.projects.map((project) => ({
    value: project.id,
    label: project.name,
})));

const selectedProject = computed(() =>
    board.value.projects.find((project) => project.id === Number(projectId.value)),
);
const title = computed(() => selectedProject.value?.name || 'All tasks');
const activeTasks = computed(() => board.value.tasks.filter((task) => task.status !== 'done'));
const miniCalendar = computed(() => openTaskCalendar(board.value.tasks, month.value));
const undated = computed(() => activeTasks.value.filter((task) => !task.due_date).length);
const tasksByStatus = computed(() =>
    Object.fromEntries(
        statuses.map((status) => [
            status.key,
            board.value.tasks.filter((task) => task.status === status.key),
        ]),
    ),
);
const visibleMilestones = computed(() =>
    board.value.milestones.filter(
        (milestone) => milestone.project_id === Number(projectId.value),
    ),
);
const selectedMilestone = computed(() =>
    visibleMilestones.value.find((milestone) => milestone.id === Number(milestoneId.value)),
);
const formMilestones = computed(() =>
    board.value.milestones.filter((milestone) => milestone.project_id === Number(form.project_id)),
);
const milestoneFilterOptions = computed(() => [
    { value: '', label: 'All milestones' },
    ...visibleMilestones.value.map((item) => ({ value: String(item.id), label: item.name })),
]);
const milestoneFormOptions = computed(() => [
    { value: '', label: 'No milestone' },
    ...formMilestones.value.map((item) => ({
        value: item.id,
        label: `${item.name} · ${formatDate(item.due_date)}`,
    })),
]);
const extendedDays = computed(() =>
    Object.fromEntries(board.value.calendar.days.map((day) => [day.date, day])),
);

async function api(path, method = 'GET', data) {
    const response = await fetch(`/api/v1/${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf.value,
        },
        credentials: 'same-origin',
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
    });
    const result = response.status === 204 ? {} : await response.json();

    if (!response.ok) {
        if (response.status === 401) {
            authenticated.value = false;
            closeModal();
        }

        if (response.status === 419) {
            const sessionResponse = await fetch('/api/v1/session', {
                headers: { Accept: 'application/json' },
            });
            const session = await sessionResponse.json();
            csrf.value = session.csrf_token;
            authenticated.value = session.authenticated;
            throw new Error('Your session has been refreshed. Please try saving again.');
        }

        const message = result.errors
            ? Object.values(result.errors).flat().join(' ')
            : result.message || 'Something went wrong. Please try again.';
        throw new Error(message);
    }

    if (result.csrf_token) {
        csrf.value = result.csrf_token;
    }

    return result;
}

function boardQuery() {
    return new URLSearchParams({
        ...(projectId.value ? { project_id: projectId.value } : {}),
        ...(projectId.value && milestoneId.value ? { milestone_id: milestoneId.value } : {}),
        ...(assignee.value ? { assignee: assignee.value } : {}),
        month: month.value,
        include_done: includeDone.value ? '1' : '0',
    });
}

async function loadBoard() {
    const requestId = ++latestBoardRequest;
    loading.value = true;

    try {
        const result = await api(`board?${boardQuery()}`);
        // A slower response must not replace a newer project or month selection.
        if (requestId === latestBoardRequest) {
            board.value = result;
        }
    } catch (cause) {
        if (requestId === latestBoardRequest) {
            error.value = cause.message;
        }
    } finally {
        if (requestId === latestBoardRequest) {
            loading.value = false;
        }
    }
}

async function refresh() {
    await loadBoard();
}

function syncWorkspaceUrl(replace = false) {
    const url = workspaceUrl(workspace, window.location.href);
    if (replace || url !== window.location.href) {
        window.history[replace ? 'replaceState' : 'pushState'](null, '', url);
    }
}

async function restoreWorkspace() {
    restoringHistory = true;
    Object.assign(workspace, readWorkspaceState(window.location.search, defaultIncludeDone));
    await nextTick();
    restoringHistory = false;
    syncWorkspaceUrl(true);
}

async function initializeWorkspace() {
    workspaceLoaded = false;
    await loadBoard();
    defaultIncludeDone = board.value.defaults?.calendar_include_done ?? false;
    const completionParameter = new URLSearchParams(window.location.search).get('include_done');
    if (!['0', '1'].includes(completionParameter) && includeDone.value !== defaultIncludeDone) {
        includeDone.value = defaultIncludeDone;
        await nextTick();
        await loadBoard();
    }
    workspaceLoaded = true;
    syncWorkspaceUrl(true);
}

async function signIn() {
    saving.value = true;
    error.value = '';

    try {
        const result = await api('session', 'POST', login);
        authenticated.value = result.authenticated;
        username.value = result.username;
        login.password = '';
        await initializeWorkspace();
    } catch (cause) {
        error.value = cause.message;
    } finally {
        saving.value = false;
    }
}

async function signOut() {
    try {
        await api('session', 'DELETE');
        authenticated.value = false;
        workspaceLoaded = false;
        assignee.value = '';
        board.value = { tasks: [], projects: [], milestones: [], calendar: { days: [], total: 0 } };
    } catch (cause) {
        error.value = cause.message;
    }
}

function chooseProject(id) {
    const nextProjectId = id ? String(id) : '';
    if (projectId.value !== nextProjectId) {
        milestoneId.value = '';
        projectId.value = nextProjectId;
    }
}

function openCalendar() {
    view.value = 'calendar';
}

function initialForm(type) {
    const defaultProjectId = projectId.value || board.value.projects[0]?.id || '';

    if (type === 'project') {
        return { name: '', color: '#2eb884' };
    }

    if (type === 'milestone') {
        return { name: '', due_date: '', project_id: defaultProjectId };
    }

    return {
        name: '',
        description: '',
        priority: 'medium',
        status: 'backlog',
        assignee: 'me',
        due_date: '',
        project_id: defaultProjectId,
        milestone_id: milestoneId.value,
        depends_on_task_id: null,
    };
}

async function openModal(type, item = null, initial = {}) {
    latestDependencyNavigation++;
    openingDependency.value = false;
    modalType.value = type;
    editingId.value = item?.id || null;
    formError.value = '';

    for (const key of Object.keys(form)) {
        delete form[key];
    }
    Object.assign(form, initialForm(type));

    if (item) {
        for (const key of Object.keys(form)) {
            form[key] = item[key] ?? '';
        }
    }
    Object.assign(form, initial);
    formDependency.value = item?.depends_on || null;
    initialFormSnapshot.value = formSnapshot();

    await nextTick();
    modalOpen.value = true;
    if (!dialog.value.open) {
        dialog.value.showModal();
    }
}

function formSnapshot() {
    return JSON.stringify({
        ...form,
        project_id: Number(form.project_id) || null,
        milestone_id: Number(form.milestone_id) || null,
        depends_on_task_id: Number(form.depends_on_task_id) || null,
    });
}

function changeFormProject() {
    form.milestone_id = '';
    form.depends_on_task_id = null;
    formDependency.value = null;
}

async function fetchDependencyTasks(projectId) {
    const result = await api(`tasks?${new URLSearchParams({ project_id: projectId })}`);
    return result.data;
}

async function openTaskDependency(taskId) {
    if (saving.value || openingDependency.value) {
        return;
    }
    const request = ++latestDependencyNavigation;
    openingDependency.value = true;
    formError.value = '';

    try {
        const result = await api(`tasks/${taskId}`);
        if (request !== latestDependencyNavigation) {
            return;
        }
        if (dialog.value.open && formSnapshot() !== initialFormSnapshot.value
            && !confirm('Discard unsaved changes and open the prerequisite task?')) {
            return;
        }
        await openModal('task', result.data);
    } catch (cause) {
        if (request === latestDependencyNavigation) {
            if (dialog.value.open) {
                formError.value = cause.message;
            } else {
                error.value = cause.message;
            }
        }
    } finally {
        if (request === latestDependencyNavigation) {
            openingDependency.value = false;
        }
    }
}

function handleDialogClose() {
    if (dialog.value.open || !modalOpen.value) {
        return;
    }
    modalOpen.value = false;
    formError.value = '';
    latestDependencyNavigation++;
    openingDependency.value = false;
}

function closeModal() {
    dialog.value?.close();
    handleDialogClose();
}

function closeOnBackdrop(event) {
    if (event.target === dialog.value) {
        closeModal();
    }
}

async function saveForm() {
    saving.value = true;
    formError.value = '';

    try {
        const payload = { ...form };
        if (modalType.value !== 'project') {
            payload.project_id = Number(payload.project_id) || null;
        }
        if (modalType.value === 'task') {
            payload.milestone_id = Number(payload.milestone_id) || null;
            payload.depends_on_task_id = Number(payload.depends_on_task_id) || null;
            payload.due_date ||= null;
        }

        const resource = resourcesByType[modalType.value];
        const path = editingId.value ? `${resource}/${editingId.value}` : resource;
        const method = editingId.value ? 'PATCH' : 'POST';
        const result = await api(path, method, payload);

        if (modalType.value === 'project' && !editingId.value) {
            chooseProject(result.data.id);
        }
        closeModal();
        await refresh();
    } catch (cause) {
        formError.value = cause.message;
    } finally {
        saving.value = false;
    }
}

async function deleteItem() {
    if (!confirm(deleteMessages[modalType.value])) {
        return;
    }
    saving.value = true;

    try {
        const resource = resourcesByType[modalType.value];
        await api(`${resource}/${editingId.value}`, 'DELETE');

        if (modalType.value === 'project') {
            if (Number(projectId.value) === editingId.value) {
                chooseProject('');
            }
        }
        if (modalType.value === 'milestone' && Number(milestoneId.value) === editingId.value) {
            milestoneId.value = '';
        }
        closeModal();
        await refresh();
    } catch (cause) {
        formError.value = cause.message;
    } finally {
        saving.value = false;
    }
}

async function moveTask(task, status) {
    error.value = '';

    try {
        await api(`tasks/${task.id}`, 'PATCH', { status });
        await refresh();
    } catch (cause) {
        error.value = cause.message;
        await refresh();
    }
}

function startDrag(event, task) {
    draggedTaskId.value = task.id;
    event.dataTransfer.setData('text/plain', String(task.id));
    event.dataTransfer.effectAllowed = 'move';
}

function endDrag() {
    draggedTaskId.value = null;
    dragStatus.value = '';
    dragDay.value = '';
}

async function dropTask(event, status) {
    const taskId = Number(event.dataTransfer.getData('text/plain'));
    const task = board.value.tasks.find((task) => task.id === taskId && task.id === draggedTaskId.value);
    endDrag();

    if (task && task.status !== status) {
        await moveTask(task, status);
    }
}

function dragOverDay(event, cell) {
    if (cell.inMonth && draggedTaskId.value !== null) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        dragDay.value = cell.key;
    }
}

async function dropOnDay(event, cell) {
    const taskId = Number(event.dataTransfer.getData('text/plain'));
    const task = board.value.tasks.find((task) => task.id === taskId && task.id === draggedTaskId.value);
    const payload = calendarDropPayload(task, cell.key, month.value);
    endDrag();
    if (!payload) {
        return;
    }

    error.value = '';
    try {
        await api(`tasks/${task.id}`, 'PATCH', payload);
        await refresh();
    } catch (cause) {
        error.value = cause.message;
        await refresh();
    }
}

watch([projectId, milestoneId, month, includeDone, assignee], () => {
    if (authenticated.value && workspaceLoaded) {
        loadBoard();
    }
});

watch(workspace, () => {
    if (workspaceLoaded && !restoringHistory) {
        syncWorkspaceUrl();
    }
});

onMounted(async () => {
    window.addEventListener('popstate', restoreWorkspace);
    try {
        const session = await api('session');
        authenticated.value = session.authenticated;
        configured.value = session.configured;
        username.value = session.username;

        if (authenticated.value) {
            await initializeWorkspace();
        }
    } catch {
        error.value = 'Unable to connect. Reload the page to try again.';
    } finally {
        ready.value = true;
    }
});

onUnmounted(() => window.removeEventListener('popstate', restoreWorkspace));
</script>

<template>
    <div
        v-if="!ready"
        class="grid min-h-dvh place-items-center text-sm text-stone-500"
    >
        Opening your workspace…
    </div>
    <main
        v-else-if="!authenticated"
        class="grid min-h-dvh place-items-center p-6"
    >
        <div class="w-full max-w-96">
            <div class="mb-10 flex items-center gap-3">
                <img
                    :src="logoUrl"
                    alt=""
                    aria-hidden="true"
                    class="h-11 w-16 shrink-0 object-contain"
                />
                <span class="min-w-0 text-xl leading-tight font-semibold tracking-tight">Me, Myself and AI</span>
            </div>
            <h1 class="text-3xl font-semibold tracking-tight">Sign in</h1>
            <form
                @submit.prevent="signIn"
                class="mt-8 grid gap-4"
            >
                <label>
                    <span class="label">Username</span>
                    <input
                        class="field"
                        v-model="login.username"
                        required
                        autocomplete="username"
                        autofocus
                    />
                </label>
                <label>
                    <span class="label">Password</span>
                    <input
                        class="field"
                        v-model="login.password"
                        required
                        type="password"
                        autocomplete="current-password"
                    />
                </label>
                <p
                    v-if="!configured"
                    class="text-xs leading-5 text-amber-800"
                >
                    Personal access must be configured in the .env file. Follow the README
                    instructions.
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="text-xs leading-5 text-red-700"
                >
                    {{ error }}
                </p>
                <button
                    class="btn btn-primary mt-2"
                    :disabled="saving || !configured"
                >
                    {{ saving ? 'Signing in…' : 'Sign in' }}
                    <Icon
                        name="arrow"
                        :size="15"
                    />
                </button>
            </form>
            <p class="mt-8 text-[11px] text-stone-400">Private access · No sign-up</p>
        </div>
    </main>
    <div
        v-else
        class="
            app-layout grid h-dvh grid-cols-[252px_minmax(0,1fr)] overflow-hidden max-md:h-auto
            max-md:min-h-dvh max-md:grid-cols-1 max-md:overflow-visible
        "
    >
        <aside
            class="
                sidebar flex min-h-0 flex-col border-r border-stone-200/80 bg-[#fbfcf9] px-5
                py-4 max-md:sticky max-md:top-0 max-md:z-20 max-md:grid
                max-md:grid-cols-[minmax(140px,1fr)_minmax(0,180px)] max-md:gap-x-4
                max-md:border-r-0 max-md:border-b max-md:px-4 max-md:py-3
            "
        >
            <div class="mb-5 flex items-center gap-2.5 max-md:mb-3">
                <img
                    :src="logoUrl"
                    alt=""
                    aria-hidden="true"
                    class="h-8 w-10 shrink-0 object-contain"
                />
                <span class="min-w-0 text-lg leading-tight font-semibold tracking-tight">
                    <span class="max-md:hidden">Me, Myself and AI</span>
                    <abbr class="no-underline md:hidden" title="Me, Myself and AI">MMAI</abbr>
                </span>
            </div>
            <div class="max-md:col-start-1 max-md:row-start-2">
                <button
                    class="nav-item"
                    :class="{ 'nav-active': !projectId }"
                    :aria-current="!projectId ? 'true' : undefined"
                    @click="chooseProject('')"
                >
                    <Icon
                        name="grid"
                        :size="16"
                    />
                    All tasks
                    <span class="ml-auto text-[10px] opacity-60">
                        {{ board.projects.reduce((total, p) => total + p.tasks_count, 0) }}
                    </span>
                </button>
            </div>
            <div
                class="mt-5 flex min-h-0 flex-1 flex-col max-md:col-start-1 max-md:row-start-3 max-md:mt-0"
            >
                <div class="mb-3 flex items-center justify-between max-md:hidden">
                    <h2
                        class="text-[10px] font-semibold tracking-[0.14em] text-stone-400 uppercase"
                    >
                        Your projects
                    </h2>
                    <button
                        class="icon-button h-5! w-5!"
                        aria-label="New project"
                        @click="openModal('project')"
                    >
                        <Icon
                            name="plus"
                            :size="14"
                        />
                    </button>
                </div>
                <div class="min-h-0 overflow-y-auto max-md:hidden">
                    <div
                        v-for="project in board.projects"
                        :key="project.id"
                        class="group flex items-center rounded-lg"
                        :class="{
                            'bg-[#e7ede3]': project.id === Number(projectId),
                        }"
                    >
                        <button
                            class="nav-item min-w-0 flex-1"
                            :class="{
                                'text-forest! font-semibold':
                                    project.id === Number(projectId),
                            }"
                            :aria-current="project.id === Number(projectId) ? 'true' : undefined"
                            @click="chooseProject(project.id)"
                        >
                            <span
                                class="h-2 w-2 shrink-0 rounded-xs"
                                :style="{ backgroundColor: project.color }"
                            ></span>
                            <span class="truncate">{{ project.name }}</span>
                            <span class="ml-auto text-[10px] opacity-60">
                                {{ project.tasks_count }}
                            </span>
                        </button>
                        <button
                            class="icon-button mr-1 h-6! w-6! opacity-0 group-focus-within:opacity-100 group-hover:opacity-100"
                            :aria-label="`Edit project ${project.name}`"
                            @click="openModal('project', project)"
                        >
                            <Icon
                                name="edit"
                                :size="12"
                            />
                        </button>
                    </div>
                    <p
                        v-if="!board.projects.length"
                        class="px-3 text-xs leading-5 text-stone-400"
                    >
                        Add your first project.
                    </p>
                </div>
                <div class="hidden gap-1 max-md:flex">
                    <AppSelect
                        class="min-w-0 flex-1"
                        :model-value="projectId"
                        :options="[{ value: '', label: 'All projects' }, ...projectOptions]"
                        aria-label="Filter tasks by project"
                        compact
                        filterable
                        @change="chooseProject"
                    />
                    <button
                        class="icon-button"
                        aria-label="New project"
                        @click="openModal('project')"
                    >
                        <Icon name="plus" />
                    </button>
                    <button
                        v-if="selectedProject"
                        class="icon-button"
                        aria-label="Edit selected project"
                        @click="openModal('project', selectedProject)"
                    >
                        <Icon name="edit" />
                    </button>
                </div>
            </div>
            <div
                class="
                    mt-3 shrink-0 border-t border-stone-200/80 pt-3 max-md:col-start-2
                    max-md:row-span-4 max-md:row-start-1 max-md:mt-0 max-md:border-t-0
                    max-md:pt-0
                "
            >
                <MiniCalendar
                    :month="month"
                    :calendar="miniCalendar"
                    :color="selectedProject?.color"
                    :loading="loading"
                    @month="month = $event"
                    @open="openCalendar"
                />
            </div>
            <div
                class="
                    mt-3 flex shrink-0 items-center justify-between border-t
                    border-stone-200/80 pt-3 max-md:col-start-1 max-md:row-start-4
                    max-md:mt-1 max-md:border-t-0 max-md:pt-0
                "
            >
                <span class="flex items-center gap-2 text-[11px] text-stone-500">
                    <span
                        class="grid h-6 w-6 place-items-center rounded-full bg-[#e7ede3] text-[10px] font-semibold text-forest"
                    >
                        {{ username?.slice(0, 1).toUpperCase() }}
                    </span>
                    {{ username }}
                </span>
                <button
                    class="icon-button"
                    aria-label="Sign out"
                    @click="signOut"
                >
                    <Icon
                        name="logout"
                        :size="15"
                    />
                </button>
            </div>
        </aside>
        <main class="flex min-h-0 min-w-0 flex-col overflow-auto max-md:min-h-[65dvh] max-md:overflow-visible">
            <div
                v-if="error"
                role="alert"
                class="flex items-center justify-between bg-red-50 px-8 py-3 text-xs text-red-800"
            >
                {{ error }}
                <button
                    aria-label="Dismiss message"
                    @click="error = ''"
                >
                    <Icon
                        name="close"
                        :size="14"
                    />
                </button>
            </div>
            <section
                class="
                    z-10 shrink-0 border-b border-stone-200/60 bg-[#f7f8f5]/85 px-8 py-4
                    backdrop-blur-xl md:sticky md:top-0 md:left-0 max-md:px-4
                "
            >
                <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                    <div class="min-w-0 flex-1 basis-40 max-sm:basis-full">
                        <h1 class="truncate text-2xl leading-tight font-semibold tracking-[-0.035em]">
                            {{ view === 'board' ? 'Kanban' : title }}
                        </h1>
                        <p class="mt-1 text-xs leading-5 text-stone-500">
                            <template v-if="view === 'board'">
                                {{ activeTasks.length }} open task{{ activeTasks.length === 1 ? '' : 's' }}
                                <span v-if="undated > 0">· {{ undated }} without a due date</span>
                            </template>
                            <template v-else>
                                See how deadlines are spread out and plan ahead.
                            </template>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1 rounded-lg bg-stone-200/50 p-1">
                        <button
                            class="flex items-center gap-2 rounded-md px-3 py-1.5 text-xs"
                            :class="
                                view === 'board'
                                    ? 'bg-white shadow-xs text-stone-800'
                                    : 'text-stone-500'
                            "
                            @click="view = 'board'"
                            :aria-pressed="view === 'board'"
                        >
                            <Icon
                                name="grid"
                                :size="14"
                            />
                            Kanban
                        </button>
                        <button
                            class="flex items-center gap-2 rounded-md px-3 py-1.5 text-xs"
                            :class="
                                view === 'calendar'
                                    ? 'bg-white shadow-xs text-stone-800'
                                    : 'text-stone-500'
                            "
                            @click="openCalendar"
                            :aria-pressed="view === 'calendar'"
                        >
                            <Icon
                                name="calendar"
                                :size="14"
                            />
                            Calendar
                        </button>
                    </div>
                    <div
                        class="flex min-w-0 flex-wrap items-center gap-2 max-sm:order-last max-sm:w-full"
                        role="group"
                        aria-label="Task filters"
                    >
                        <AppSelect
                            v-model="assignee"
                            :options="[{ value: '', label: 'All assignees' }, ...assigneeOptions]"
                            class="w-34!"
                            :active="!!assignee"
                            aria-label="Assigned to"
                            compact
                        />
                        <div
                            v-if="selectedProject"
                            class="flex h-9 min-w-0 max-w-full items-center rounded-lg border border-stone-200 bg-white shadow-xs transition hover:border-stone-300"
                            :class="{ 'border-emerald-700/30 bg-emerald-50/70': milestoneId }"
                        >
                            <AppSelect
                                v-model="milestoneId"
                                :options="milestoneFilterOptions"
                                class="app-select-joined w-44! min-w-0 max-w-full"
                                :active="!!milestoneId"
                                aria-label="Filter tasks by milestone"
                                compact
                                filterable
                            />
                            <button
                                type="button"
                                class="filter-action bg-emerald-50/70 text-forest hover:bg-emerald-100/70"
                                :class="{ 'rounded-r-lg': !selectedMilestone }"
                                aria-label="New milestone"
                                title="New milestone"
                                @click="openModal('milestone')"
                            >
                                <Icon
                                    name="plus"
                                    :size="14"
                                />
                            </button>
                            <button
                                v-if="selectedMilestone"
                                type="button"
                                class="filter-action rounded-r-lg"
                                :aria-label="`Edit milestone ${selectedMilestone.name}`"
                                title="Edit selected milestone"
                                @click="openModal('milestone', selectedMilestone)"
                            >
                                <Icon
                                    name="edit"
                                    :size="14"
                                />
                            </button>
                        </div>
                        <span
                            v-if="loading"
                            role="status"
                            class="text-[10px] text-stone-400"
                        >
                            Updating…
                        </span>
                    </div>
                    <button
                        class="btn btn-primary min-h-9! shrink-0 py-1.5! max-sm:ml-auto"
                        :disabled="!board.projects.length"
                        @click="openModal('task')"
                    >
                        <Icon
                            name="plus"
                            :size="16"
                        />
                        New task
                    </button>
                </div>
            </section>
            <div
                v-if="!board.projects.length && !loading"
                class="mx-8 mt-4 mb-8 rounded-xl border border-dashed border-stone-300 bg-white p-8 text-center"
            >
                <h2 class="font-semibold">Where would you like to start?</h2>
                <p class="mt-2 text-sm text-stone-500">
                    Create a project and add your first tasks.
                </p>
                <button
                    class="btn btn-primary mt-5"
                    @click="openModal('project')"
                >
                    <Icon
                        name="plus"
                        :size="15"
                    />
                    Create your first project
                </button>
            </div>
            <div
                v-else-if="view === 'board'"
                class="flex-1 px-8 pt-4 pb-8 max-md:overflow-x-auto max-md:px-4"
                aria-label="Task board"
            >
                <div class="grid min-h-full min-w-[960px] grid-cols-5 items-start gap-4">
                    <section
                        v-for="status in statuses"
                        :key="status.key"
                        class="min-h-48 rounded-xl bg-[#edf0e9]/75 p-2.5 transition"
                        :class="{
                            'ring-2 ring-inset ring-emerald-700 bg-emerald-50':
                                dragStatus === status.key,
                        }"
                        :aria-label="status.name"
                        @dragover.prevent="dragStatus = status.key"
                        @dragleave.self="dragStatus = ''"
                        @drop.prevent="dropTask($event, status.key)"
                    >
                        <div class="flex items-center gap-2 px-1.5 pt-1 pb-4">
                            <span
                                class="h-2 w-2 rounded-full"
                                :style="{ backgroundColor: status.color }"
                            ></span>
                            <h2 class="text-xs font-semibold text-stone-700">{{ status.name }}</h2>
                            <span
                                class="rounded-md bg-white/70 px-1.5 py-0.5 text-[10px] text-stone-400"
                            >
                                {{ tasksByStatus[status.key].length }}
                            </span>
                            <button
                                class="icon-button ml-auto h-5! w-5!"
                                :aria-label="`Add task to ${status.name}`"
                                @click="openModal('task', null, { status: status.key })"
                            >
                                <Icon
                                    name="plus"
                                    :size="14"
                                />
                            </button>
                        </div>
                        <div class="grid gap-2.5">
                            <TaskCard
                                v-for="task in tasksByStatus[status.key]"
                                :key="task.id"
                                :task="task"
                                @open="openModal('task', $event)"
                                @open-dependency="openTaskDependency"
                                @drag="startDrag"
                                @dragend="endDrag"
                            />
                        </div>
                        <button
                            class="
                                mt-3 flex w-full items-center gap-2 rounded-lg
                                px-2 py-2 text-[11px] text-stone-400
                                hover:bg-white/60 hover:text-stone-600
                            "
                            @click="openModal('task', null, { status: status.key })"
                        >
                            <Icon
                                name="plus"
                                :size="13"
                            />
                            Add a task
                        </button>
                    </section>
                </div>
            </div>
            <section
                v-else
                class="flex-1 px-8 pt-4 pb-8 max-md:overflow-x-auto max-md:px-4"
                aria-label="Full monthly calendar"
            >
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <button
                            class="icon-button"
                            aria-label="Previous month in full calendar"
                            @click="month = shiftMonth(month, -1)"
                        >
                            <Icon
                                name="arrow"
                                class="rotate-180"
                            />
                        </button>
                        <h2 class="text-lg font-semibold capitalize">
                            {{ monthLabel(month) }}
                        </h2>
                        <button
                            class="icon-button"
                            aria-label="Next month in full calendar"
                            @click="month = shiftMonth(month, 1)"
                        >
                            <Icon name="arrow" />
                        </button>
                        <button
                            class="btn min-h-8! py-1!"
                            @click="month = currentMonth()"
                        >
                            Today
                        </button>
                    </div>
                    <label
                        class="ml-auto inline-flex h-8 shrink-0 cursor-pointer items-center gap-2 rounded-lg border border-stone-200 bg-white px-3 text-xs font-medium text-stone-600 shadow-xs transition hover:border-stone-300"
                        :class="{ 'border-emerald-700/30 bg-emerald-50/70 text-forest': includeDone }"
                    >
                        <input
                            type="checkbox"
                            v-model="includeDone"
                            class="h-3.5 w-3.5 cursor-pointer accent-emerald-800"
                        />
                        with Done
                    </label>
                </div>
                <p class="mb-4 text-xs text-stone-500">
                    {{ board.calendar.total }} task{{
                        board.calendar.total === 1 ? '' : 's'
                    }}
                    due · Drag a task to another day in this month to reschedule it.
                </p>
                <div
                    class="min-w-[750px] overflow-hidden rounded-xl border border-stone-200 bg-white"
                >
                    <div class="grid grid-cols-7 border-b border-stone-200 bg-stone-50">
                        <span
                            v-for="day in [
                                'Monday',
                                'Tuesday',
                                'Wednesday',
                                'Thursday',
                                'Friday',
                                'Saturday',
                                'Sunday',
                            ]"
                            class="px-3 py-3 text-[10px] font-medium text-stone-500"
                        >
                            {{ day }}
                        </span>
                    </div>
                    <div class="grid grid-cols-7">
                        <div
                            v-for="cell in monthCells(month)"
                            :key="cell.key"
                            class="min-h-32 border-r border-b border-stone-100 p-2"
                            :class="{
                                'bg-stone-50 text-stone-400': !cell.inMonth,
                                'bg-emerald-50 inset-ring-2 inset-ring-emerald-700': dragDay === cell.key,
                            }"
                            :data-date="cell.key"
                            @dragover="dragOverDay($event, cell)"
                            @dragleave.self="dragDay = ''"
                            @drop.prevent="dropOnDay($event, cell)"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <button
                                    class="grid h-6 w-6 place-items-center rounded-full text-[11px]"
                                    :class="
                                        cell.today
                                            ? 'bg-forest text-white font-semibold'
                                            : 'hover:bg-stone-100'
                                    "
                                    :aria-label="`New task due on ${cell.key}`"
                                    @click="
                                        openModal('task', null, {
                                            due_date: cell.key,
                                        })
                                    "
                                >
                                    {{ cell.day }}
                                </button>
                                <span
                                    v-if="extendedDays[cell.key]"
                                    class="text-[10px] text-stone-400"
                                >
                                    {{ extendedDays[cell.key].count }}
                                </span>
                            </div>
                            <button
                                v-for="task in extendedDays[cell.key]?.tasks || []"
                                :key="task.id"
                                @click="openModal('task', task)"
                                draggable="true"
                                @dragstart="startDrag($event, task)"
                                @dragend="endDrag"
                                class="
                                    mb-1.5 block w-full rounded-md
                                    border-l-2 bg-[#f1f5ef] px-2 py-1.5
                                    text-left text-[10px] leading-4
                                    hover:bg-[#e5eddf]
                                "
                                :style="{ borderColor: task.project.color }"
                            >
                                <span class="block truncate text-[9px] text-stone-500">
                                    {{ task.project.name }}
                                </span>
                                <!-- Keep the inline labels adjacent to preserve calendar wrapping. -->
                                <span
                                    :class="{ 'line-through opacity-60': task.status === 'done' }"
                                >{{ task.name }}</span><span
                                    v-if="task.priority === 'high'"
                                    class="ml-1 text-red-700"
                                >•</span>
                                <span class="mt-1 block text-[9px] text-stone-500">
                                    {{ assignees[task.assignee] }}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <dialog
        ref="dialog"
        class="workspace-dialog max-w-[calc(100%-1.5rem)] rounded-2xl border border-stone-200 bg-[#fbfcf9] p-0 shadow-2xl"
        :class="modalType === 'task' ? 'task-dialog w-[900px]' : 'w-[480px]'"
        @close="handleDialogClose"
        @cancel.prevent="closeModal"
        @click="closeOnBackdrop"
    >
        <form @submit.prevent="saveForm">
            <div class="dialog-header flex items-center justify-between border-b border-stone-200 px-5 py-3">
                <div>
                    <h2 class="text-lg font-semibold">
                        {{ editingId ? 'Edit' : 'New' }} {{ modalType }}
                    </h2>
                </div>
                <button
                    type="button"
                    class="icon-button"
                    aria-label="Close dialog"
                    @click="closeModal"
                >
                    <Icon name="close" />
                </button>
            </div>
            <div
                class="dialog-body grid min-w-0 gap-3 p-5 max-sm:p-4"
                :class="modalType === 'task' ? 'task-form grid-cols-2' : 'grid-cols-1'"
            >
                <label class="col-span-full min-w-0">
                    <span class="label">
                        Name
                        <span class="text-emerald-700">*</span>
                    </span>
                    <input
                        class="field min-w-0"
                        v-model="form.name"
                        required
                        :maxlength="
                            modalType === 'task' ? 200 : modalType === 'project' ? 100 : 150
                        "
                        autofocus
                    />
                </label>
                <template v-if="modalType !== 'project'">
                    <label class="min-w-0">
                        <span class="label">
                            Project
                            <span class="text-emerald-700">*</span>
                        </span>
                        <AppSelect
                            v-model="form.project_id"
                            :options="projectOptions"
                            aria-label="Project"
                            aria-required="true"
                            placeholder="Select a project"
                            filterable
                            :disabled="modalType === 'milestone' && !!editingId"
                            @change="changeFormProject"
                        />
                    </label>
                    <label v-if="modalType === 'task'" class="min-w-0">
                        <span class="label">
                            Milestone
                            <span class="font-normal text-stone-400">· optional</span>
                        </span>
                        <AppSelect
                            v-model="form.milestone_id"
                            :options="milestoneFormOptions"
                            aria-label="Milestone"
                            filterable
                        />
                    </label>
                    <TaskDependencySelect
                        v-if="modalType === 'task' && modalOpen"
                        class="col-span-full"
                        v-model="form.depends_on_task_id"
                        :project-id="form.project_id"
                        :task-id="editingId"
                        :selected-task="formDependency"
                        :fetch-tasks="fetchDependencyTasks"
                        :disabled="saving || openingDependency"
                        @select="formDependency = $event"
                        @open="openTaskDependency"
                    />
                    <label v-if="modalType === 'task'" class="form-description flex min-h-0 min-w-0 flex-col max-sm:col-span-full">
                        <span class="label">Description</span>
                        <textarea
                            class="field min-h-0 min-w-0 flex-1 resize-none"
                            v-model="form.description"
                            rows="4"
                            maxlength="20000"
                            placeholder="What needs to be done?"
                        ></textarea>
                    </label>
                    <div
                        class="grid min-w-0 content-start gap-3 max-sm:col-span-full"
                        :class="modalType === 'task' ? 'grid-cols-2' : 'grid-cols-1'"
                    >
                        <label class="min-w-0">
                            <span class="label">
                                Due date {{ modalType === 'milestone' ? '*' : '' }}
                            </span>
                            <input
                                class="field min-w-0 max-w-full"
                                type="date"
                                v-model="form.due_date"
                                :required="modalType === 'milestone'"
                            />
                        </label>
                        <template v-if="modalType === 'task'">
                            <label class="min-w-0">
                                <span class="label">Priority</span>
                                <AppSelect v-model="form.priority" :options="priorityOptions" aria-label="Priority" />
                            </label>
                            <label class="min-w-0">
                                <span class="label">Status</span>
                                <AppSelect v-model="form.status" :options="statusOptions" aria-label="Status" />
                            </label>
                            <label class="min-w-0">
                                <span class="label">Assigned to</span>
                                <AppSelect v-model="form.assignee" :options="assigneeOptions" aria-label="Assigned to" />
                            </label>
                        </template>
                    </div>
                    <p v-if="modalType === 'milestone'" class="text-[11px] leading-5 text-stone-400">
                        Milestone and task due dates are independent.
                    </p>
                </template>
                <fieldset v-else>
                    <legend class="label">Project color</legend>
                    <div class="flex flex-wrap items-center gap-3 px-1 py-2">
                        <label
                            v-for="color in projectColors"
                            :key="color.value"
                            :title="color.name"
                            class="relative flex h-10 w-10 cursor-pointer items-center justify-center rounded-full"
                        >
                            <input
                                type="radio"
                                name="project-color"
                                v-model="form.color"
                                :value="color.value"
                                :aria-label="color.name"
                                required
                                class="peer sr-only"
                            />
                            <span
                                class="
                                    absolute inset-0 rounded-full
                                    ring-offset-[#fbfcf9]
                                    peer-checked:ring-2
                                    peer-checked:ring-current
                                    peer-checked:ring-offset-3
                                    peer-focus-visible:outline-2
                                    peer-focus-visible:outline-offset-4
                                "
                                :style="{ backgroundColor: color.value, color: color.value }"
                            ></span>
                            <Icon
                                name="check"
                                :size="20"
                                class="pointer-events-none relative text-white opacity-0 peer-checked:opacity-100"
                            />
                        </label>
                    </div>
                </fieldset>
                <p
                    v-if="formError"
                    role="alert"
                    class="col-span-full rounded-lg bg-red-50 px-3 py-2 text-xs leading-5 text-red-800"
                >
                    {{ formError }}
                </p>
            </div>
            <div class="dialog-footer flex items-center justify-between border-t border-stone-200 px-5 py-3">
                <button
                    v-if="editingId"
                    type="button"
                    class="icon-button text-red-700!"
                    :disabled="saving"
                    aria-label="Delete"
                    @click="deleteItem"
                >
                    <Icon
                        name="trash"
                        :size="17"
                    />
                </button>
                <span v-else></span>
                <div class="flex gap-2">
                    <button
                        type="button"
                        class="btn"
                        :disabled="saving"
                        @click="closeModal"
                    >
                        Cancel
                    </button>
                    <button
                        class="btn btn-primary"
                        :disabled="saving"
                    >
                        {{ saving ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </form>
    </dialog>
</template>
