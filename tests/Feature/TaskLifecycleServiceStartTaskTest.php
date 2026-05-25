<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TaskLifecycleServiceStartTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_start_enqueues_after_outer_transaction_commits(): void
    {
        $task = Task::query()->create([
            'name' => 'Manual start task',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $baselineTransactionLevel = DB::transactionLevel();

        $jobQueue = Mockery::mock(JobQueueService::class);
        $jobQueue->shouldReceive('initializeTaskSchedule')
            ->once()
            ->with($task->id);
        $jobQueue->shouldReceive('enqueueTaskJob')
            ->once()
            ->with($task->id, 'generate_article', ['source' => 'api_manual_start'])
            ->andReturnUsing(function () use ($task, $baselineTransactionLevel): int {
                $this->assertSame($baselineTransactionLevel, DB::transactionLevel(), 'manual start should dispatch after commit');

                $freshTask = Task::query()->findOrFail($task->id);
                $this->assertSame('active', $freshTask->status);
                $this->assertSame(1, (int) $freshTask->schedule_enabled);

                return 123;
            });
        $this->app->instance(JobQueueService::class, $jobQueue);

        /** @var TaskLifecycleService $service */
        $service = $this->app->make(TaskLifecycleService::class);
        $result = $service->startTask($task->id, true);

        $this->assertSame(123, $result['started_job_id']);
    }
}
