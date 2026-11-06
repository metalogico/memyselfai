<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\PersonalAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['memyselfai.username' => 'test-user', 'memyselfai.password_hash' => Hash::make('test-password-123')]);
    $this->withSession(['personal_access' => app(PersonalAccess::class)->fingerprint()]);
});

it('creates and reads a dependency without recursively exposing prerequisite details', function (): void {
    $previous = Task::factory()->create(['name' => 'Prepare the API']);

    $id = $this->postJson('/api/v1/tasks', [
        'name' => 'Build the interface', 'project_id' => $previous->project_id,
        'depends_on_task_id' => $previous->id, 'due_date' => '2026-11-10',
    ])->assertCreated()->assertJsonPath('data.depends_on_task_id', $previous->id)
        ->assertJsonPath('data.depends_on', ['id' => $previous->id, 'name' => 'Prepare the API'])->json('data.id');

    $this->assertDatabaseHas('tasks', ['id' => $id, 'depends_on_task_id' => $previous->id]);
    $this->getJson('/api/v1/tasks/'.$id)->assertOk()->assertJsonPath('data.depends_on.name', 'Prepare the API');
    $this->getJson('/api/v1/board?month=2026-11')->assertOk()
        ->assertJsonPath('calendar.days.0.tasks.0.depends_on.name', 'Prepare the API');
});

it('replaces clears and preserves dependencies during partial updates without blocking completion', function (): void {
    $first = Task::factory()->create();
    $second = Task::factory()->for($first->project)->create();
    $task = Task::factory()->for($first->project)->create(['depends_on_task_id' => $first->id]);

    $this->patchJson('/api/v1/tasks/'.$task->id, ['depends_on_task_id' => $second->id])
        ->assertOk()->assertJsonPath('data.depends_on_task_id', $second->id);
    $this->patchJson('/api/v1/tasks/'.$task->id, ['status' => 'done'])
        ->assertOk()->assertJsonPath('data.status', 'done')->assertJsonPath('data.depends_on_task_id', $second->id);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'done', 'depends_on_task_id' => $second->id]);
    $this->patchJson('/api/v1/tasks/'.$task->id, ['depends_on_task_id' => null])
        ->assertOk()->assertJsonPath('data.depends_on_task_id', null)->assertJsonPath('data.depends_on', null);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'depends_on_task_id' => null]);
});

it('rejects invalid dependency values with 422 without changing other fields', function (mixed $value): void {
    $task = Task::factory()->create(['name' => 'Keep this name']);

    $this->patchJson('/api/v1/tasks/'.$task->id, ['name' => 'Invalid update', 'depends_on_task_id' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('depends_on_task_id');

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'name' => 'Keep this name', 'depends_on_task_id' => null]);
})->with(['missing task' => [99999], 'text' => ['invalid'], 'zero' => [0], 'multiple tasks' => [[1, 2]]]);

it('rejects dependencies in another project with 422 when creating or updating', function (): void {
    $previous = Task::factory()->create();
    $task = Task::factory()->create();

    $this->postJson('/api/v1/tasks', [
        'name' => 'Invalid task', 'project_id' => $task->project_id, 'depends_on_task_id' => $previous->id,
    ])->assertUnprocessable()->assertInvalid([
        'depends_on_task_id' => 'The dependency must be an existing task in the same project.',
    ]);
    $this->patchJson('/api/v1/tasks/'.$task->id, ['depends_on_task_id' => $previous->id])
        ->assertUnprocessable()->assertJsonValidationErrors('depends_on_task_id');

    $this->assertDatabaseCount('tasks', 2);
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'depends_on_task_id' => null]);
});

it('rejects a self dependency with 422', function (): void {
    $task = Task::factory()->create();

    $this->patchJson('/api/v1/tasks/'.$task->id, ['depends_on_task_id' => $task->id])
        ->assertUnprocessable()->assertInvalid(['depends_on_task_id' => 'A task cannot depend on itself.']);

    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'depends_on_task_id' => null]);
});

it('rejects direct and indirect dependency cycles with 422', function (int $length): void {
    $first = Task::factory()->create();
    $last = $first;
    for ($index = 0; $index < $length; $index++) {
        $last = Task::factory()->for($first->project)->create(['depends_on_task_id' => $last->id]);
    }

    $this->patchJson('/api/v1/tasks/'.$first->id, ['depends_on_task_id' => $last->id])
        ->assertUnprocessable()->assertInvalid([
            'depends_on_task_id' => 'This dependency would create a circular task dependency.',
        ]);

    $this->assertDatabaseHas('tasks', ['id' => $first->id, 'depends_on_task_id' => null]);
})->with(['direct cycle' => 1, 'indirect cycle' => 3]);

