export function dependencyOptions(tasks, projectId, taskId, search = '') {
    const query = search.trim().toLocaleLowerCase();

    return tasks.filter((task) =>
        task.project_id === Number(projectId)
        && task.id !== Number(taskId)
        && task.name.toLocaleLowerCase().includes(query),
    );
}

export function createDependencyLoader(fetchTasks, state) {
    let latestRequest = 0;

    return {
        async load(projectId) {
            const request = ++latestRequest;
            state.tasks = [];
            state.error = '';
            state.loading = !!projectId;

            if (!projectId) {
                return;
            }

            try {
                const tasks = await fetchTasks(Number(projectId));
                if (request === latestRequest) {
                    state.tasks = tasks;
                }
            } catch (cause) {
                if (request === latestRequest) {
                    state.error = cause.message;
                }
            } finally {
                if (request === latestRequest) {
                    state.loading = false;
                }
            }
        },
        cancel() {
            latestRequest++;
        },
    };
}
