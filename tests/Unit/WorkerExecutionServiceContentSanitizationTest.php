<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceContentSanitizationTest extends TestCase
{
    public function test_it_removes_think_blocks_and_leading_meta_preamble(): void
    {
        $raw = <<<'MD'
<think>
用户需要我生成一篇关于 AI CRM 的文章，我应该先解释任务，再给出正文。
</think>
用户需要我生成一篇关于 AI CRM 的文章，以下是文章内容：

# AI CRM 到底是什么？

AI CRM 是将人工智能能力引入客户关系管理流程的一类系统。

## 为什么企业开始关注 AI CRM

它可以帮助企业提高线索分配、客户分层和销售跟进效率。
MD;

        $cleaned = $this->sanitizeGeneratedContent($raw);

        $this->assertStringNotContainsString('<think>', $cleaned);
        $this->assertStringNotContainsString('用户需要我生成一篇关于', $cleaned);
        $this->assertStringStartsWith('# AI CRM 到底是什么？', $cleaned);
        $this->assertStringContainsString('## 为什么企业开始关注 AI CRM', $cleaned);
    }

    public function test_it_rejects_meta_response_without_article_body(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI 生成结果不包含可发布的文章正文');

        $raw = <<<'TEXT'
<think>先分析用户意图</think>
用户需要我生成一篇关于 AI CRM 的文章，我会从定义、价值和场景三个角度来写。
TEXT;

        $this->sanitizeGeneratedContent($raw);
    }

    private function sanitizeGeneratedContent(string $content): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'sanitizeGeneratedContent');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $content);
    }
}