it('keeps dependent tasks and clears their links when deleting the prerequisite', function (): void {
    $previous = Task::factory()->create();
    $dependent = Task::factory()->for($previous->project)->create(['depends_on_task_id' => $previous->id]);

    $this->deleteJson('/api/v1/tasks/'.$previous->id)->assertNoContent();

    $this->assertModelMissing($previous);
    $this->assertDatabaseHas('tasks', ['id' => $dependent->id, 'depends_on_task_id' => null]);
    $this->getJson('/api/v1/tasks/'.$dependent->id)->assertOk()->assertJsonPath('data.depends_on', null);
});

it('requires clearing outgoing dependencies before moving projects with 422', function (): void {
    $previous = Task::factory()->create();
    $task = Task::factory()->for($previous->project)->create(['depends_on_task_id' => $previous->id]);
    $other = Project::factory()->create();

    $this->patchJson('/api/v1/tasks/'.$task->id, ['project_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('depends_on_task_id');
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $previous->project_id]);
    $this->patchJson('/api/v1/tasks/'.$task->id, ['project_id' => $other->id, 'depends_on_task_id' => null])->assertOk();
    $this->assertDatabaseHas('tasks', ['id' => $task->id, 'project_id' => $other->id, 'depends_on_task_id' => null]);
});

it('rejects moving a prerequisite to another project with 422 until its incoming links are removed', function (): void {
    $previous = Task::factory()->create();
    $dependent = Task::factory()->for($previous->project)->create(['depends_on_task_id' => $previous->id]);
    $other = Project::factory()->create();

    $this->patchJson('/api/v1/tasks/'.$previous->id, ['project_id' => $other->id])
        ->assertUnprocessable()->assertInvalid([
            'project_id' => 'Remove incoming task dependencies before changing projects.',
        ]);
    $this->assertDatabaseHas('tasks', ['id' => $previous->id, 'project_id' => $dependent->project_id]);
    $this->patchJson('/api/v1/tasks/'.$dependent->id, ['depends_on_task_id' => null])->assertOk();
    $this->patchJson('/api/v1/tasks/'.$previous->id, ['project_id' => $other->id])->assertOk();
    $this->assertDatabaseHas('tasks', ['id' => $previous->id, 'project_id' => $other->id]);
});

it('lists all project task choices regardless of board filters and reads a hidden prerequisite by ID', function (): void {
    $milestone = Milestone::factory()->create();
    $previous = Task::factory()->for($milestone->project)->create(['name' => 'Alpha', 'status' => 'done', 'assignee' => 'me']);
    $other = Task::factory()->for($milestone->project)->create(['name' => 'Alpha']);
    $task = Task::factory()->for($milestone->project)->for($milestone)->create([
        'name' => 'Beta', 'assignee' => 'agent', 'depends_on_task_id' => $previous->id,
    ]);
    Task::factory()->create();

    $this->getJson('/api/v1/board?project_id='.$task->project_id.'&milestone_id='.$milestone->id.'&assignee=agent')
        ->assertOk()->assertJsonCount(1, 'tasks')->assertJsonPath('tasks.0.depends_on.id', $previous->id);
    $this->getJson('/api/v1/tasks?project_id='.$task->project_id.'&assignee=agent&milestone_id='.$milestone->id)
        ->assertOk()->assertExactJson(['data' => [
            ['id' => $previous->id, 'name' => 'Alpha', 'status' => 'done', 'project_id' => $task->project_id],
            ['id' => $other->id, 'name' => 'Alpha', 'status' => 'backlog', 'project_id' => $task->project_id],
            ['id' => $task->id, 'name' => 'Beta', 'status' => 'backlog', 'project_id' => $task->project_id],
        ]]);
    $this->getJson('/api/v1/tasks/'.$previous->id)->assertOk()->assertJsonPath('data.id', $previous->id);
    $this->getJson('/api/v1/tasks/99999')->assertNotFound();
});

it('requires an existing project for task choices with 422', function (string $query): void {
    $this->getJson('/api/v1/tasks'.$query)->assertUnprocessable()->assertJsonValidationErrors('project_id');
})->with(['missing project' => '', 'unknown project' => '?project_id=99999', 'invalid project' => '?project_id=no']);

it('requires a personal session for task lookup and details with 401', function (string $path): void {
    $this->flushSession();

    $this->getJson($path)->assertUnauthorized();
})->with(['/api/v1/tasks?project_id=1', '/api/v1/tasks/1']);
