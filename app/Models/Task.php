<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public const STATUSES = ['backlog', 'todo', 'in_progress', 'in_review', 'done'];

    public const PRIORITIES = ['high', 'medium', 'low'];

    public const ASSIGNEES = ['me', 'agent'];

    protected $fillable = ['name', 'description', 'priority', 'status', 'assignee', 'due_date', 'project_id', 'milestone_id', 'depends_on_task_id'];

    protected $attributes = ['priority' => 'medium', 'status' => 'backlog', 'assignee' => 'me'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'depends_on_task_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Task $task): void {
            if (! $task->isDirty(['project_id', 'depends_on_task_id'])) {
                return;
            }

            if ($task->exists && $task->isDirty('project_id') && $task->dependentTasks()->exists()) {
                throw ValidationException::withMessages([
                    'project_id' => 'Remove incoming task dependencies before changing projects.',
                ]);
            }

            if ($task->depends_on_task_id === null) {
                return;
            }

            if ($task->depends_on_task_id === $task->id) {
                throw ValidationException::withMessages([
                    'depends_on_task_id' => 'A task cannot depend on itself.',
                ]);
            }

            $dependencies = static::query()->where('project_id', $task->project_id)
                ->pluck('depends_on_task_id', 'id');

            if (! $dependencies->has($task->depends_on_task_id)) {
                throw ValidationException::withMessages([
                    'depends_on_task_id' => 'The dependency must be an existing task in the same project.',
                ]);
            }

            $visited = $task->exists ? [$task->id => true] : [];
            $dependencyId = $task->depends_on_task_id;

            while ($dependencyId !== null) {
                if (isset($visited[$dependencyId])) {
                    throw ValidationException::withMessages([
                        'depends_on_task_id' => 'This dependency would create a circular task dependency.',
                    ]);
                }

                $visited[$dependencyId] = true;
                $dependencyId = $dependencies->get($dependencyId);
            }
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_task_id');
    }

    public function dependentTasks(): HasMany
    {
        return $this->hasMany(self::class, 'depends_on_task_id');
    }
}
