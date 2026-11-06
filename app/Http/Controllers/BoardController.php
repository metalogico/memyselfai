<?php

namespace App\Http\Controllers;

use App\Http\Resources\TaskResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BoardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['required_with:milestone_id', 'nullable', 'integer', Rule::exists('projects', 'id')],
            'milestone_id' => ['nullable', 'integer', Rule::exists('milestones', 'id')->where('project_id', $request->integer('project_id'))],
            'month' => ['sometimes', 'date_format:Y-m'],
            'include_done' => ['sometimes', 'boolean'],
            'assignee' => ['sometimes', 'required', Rule::in(Task::ASSIGNEES)],
        ]);
        $month = CarbonImmutable::createFromFormat('!Y-m', $filters['month'] ?? now()->format('Y-m'));
        $monthKey = $month->format('Y-m');
        $includeDone = $request->boolean('include_done', config('memyselfai.calendar_include_done'));

        $tasks = Task::query()
            ->with(['project', 'milestone', 'dependsOn'])
            ->when($filters['project_id'] ?? null, fn ($query, $projectId) => $query->where('project_id', $projectId))
            ->when($filters['milestone_id'] ?? null, fn ($query, $milestoneId) => $query->where('milestone_id', $milestoneId))
            ->when($filters['assignee'] ?? null, fn ($query, $assignee) => $query->where('assignee', $assignee))
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        // Month and completion filters do not remove undated or completed tasks from the board.
        $scheduledTasks = $tasks->filter(fn (Task $task) => $task->due_date
            && $task->due_date->format('Y-m') === $monthKey
            && ($includeDone || $task->status !== 'done'));
        $calendarDays = $scheduledTasks
            ->groupBy(fn (Task $task) => $task->due_date->toDateString())
            ->map(fn ($dayTasks, $date) => [
                'date' => $date,
                'count' => $dayTasks->count(),
                'tasks' => TaskResource::collection($dayTasks->values())->resolve($request),
            ])
            ->values();

        $projects = Project::query()
            ->withCount('tasks')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'color']);
        $milestones = Milestone::query()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'name', 'due_date', 'project_id']);

        return response()->json([
            'projects' => $projects,
            'milestones' => $milestones,
            'tasks' => TaskResource::collection($tasks)->resolve($request),
            'calendar' => [
                'month' => $monthKey,
                'project_id' => isset($filters['project_id']) ? (int) $filters['project_id'] : null,
                'milestone_id' => isset($filters['milestone_id']) ? (int) $filters['milestone_id'] : null,
                'assignee' => $filters['assignee'] ?? null,
                'include_done' => $includeDone,
                'days' => $calendarDays,
                'total' => $scheduledTasks->count(),
            ],
            'defaults' => ['calendar_include_done' => config('memyselfai.calendar_include_done')],
        ]);
    }
}
