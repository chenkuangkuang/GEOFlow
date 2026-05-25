<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\GeoFlow\JobQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class GeoFlowScheduleTasksCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_waits_for_publish_time_when_publishable_draft_already_exists(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'GEOFlow']);
        $nextPublishAt = Carbon::now()->addSeconds(45);

        $task = Task::query()->create([
            'name' => 'Queued publish task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'need_review' => 0,
            'draft_limit' => 10,
            'article_limit' => 30,
            'created_count' => 1,
            'publish_interval' => 60,
            'next_run_at' => Carbon::now()->subSecond(),
            'next_publish_at' => $nextPublishAt,
        ]);

        Article::query()->create([
            'title' => 'AI CRM 到底是什么？',
            'slug' => 'ai-crm-what-is',
            'excerpt' => 'summary',
            'content' => '# AI CRM',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
        ]);

        $jobQueue = Mockery::mock(JobQueueService::class);
        $jobQueue->shouldReceive('recoverStaleJobs')->once()->andReturn(0);
        $jobQueue->shouldReceive('enqueueTaskJob')->never();
        $jobQueue->shouldReceive('initializeTaskSchedule')->never();
        $this->app->instance(JobQueueService::class, $jobQueue);

        $this->artisan('geoflow:schedule-tasks')
            ->expectsOutputToContain('queued=0')
            ->assertSuccessful();

        $task->refresh();

        $this->assertTrue($task->next_run_at !== null);
        $this->assertSame(
            $nextPublishAt->toDateTimeString(),
            $task->next_run_at?->toDateTimeString()
        );
    }
}
