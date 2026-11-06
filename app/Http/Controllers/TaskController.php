<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        return response()->json(['data' => Task::query()
            ->where('project_id', $filters['project_id'])
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'status', 'project_id'])]);
    }

    public function show(Task $task): TaskResource
    {
        return new TaskResource($task->load(['project', 'milestone', 'dependsOn']));
    }

    public function store(TaskRequest $request): TaskResource
    {
        $task = Task::create($request->validated());

        return new TaskResource($task->load(['project', 'milestone', 'dependsOn']));
    }

    public function update(TaskRequest $request, Task $task): TaskResource
    {
        $task->update($request->validated());

        return new TaskResource($task->load(['project', 'milestone', 'dependsOn']));
    }

    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }
}
