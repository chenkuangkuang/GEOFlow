<?php

namespace Tests\Unit;

use App\Services\GeoFlow\WorkerExecutionService;
use ReflectionMethod;
use Tests\TestCase;

class WorkerExecutionServiceExcerptGenerationTest extends TestCase
{
    public function test_excerpt_prefers_key_takeaways_instead_of_title_and_intro_echo(): void
    {
        $content = <<<'MD'
# AI CRM 到底是什么？

## 核心摘要
- AI CRM 可以帮助销售团队更快完成线索分层和跟进优先级判断。
- 它最适合已经有客户数据沉淀、但人工跟进效率不足的团队。

## 一、引言
AI CRM 到底是什么？很多团队第一次接触这个概念时，往往会先看到一堆泛泛而谈的定义。
MD;

        $excerpt = $this->buildExcerpt($content);

        $this->assertStringContainsString('AI CRM 可以帮助销售团队更快完成线索分层和跟进优先级判断', $excerpt);
        $this->assertStringNotContainsString('AI CRM 到底是什么？很多团队第一次接触这个概念时', $excerpt);
        $this->assertStringNotContainsString('核心摘要', $excerpt);
    }

    private function buildExcerpt(string $content): string
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'buildExcerpt');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $content);
    }
}
