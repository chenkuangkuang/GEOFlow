<?php

namespace Tests\Unit;

use App\Services\GeoFlow\TitleDiversityService;
use Tests\TestCase;

class TitleDiversityServiceTest extends TestCase
{
    public function test_recent_three_same_family_force_a_different_title_family(): void
    {
        $service = app(TitleDiversityService::class);

        $rewritten = $service->rewriteTitle(
            'AI CRM 行业发展趋势报告',
            'AI CRM',
            'explainer',
            [
                'AI CRM到底是什么？',
                'AI CRM到底是什么？',
                'AI CRM到底是什么？',
            ]
        );

        $this->assertNotSame('AI CRM到底是什么？', $rewritten);
        $this->assertMatchesRegularExpression(
            '/^AI CRM(入门指南|全解析|适合谁？|为什么重要？|核心要点)$/u',
            $rewritten
        );
    }

    public function test_tutorial_titles_can_be_rewritten_into_mixed_families(): void
    {
        $service = app(TitleDiversityService::class);

        $rewritten = $service->rewriteTitle(
            'AI 工具上手指南',
            'AI 工具',
            'tutorial',
            [
                'AI 工具怎么做？',
                'AI 工具怎么做？',
                'AI 工具怎么做？',
            ]
        );

        $this->assertMatchesRegularExpression(
            '/^AI 工具(怎么做？|上手指南|实操步骤|完整教程|入门教程)$/u',
            $rewritten
        );
    }
}
