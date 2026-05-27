<?php

namespace Tests\Unit;

use App\Services\GeoFlow\TitleAiGenerationService;
use ReflectionMethod;
use Tests\TestCase;

class TitleAiGenerationServiceTest extends TestCase
{
    public function test_title_generation_prompts_request_mixed_title_structures(): void
    {
        $service = app(TitleAiGenerationService::class);
        $method = new ReflectionMethod($service, 'buildTitleGenerationPrompts');
        $method->setAccessible(true);

        [$systemPrompt, $userPrompt] = $method->invoke($service, ['AI CRM'], 6, 'question', '请强调决策场景。');

        $this->assertStringContainsString('混合多种结构', $systemPrompt);
        $this->assertStringContainsString('标题要混合问句、指南、解析、对比、决策等不同结构', $userPrompt);
        $this->assertStringContainsString('同一种标题结构不要连续重复', $userPrompt);
        $this->assertStringContainsString('请强调决策场景。', $userPrompt);
    }

    public function test_mock_title_generation_cycles_through_multiple_families(): void
    {
        $service = app(TitleAiGenerationService::class);
        $method = new ReflectionMethod($service, 'generateMockTitles');
        $method->setAccessible(true);

        $titles = $method->invoke($service, ['AI CRM'], 6, 'professional');

        $this->assertCount(6, $titles);
        $this->assertSame('AI CRM的深度分析', $titles[0]);
        $this->assertSame('关于AI CRM的专业见解', $titles[1]);
        $this->assertSame('AI CRM入门指南', $titles[2]);
        $this->assertSame('AI CRM到底是什么？', $titles[3]);
        $this->assertSame('AI CRM为什么重要？', $titles[4]);
        $this->assertSame('AI CRM的核心要点', $titles[5]);
    }
}
