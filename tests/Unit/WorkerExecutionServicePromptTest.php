<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServicePromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_prompt_without_variables_receives_smart_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇专业、可信、适合 GEO 引用的文章。',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('请写一篇专业、可信、适合 GEO 引用的文章。', $prompt);
        $this->assertStringContainsString('【任务上下文】', $prompt);
        $this->assertStringContainsString('- 文章标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('- 核心关键词：AI CRM', $prompt);
        $this->assertStringContainsString('这是来自知识库的参考资料。', $prompt);
    }

    public function test_prompt_with_variables_keeps_precise_rendering_without_extra_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '标题：{{title}}'."\n".'{{#if keyword}}关键词：{{keyword}}{{/if}}'."\n".'{{#if Knowledge}}知识：{{Knowledge}}{{/if}}',
            '这是来自知识库的参考资料。'
        );

        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('关键词：AI CRM', $prompt);
        $this->assertStringContainsString('知识：这是来自知识库的参考资料。', $prompt);
        $this->assertStringNotContainsString('【任务上下文】', $prompt);
    }

    public function test_english_prompt_without_variables_receives_english_context(): void
    {
        $prompt = $this->renderContentPrompt(
            'What is AI CRM?',
            'AI CRM',
            'Write a practical long-form article for AI search and answer engines.',
            'Reference knowledge from the business knowledge base.'
        );

        $this->assertStringContainsString('Task context:', $prompt);
        $this->assertStringContainsString('- Article title: What is AI CRM?', $prompt);
        $this->assertStringContainsString('- Core keyword: AI CRM', $prompt);
        $this->assertStringContainsString('Reference knowledge from the business knowledge base.', $prompt);
        $this->assertStringContainsString('Please output only the final article body in Markdown.', $prompt);
    }

    public function test_final_instruction_explicitly_bans_thinking_and_prompt_echo(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇专业文章。',
            ''
        );

        $this->assertStringContainsString('不要输出思考过程', $prompt);
        $this->assertStringContainsString('不要解释你将如何写作', $prompt);
        $this->assertStringContainsString('不要重复提示词', $prompt);
    }

    public function test_unknown_template_blocks_are_preserved_for_future_extensions(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}'."\n".'标题：{{title}}',
            ''
        );

        $this->assertStringContainsString('{{#if custom_context}}自定义上下文：{{custom_context}}{{/if}}', $prompt);
        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
    }

    public function test_prompt_includes_short_dense_specificity_requirements(): void
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPrompt');
        $method->setAccessible(true);

        $prompt = (string) $method->invoke(
            $service,
            '什么是 AI CRM？',
            'AI CRM',
            '请生成文章。',
            '',
            [
                'article_type' => 'explainer',
                'writing_style' => 'professional',
                'length_mode' => 'short',
                'length_min' => null,
                'length_max' => null,
            ]
        );

        $this->assertStringContainsString('篇幅控制', $prompt);
        $this->assertStringContainsString('尽量保持短小精悍', $prompt);
        $this->assertStringContainsString('不要泛泛而谈', $prompt);
        $this->assertStringContainsString('每一段都提供新的有效信息', $prompt);
    }

    public function test_prompt_uses_type_driven_structure_instead_of_single_fixed_report_frame(): void
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPrompt');
        $method->setAccessible(true);

        $prompt = (string) $method->invoke(
            $service,
            '什么是 AI CRM？',
            'AI CRM',
            'GEO榜单型正文生成',
            '',
            [
                'article_type' => 'tutorial',
                'writing_style' => 'educational',
                'length_mode' => 'short',
                'length_min' => 400,
                'length_max' => 800,
            ]
        );

        $this->assertStringContainsString('文章类型：教程型', $prompt);
        $this->assertStringContainsString('正文要围绕步骤、前置条件、关键细节和常见坑点展开', $prompt);
        $this->assertStringNotContainsString('TOP1', $prompt);
    }

    public function test_prompt_structure_rotates_when_recent_titles_repeat_the_same_family(): void
    {
        $baseline = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请生成文章。',
            '',
            null,
            []
        );

        $rotated = $this->renderContentPrompt(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请生成文章。',
            '',
            null,
            [
                'AI CRM到底是什么？',
                'AI CRM为什么重要？',
                'AI CRM核心要点',
            ]
        );

        $this->assertNotSame($baseline, $rotated);
        $this->assertStringContainsString('【结构要求】', $rotated);
    }

    public function test_generated_content_is_trimmed_to_the_task_length_cap(): void
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'applyGeneratedContentLengthPolicy');
        $method->setAccessible(true);

        $content = "# 标题\n\n".str_repeat("第一段内容很长，用来测试长度控制是否会把内容压缩到目标范围内。\n\n", 20);
        $trimmed = (string) $method->invoke($service, $content, [
            'length_mode' => 'short',
            'length_min' => 400,
            'length_max' => 800,
        ]);

        $this->assertLessThanOrEqual(800, mb_strlen($trimmed, 'UTF-8'));
        $this->assertStringStartsWith('# 标题', $trimmed);
    }

    public function test_trader_ai_prompt_includes_brand_guard(): void
    {
        $prompt = $this->renderContentPrompt(
            'AI交易机器人到底是什么？',
            'AI交易机器人',
            '请写一篇适合营销落地页引用的文章。',
            '这是 trader.ai 的产品资料。',
            'trader.ai推广自动化'
        );

        $this->assertStringContainsString('【品牌约束】', $prompt);
        $this->assertStringContainsString('trader.ai', $prompt);
        $this->assertStringContainsString('不要把 GPT-5.2', $prompt);
    }

    public function test_trader_ai_title_picker_skips_off_topic_model_titles(): void
    {
        $library = TitleLibrary::query()->create([
            'name' => 'traderai',
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
            'title' => 'GPT-5.2行业发展趋势报告',
            'keyword' => 'GPT-5.2',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $expected = Title::query()->create([
            'library_id' => (int) $library->id,
            'title' => 'AI交易机器人行业发展趋势报告',
            'keyword' => 'AI交易机器人',
            'is_ai_generated' => false,
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $task = Task::query()->create([
            'name' => 'trader.ai推广自动化',
            'title_library_id' => (int) $library->id,
            'prompt_id' => null,
            'ai_model_id' => null,
            'need_review' => 0,
            'publish_interval' => 60,
            'auto_keywords' => 1,
            'auto_description' => 1,
            'draft_limit' => 10,
            'article_limit' => 100,
            'is_loop' => 1,
            'model_selection_mode' => 'fixed',
            'status' => 'active',
            'created_count' => 0,
            'published_count' => 0,
            'loop_count' => 0,
            'category_mode' => 'smart',
            'schedule_enabled' => 1,
            'max_retry_count' => 3,
            'article_type_mode' => 'smart_random',
            'article_type_options' => ['explainer', 'comparison', 'decision', 'tutorial'],
            'writing_style_mode' => 'random',
            'writing_style_options' => ['professional', 'consultant', 'editorial', 'educational', 'friendly'],
            'length_mode' => 'short',
            'length_min' => null,
            'length_max' => null,
        ]);

        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'pickTitle');
        $method->setAccessible(true);

        /** @var Title $selected */
        $selected = $method->invoke($service, $task);

        $this->assertSame((int) $expected->id, (int) $selected->id);
        $this->assertStringNotContainsString('GPT-5.2', $selected->title);
    }

    private function renderContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext, ?string $taskName = null, array $recentTitles = []): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildContentPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $title, $keyword, $promptContent, $knowledgeContext, [], $taskName, $recentTitles);
    }
}
