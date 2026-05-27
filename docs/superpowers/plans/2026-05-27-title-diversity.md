# Title Diversity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make article titles vary across consecutive generations by rotating title families within a task and across recent global output, instead of repeatedly collapsing into one question-like pattern.

**Architecture:** Keep the existing task/article pipeline, but add a lightweight title-family selector in `WorkerExecutionService` that looks at recent titles from the same task and the global article feed before choosing a rewritten title variant. Broaden the title generation prompts and fallback templates so the title library itself also contains more than one structural family.

**Tech Stack:** PHP 8.2+, Laravel, existing `Article`, `Task`, `Title`, `TitleAiGenerationService`, and `UrlImportProcessingService` services.

---

### Task 1: Add title-family rotation to article generation

**Files:**
- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Test: `tests/Unit/WorkerExecutionServiceWritingProfileTest.php`

- [ ] **Step 1: Write the failing test**

Add a unit test that calls the title rewrite helper with recent titles and verifies that the service avoids repeating the same explainer family when a question-style title has already been used recently.

```php
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
        ['AI CRM到底是什么？', 'AI CRM到底是什么？', 'AI CRM为什么重要？']
    );

    $this->assertNotSame('AI CRM到底是什么？', $rewritten);
    $this->assertMatchesRegularExpression('/AI CRM(全解析|入门指南|适合谁？|为什么重要？|核心要点)/u', $rewritten);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=WorkerExecutionServiceWritingProfileTest`

Expected: the new test fails because `rewriteGeneratedTitle()` still always prefers the same explainer question form.

- [ ] **Step 3: Implement the rotation logic**

Update `rewriteGeneratedTitle()` so it:
- builds a family-specific candidate pool for `explainer`, `comparison`, `decision`, and `tutorial`
- inspects recent titles from the same task plus recent global articles
- scores candidate families by recent usage
- prefers a less-used family instead of the same question-like family every time

Add the helper methods needed for family detection and recent-title loading, and wire them into `executeTask()` so the generator passes recent history into the title rewrite step.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=WorkerExecutionServiceWritingProfileTest`

Expected: the new rotation test passes, and the existing rewrite tests still pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/WorkerExecutionService.php tests/Unit/WorkerExecutionServiceWritingProfileTest.php
git commit -m "feat: diversify generated article titles"
```

### Task 2: Make title-library AI output less repetitive

**Files:**
- Modify: `app/Services/GeoFlow/TitleAiGenerationService.php`
- Modify: `app/Services/GeoFlow/UrlImportProcessingService.php`
- Test: `tests/Unit/TitleAiGenerationServiceTest.php` or extend the existing unit coverage if a dedicated file is not present

- [ ] **Step 1: Write the failing test**

Add a unit test that confirms the AI title-generation prompt explicitly asks for mixed title families instead of a single question-only family, and that the fallback/mock title generator also returns a mixed set when several titles are requested.

```php
public function test_mock_title_generation_mixes_title_families(): void
{
    $service = app(TitleAiGenerationService::class);
    $method = new ReflectionMethod($service, 'generateMockTitles');
    $method->setAccessible(true);

    $titles = $method->invoke($service, ['AI CRM'], 6, 'professional');

    $this->assertCount(6, $titles);
    $this->assertTrue(
        collect($titles)->contains(fn (string $title) => str_contains($title, '指南'))
        || collect($titles)->contains(fn (string $title) => str_contains($title, '解析'))
        || collect($titles)->contains(fn (string $title) => str_contains($title, '怎么选'))
    );
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TitleAiGenerationServiceTest`

Expected: the fallback output is still too narrow and the prompt does not explicitly require mixed title families.

- [ ] **Step 3: Implement prompt and fallback changes**

Update `TitleAiGenerationService::requestTitlesFromModel()` so the system/user prompts ask for a mix of question, guide, analysis, comparison, and decision titles, with an explicit anti-repetition requirement. Update `generateMockTitles()` to emit a diverse pool instead of only one style family per mode.

Optionally align `UrlImportProcessingService::generateTitles()` so imported title libraries also produce a broader mix of title families.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TitleAiGenerationServiceTest`

Expected: the fallback test passes and the prompt contains the anti-repetition requirement.

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeoFlow/TitleAiGenerationService.php app/Services/GeoFlow/UrlImportProcessingService.php tests/Unit/TitleAiGenerationServiceTest.php
git commit -m "feat: diversify title generation prompts"
```

### Task 3: Run focused regression tests

**Files:**
- No code changes; verification only

- [ ] **Step 1: Run the relevant unit suite**

Run: `php artisan test --filter=WorkerExecutionServiceWritingProfileTest --filter=TitleAiGenerationServiceTest`

Expected: all title-related tests pass, including the existing prompt tests.

- [ ] **Step 2: Sanity-check the app response path**

Run: `docker compose ps`
Run: `curl.exe -I http://127.0.0.1:18182`

Expected: the application containers are still healthy and the front page returns `200 OK`.

