<?php

use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['database.connections.dependency_migration' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]);
});

afterEach(function (): void {
    DB::purge('dependency_migration');
});

it('upgrades and rolls back existing tasks without changing their data', function (): void {
    $migration = 'database/migrations/2026_11_06_171311_add_depends_on_task_id_to_tasks_table.php';
    $previous = array_values(array_filter(glob(database_path('migrations/*.php')),
        fn (string $path): bool => basename($path) < basename($migration)));
    $this->artisan('migrate', [
        '--database' => 'dependency_migration', '--path' => $previous, '--realpath' => true, '--force' => true,
    ])->assertSuccessful();
    $database = DB::connection('dependency_migration');
    $projectId = $database->table('projects')->insertGetId(['name' => 'Existing project']);
    $taskId = $database->table('tasks')->insertGetId([
        'name' => 'Existing task', 'description' => 'Keep the details', 'project_id' => $projectId,
        'assignee' => 'agent', 'status' => 'in_progress', 'due_date' => '2026-11-10',
    ]);
    $original = (array) $database->table('tasks')->find($taskId);

    $this->artisan('migrate', [
        '--database' => 'dependency_migration', '--path' => $migration, '--force' => true,
    ])->assertSuccessful();
    expect((array) $database->table('tasks')->find($taskId))->toBe([...$original, 'depends_on_task_id' => null]);

    $this->artisan('migrate:rollback', [
        '--database' => 'dependency_migration', '--path' => $migration, '--step' => 1, '--force' => true,
    ])->assertSuccessful();
    expect((array) $database->table('tasks')->find($taskId))->toBe($original);
});
