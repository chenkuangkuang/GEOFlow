# Trader.ai Topic Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the trader.ai content pipeline on-brand by removing off-topic title pool entries and adding a brand-specific prompt guard for future generations.

**Architecture:** The fix is split between data hygiene and generation rules. First, clean the existing title library so the task stops sampling generic model names like GPT-5.2; then strengthen the task prompt so future title/body generations stay anchored to trader.ai marketing and product vocabulary.

**Tech Stack:** Laravel 12, PostgreSQL, Eloquent, Blade-admin UI, Artisan tinker/migrations if needed

---

### Task 1: Clean the trader.ai title pool

**Files:**
- Modify: `database` records for `title_libraries`, `titles`, and `keywords` via a repeatable Artisan command or seed adjustment if needed
- Test: `docker compose exec -T app php artisan tinker`

- [ ] **Step 1: Inspect and identify off-topic entries**

```php
$library = DB::table('title_libraries')->where('name', 'traderai')->first();
$titles = DB::table('titles')->where('library_id', 1)->pluck('title');
$keywords = DB::table('keywords')->where('library_id', 1)->pluck('keyword');
```

- [ ] **Step 2: Remove generic model/news keywords and titles that cause unrelated topics**

```php
DB::table('keywords')
    ->where('library_id', 1)
    ->whereIn('keyword', ['GPT-5.2', 'Leaderboard', 'MiniMax-M2.1', 'OpenClaw'])
    ->delete();

DB::table('titles')
    ->where('library_id', 1)
    ->whereIn('title', ['GPT-5.2行业发展趋势报告', 'Leaderboard行业发展趋势报告'])
    ->delete();
```

- [ ] **Step 3: Add trader.ai marketing terms that match the intended domain**

```php
$keywords = ['Trader.ai', 'trader.ai', 'AI交易平台', 'AI交易机器人', '交易代理', '实盘AI交易', '信号订阅网络', 'AI策略浏览器'];
foreach ($keywords as $keyword) {
    DB::table('keywords')->updateOrInsert(
        ['library_id' => 1, 'keyword' => $keyword],
        ['created_at' => now(), 'updated_at' => now()]
    );
}
```

- [ ] **Step 4: Verify the pool no longer contains off-topic terms**

```php
DB::table('keywords')->where('library_id', 1)->where('keyword', 'GPT-5.2')->exists();
DB::table('titles')->where('library_id', 1)->where('title', 'like', '%GPT-5.2%')->exists();
```

### Task 2: Add a trader.ai brand guard to the generation prompt

**Files:**
- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Test: `tests/Feature/...` or a focused tinker/runtime check if no existing unit coverage fits cleanly

- [ ] **Step 1: Add a small brand-guard helper that appends trader.ai-specific requirements when the task name or prompt indicates trader.ai**

```php
private function appendBrandGuard(string $prompt, Task $task, string $title, string $keyword, string $knowledgeContext): string
{
    $brandHint = mb_strtolower($task->name.' '.$title.' '.$keyword.' '.$knowledgeContext, 'UTF-8');

    if (! str_contains($brandHint, 'trader.ai') && ! str_contains($brandHint, 'traderai')) {
        return $prompt;
    }

    $guard = "\n\n【品牌约束】\n- 本任务必须围绕 trader.ai 的产品、功能、使用场景和转化诉求写作。\n- 不要把 GPT-5.2、MiniMax、Leaderboard 这类泛模型或泛技术名词作为主标题，除非正文明确是在比较 trader.ai 的相关能力。\n- 优先使用 trader.ai、AI交易平台、AI交易机器人、交易代理、实盘AI交易、信号订阅网络等语义。\n";

    return trim($prompt).$guard;
}
```

- [ ] **Step 2: Wire the guard into `buildContentPrompt()` after the adaptive base prompt is built**

```php
$renderedPrompt = $this->composeAdaptiveBasePrompt(...);
$renderedPrompt = $this->appendBrandGuard($renderedPrompt, $task, $title, $keyword, $knowledgeContext);
```

- [ ] **Step 3: Verify a trader.ai task now emits a brand-anchored prompt**

```php
// Use tinker or a focused test to assert the prompt contains trader.ai constraints
```

### Task 3: Verify the result end to end

**Files:**
- Test: existing app logs / generated articles

- [ ] **Step 1: Regenerate one article for the trader.ai task**

```bash
docker compose exec -T app php artisan tinker --execute='$task = DB::table("tasks")->where("id", 1)->first(); dump($task->name);'
```

- [ ] **Step 2: Confirm the new article title stays in the trader.ai domain**

```php
DB::table('articles')->orderByDesc('id')->limit(5)->get(['title','original_keyword']);
```

- [ ] **Step 3: Commit the fix**

```bash
git add app/Services/GeoFlow/WorkerExecutionService.php
 git commit -m "fix: keep trader.ai generation on brand"
```
