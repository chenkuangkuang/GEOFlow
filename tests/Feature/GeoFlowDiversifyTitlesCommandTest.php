<?php

namespace Tests\Feature;

use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoFlowDiversifyTitlesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rewrites_repetitive_title_library_entries(): void
    {
        $library = TitleLibrary::query()->create([
            'name' => 'AI 内容标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'keyword_library_id' => null,
            'ai_model_id' => null,
            'prompt_id' => null,
            'generation_rounds' => 0,
            'is_ai_generated' => 0,
        ]);

        foreach ([
            ['AI CRM', 'AI CRM到底是什么？'],
            ['AI CRM', 'AI CRM到底是什么？'],
            ['AI CRM', 'AI CRM到底是什么？'],
            ['AI CRM', 'AI CRM到底是什么？'],
        ] as [$keyword, $title]) {
            Title::query()->create([
                'library_id' => (int) $library->id,
                'title' => $title,
                'keyword' => $keyword,
                'is_ai_generated' => true,
                'used_count' => 0,
                'usage_count' => 0,
            ]);
        }

        $this->artisan('geoflow:diversify-titles', [
            '--library' => (string) $library->id,
        ])->assertSuccessful();

        $titles = Title::query()->where('library_id', (int) $library->id)->orderBy('id')->pluck('title')->all();

        $this->assertCount(4, $titles);
        $this->assertTrue(collect($titles)->contains(fn (string $title): bool => $title !== 'AI CRM到底是什么？'));
        $this->assertGreaterThan(1, count(array_unique($titles)));
        $this->assertCount(count($titles), array_unique($titles));
    }

    public function test_command_avoids_duplicate_rewrites_even_when_same_subject_entries_are_far_apart(): void
    {
        $library = TitleLibrary::query()->create([
            'name' => '跨度去重标题库',
            'description' => '',
            'title_count' => 0,
            'generation_type' => 'manual',
            'keyword_library_id' => null,
            'ai_model_id' => null,
            'prompt_id' => null,
            'generation_rounds' => 0,
            'is_ai_generated' => 0,
        ]);

        Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => '均线交叉策略行业发展趋势报告',
            'keyword' => '均线交叉策略',
            'is_ai_generated' => true,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        foreach (range(1, 8) as $index) {
            Title::query()->create([
                'library_id' => (int) $library->id,
                'title' => '填充主题'.$index.'行业发展趋势报告',
                'keyword' => '填充主题'.$index,
                'is_ai_generated' => true,
                'used_count' => 0,
                'usage_count' => 0,
            ]);
        }

        Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => '均线交叉策略行业发展趋势报告',
            'keyword' => '均线交叉策略',
            'is_ai_generated' => true,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->artisan('geoflow:diversify-titles', [
            '--library' => (string) $library->id,
        ])->assertSuccessful();

        $titles = Title::query()
            ->where('library_id', (int) $library->id)
            ->where('keyword', '均线交叉策略')
            ->orderBy('id')
            ->pluck('title')
            ->all();

        $this->assertCount(2, $titles);
        $this->assertNotSame($titles[0], $titles[1]);
    }
}
