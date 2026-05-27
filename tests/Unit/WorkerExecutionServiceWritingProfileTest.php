<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceWritingProfileTest extends TestCase
{
    public function test_smart_random_type_prefers_comparison_for_vs_titles(): void
    {
        $task = new Task([
            'article_type_mode' => 'smart_random',
            'article_type_options' => json_encode(['explainer', 'comparison', 'tutorial'], JSON_UNESCAPED_UNICODE),
            'writing_style_mode' => 'fixed',
            'writing_style_options' => json_encode(['professional'], JSON_UNESCAPED_UNICODE),
            'length_mode' => 'short',
        ]);

        $profile = $this->resolveGenerationProfile($task, 'DeepSeek vs OpenAI: 哪个更适合企业知识库？', 'DeepSeek vs OpenAI');

        $this->assertSame('comparison', $profile['article_type']);
        $this->assertSame('professional', $profile['writing_style']);
        $this->assertSame('short', $profile['length_mode']);
    }

    public function test_random_style_is_selected_from_checked_pool(): void
    {
        $task = new Task([
            'article_type_mode' => 'fixed',
            'article_type_options' => json_encode(['explainer'], JSON_UNESCAPED_UNICODE),
            'writing_style_mode' => 'random',
            'writing_style_options' => json_encode(['consultant'], JSON_UNESCAPED_UNICODE),
            'length_mode' => 'custom',
            'length_min' => 320,
            'length_max' => 520,
        ]);

        $profile = $this->resolveGenerationProfile($task, '什么是 AI CRM？', 'AI CRM');

        $this->assertSame('explainer', $profile['article_type']);
        $this->assertSame('consultant', $profile['writing_style']);
        $this->assertSame('custom', $profile['length_mode']);
        $this->assertSame(320, $profile['length_min']);
        $this->assertSame(520, $profile['length_max']);
    }

    public function test_report_style_title_is_rewritten_for_explainer_output(): void
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'rewriteGeneratedTitle');
        $method->setAccessible(true);

        $rewritten = (string) $method->invoke(
            $service,
            'AI交易机器人行业发展趋势报告',
            'AI交易机器人',
            ['article_type' => 'explainer', 'writing_style' => 'professional']
        );

        $this->assertMatchesRegularExpression(
            '/^AI交易机器人(到底是什么？|入门指南|全解析|适合谁？|为什么重要？|核心要点)$/u',
            $rewritten
        );
    }

    public function test_report_style_title_is_rewritten_for_tutorial_output(): void
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'rewriteGeneratedTitle');
        $method->setAccessible(true);

        $rewritten = (string) $method->invoke(
            $service,
            '黄金AI交易的深度分析与研究',
            '黄金AI交易',
            ['article_type' => 'tutorial', 'writing_style' => 'educational']
        );

        $this->assertMatchesRegularExpression(
            '/^黄金AI交易(怎么做？|上手指南|实操步骤|完整教程|入门教程)$/u',
            $rewritten
        );
    }

    public function test_rewrite_generated_title_rotates_explainer_family_when_question_titles_repeat(): void
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'rewriteGeneratedTitle');
        $method->setAccessible(true);

        $rewritten = (string) $method->invoke(
            $service,
            'AI CRM 行业发展趋势报告',
            'AI CRM',
            ['article_type' => 'explainer', 'writing_style' => 'professional'],
            [
                'AI CRM到底是什么？',
                'AI CRM到底是什么？',
                'AI CRM为什么重要？',
            ]
        );

        $this->assertNotSame('AI CRM到底是什么？', $rewritten);
        $this->assertMatchesRegularExpression('/^AI CRM(入门指南|全解析|适合谁？|为什么重要？|核心要点)$/u', $rewritten);
    }

    /**
     * @return array{article_type:string,writing_style:string,length_mode:string,length_min:int|null,length_max:int|null}
     */
    private function resolveGenerationProfile(Task $task, string $title, string $keyword): array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'resolveGenerationProfile');
        $method->setAccessible(true);

        /** @var array{article_type:string,writing_style:string,length_mode:string,length_min:int|null,length_max:int|null} $profile */
        $profile = $method->invoke($service, $task, $title, $keyword);

        return $profile;
    }
}
