export function filterTasks(tasks, search) {
    const query = search.trim().toLocaleLowerCase();

    if (!query) {
        return tasks;
    }

    return tasks.filter((task) =>
        task.name.toLocaleLowerCase().includes(query)
        || (task.description ?? '').toLocaleLowerCase().includes(query),
    );
}

export function filterTaskCalendar(calendar, search) {
    const days = calendar.days.flatMap((day) => {
        const tasks = filterTasks(day.tasks, search);

        return tasks.length ? [{ ...day, tasks, count: tasks.length }] : [];
    });

    return {
        ...calendar,
        days,
        total: days.reduce((total, day) => total + day.count, 0),
    };
}
