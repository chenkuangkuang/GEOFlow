<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleSummaryGenerator;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 复用统一 API Key 解密组件，确保 worker 与后台配置端解密行为一致。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly TitleDiversityService $titleDiversityService
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId): array
    {
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('任务不存在');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('任务未激活');
        }

        $publishResult = $this->publishDueDraftArticle($task);
        if ($publishResult !== null) {
            return $publishResult;
        }

        $generationBlockReason = $this->getGenerationBlockReason($task);
        if ($generationBlockReason !== null) {
            return [
                'article_id' => null,
                'title' => '',
                'message' => $generationBlockReason,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'action' => 'noop',
                    'reason' => $generationBlockReason,
                ],
            ];
        }

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $generationProfile = $this->resolveGenerationProfile($task, (string) $titleRow->title, $keyword);
        $recentTitles = $this->loadRecentTitleHistory((int) $task->id);
        $effectiveTitle = $this->rewriteGeneratedTitle((string) $titleRow->title, $keyword, $generationProfile, $recentTitles);
        $knowledgeContext = $this->resolveKnowledgeContext($task, $effectiveTitle, $keyword);
        $contentPrompt = $this->buildContentPrompt($effectiveTitle, $keyword, $prompt?->content, $knowledgeContext, $generationProfile, (string) $task->name, $recentTitles);
        $generation = $this->generateContentWithModelSelection($task, $contentPrompt, $generationProfile);
        $aiModel = $generation['model'];
        $generatedContent = $generation['content'];
        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent);
        $content = $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $excerpt = $this->buildExcerpt($content, $effectiveTitle);
        $workflow = [
            'status' => 'draft',
            'review_status' => (int) ($task->need_review ?? 1) === 1 ? 'pending' : 'approved',
            'published_at' => null,
        ];

        $articleId = DB::transaction(function () use ($task, $titleRow, $effectiveTitle, $author, $category, $keyword, $content, $excerpt, $workflow, $selectedImages): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }
            $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
            if ($generationBlockReason !== null) {
                throw new RuntimeException($generationBlockReason);
            }

            $article = Article::query()->create([
                'title' => $effectiveTitle,
                'slug' => ArticleWorkflow::generateUniqueSlug($effectiveTitle),
                'excerpt' => $excerpt,
                'content' => $content,
                'category_id' => $category?->id,
                'author_id' => $author?->id,
                'task_id' => (int) $task->id,
                'original_keyword' => $keyword,
                'keywords' => $keyword,
                'meta_description' => mb_substr($excerpt, 0, 120),
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'is_ai_generated' => 1,
                'published_at' => $workflow['published_at'],
                'view_count' => 0,
            ]);
            if ($selectedImages !== []) {
                foreach ($selectedImages as $position => $image) {
                    ArticleImage::query()->create([
                        'article_id' => (int) $article->id,
                        'image_id' => (int) $image->id,
                        'position' => $position,
                    ]);
                    Image::query()->whereKey((int) $image->id)->update([
                        'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                        'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                    ]);
                }
            }

            // 保持与旧逻辑一致：每次任务执行会消耗标题并累加任务计数。
            Title::query()->whereKey($titleRow->id)->increment('used_count');
            Title::query()->whereKey($titleRow->id)->increment('usage_count');

            $taskUpdate = [
                'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                'updated_at' => now(),
            ];
            if ($freshTask->next_publish_at === null || ! $freshTask->next_publish_at->greaterThan(now())) {
                $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
            }
            Task::query()->whereKey($task->id)->update($taskUpdate);

            return (int) $article->id;
        });

        return [
            'article_id' => $articleId,
            'title' => $effectiveTitle,
            'message' => '草稿生成成功',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_draft',
                'title_id' => (int) $titleRow->id,
                'author_id' => $author?->id,
                'category_id' => $category?->id,
                'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                'image_count' => count($selectedImages),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'article_type' => $generationProfile['article_type'],
                'writing_style' => $generationProfile['writing_style'],
                'length_mode' => $generationProfile['length_mode'],
                'length_min' => $generationProfile['length_min'],
                'length_max' => $generationProfile['length_max'],
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $generation['attempts'],
            ],
        ];
    }

    /**
     * 发布一个已审核草稿。生成与发布解耦后，Worker 每次执行优先释放到期草稿。
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(Task $task): ?array
    {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        return DB::transaction(function () use ($task): ?array {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }

            if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                return null;
            }

            /** @var Article|null $article */
            $article = Article::query()
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'title', 'review_status']);
            if (! $article) {
                return null;
            }

            $workflow = ArticleWorkflow::normalizeState('published', (string) ($article->review_status ?: 'approved'));
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'published_at' => $workflow['published_at'],
                'updated_at' => now(),
            ]);

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);

            return [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '草稿发布成功',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];
        });
    }

    /**
     * 判断是否允许继续生成草稿。
     */
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '已达到文章总数上限';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftQuery = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at');
        // PostgreSQL 不允许在 count(*) 聚合查询上追加 FOR UPDATE。
        // 这里的并发保护由任务行锁和 task_runs 的单任务串行队列保证，草稿计数不需要再单独加锁。

        if ($draftQuery->count() >= $draftLimit) {
            return '草稿池已满，等待审核或按间隔发布';
        }

        return null;
    }

    private function normalizePublishInterval(Task $task): int
    {
        return max(60, (int) ($task->publish_interval ?? 3600));
    }

    /**
     * 解析并校验任务绑定的 AI 模型（必须是 active + chat）。
     */
    private function resolveAiModel(Task $task): AiModel
    {
        $aiModelId = (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 模型');
        }

        $aiModel = AiModel::query()
            ->whereKey($aiModelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * 固定模型只尝试主模型；智能切换按 failover_priority 依次尝试其它 active chat 模型。
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(Task $task, string $contentPrompt, array $generationProfile = []): array
    {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task) as $candidate) {
            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new RuntimeException($unavailableReason);
                }

                continue;
            }

            try {
                $content = $this->generateContent($candidate, $contentPrompt, $generationProfile);
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null);

                return [
                    'content' => $content,
                    'model' => $candidate,
                    'attempts' => $attempts,
                ];
            } catch (Throwable $exception) {
                $lastMessage = trim($exception->getMessage());
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($mode !== 'smart_failover') {
                    throw $exception;
                }
            }
        }

        if ($mode === 'smart_failover' && $attempts !== []) {
            throw new RuntimeException($this->buildFailoverErrorMessage($attempts, $lastMessage));
        }

        throw new RuntimeException('AI模型不可用或已达每日限制');
    }

    /**
     * @return list<AiModel>
     */
    private function resolveAiModelCandidates(Task $task): array
    {
        $primaryModel = $this->resolveAiModel($task);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$primaryModel];
        }

        $fallbackModels = AiModel::query()
            ->whereKeyNot((int) $primaryModel->id)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI模型不可用或已达每日限制';
        }

        $dailyLimit = (int) ($aiModel->daily_limit ?? 0);
        $usedToday = (int) ($aiModel->used_today ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
            return 'AI模型不可用或已达每日限制';
        }

        return null;
    }

    /**
     * @return array{model_id:int,model_name:string,status:string,reason:?string}
     */
    private function buildModelAttempt(AiModel $aiModel, string $status, ?string $reason): array
    {
        return [
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array{model_id:int,model_name:string,status:string,reason:?string}>  $attempts
     */
    private function buildFailoverErrorMessage(array $attempts, string $lastMessage): string
    {
        $summaries = [];
        foreach ($attempts as $attempt) {
            $reason = trim((string) ($attempt['reason'] ?? ''));
            $summaries[] = (string) $attempt['model_name'].($reason !== '' ? '（'.$reason.'）' : '');
        }

        return '智能模型切换已尝试：'.implode('；', $summaries).'。最终失败：'.$lastMessage;
    }

    private function pickTitle(Task $task): Title
    {
        $libraryId = (int) ($task->title_library_id ?? 0);
        if ($libraryId <= 0) {
            throw new RuntimeException('任务未配置标题库');
        }

        $query = Title::query()->where('library_id', $libraryId);
        $titleLibrary = TitleLibrary::query()->find($libraryId);
        if ($this->isTraderAiBrandContext((string) ($task->name ?? ''), (string) ($titleLibrary?->name ?? ''))) {
            $query->where(function ($builder): void {
                foreach ($this->traderAiOffTopicTerms() as $term) {
                    $like = '%'.mb_strtolower($term, 'UTF-8').'%';
                    $builder->where(function ($termQuery) use ($like): void {
                        $termQuery->whereRaw('LOWER(COALESCE(title, \'\')) NOT LIKE ?', [$like])
                            ->whereRaw('LOWER(COALESCE(keyword, \'\')) NOT LIKE ?', [$like]);
                    });
                }
            });
        }

        if ((int) ($task->is_loop ?? 0) !== 1) {
            $query->where(function ($builder): void {
                $builder->whereNull('used_count')->orWhere('used_count', '<=', 0);
            });
        }

        /** @var Title|null $title */
        $title = $query
            ->orderBy('used_count')
            ->orderBy('id')
            ->first();

        if (! $title) {
            throw new RuntimeException((int) ($task->is_loop ?? 0) === 1 ? '没有可用的标题' : '标题库已用尽');
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = Author::query()->find($authorId);
            if ($author) {
                return $author;
            }
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return $author;
        }

        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task): ?Category
    {
        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            return Category::query()->find((int) $task->fixed_category_id);
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐任务上下文。
     */
    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext, array $generationProfile = [], ?string $taskName = null, array $recentTitles = []): string
    {
        $customPrompt = trim((string) $promptContent);
        $hasExplicitContextVariables = $customPrompt !== '' && $this->promptHasKnownContextVariables($customPrompt);
        $renderedCustomPrompt = $customPrompt !== '' ? $this->renderPromptTemplate($customPrompt, [
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ]) : '';

        $renderedPrompt = $this->composeAdaptiveBasePrompt($title, $keyword, $knowledgeContext, $generationProfile, ! $hasExplicitContextVariables, $recentTitles);
        if ($renderedCustomPrompt !== '') {
            $renderedPrompt .= "\n\n".$this->appendCustomPromptReference($renderedCustomPrompt, $generationProfile);
        }

        $renderedPrompt = $this->appendWritingProfileGuidance($renderedPrompt, $generationProfile);
        $renderedPrompt = $this->appendLengthGuidance($renderedPrompt, $generationProfile);
        $renderedPrompt = $this->appendBrandGuard($renderedPrompt, $taskName, $title, $keyword, $knowledgeContext);

        return trim($renderedPrompt)."\n\n".$this->finalPromptInstruction($renderedPrompt);
    }

    /**
     * @param  array{article_type?:string,writing_style?:string,length_mode?:string,length_min?:int|null,length_max?:int|null}  $generationProfile
     */
    private function composeAdaptiveBasePrompt(string $title, string $keyword, string $knowledgeContext, array $generationProfile, bool $includeTaskContext = true, array $recentTitles = []): string
    {
        $articleType = (string) ($generationProfile['article_type'] ?? 'explainer');

        if ($this->isLikelyEnglishPrompt($keyword.' '.$title.' '.$knowledgeContext)) {
            $lines = [
                '[Role]',
                'You are a sharp GEO content editor. Write concise, information-dense, publishable Markdown that is specific, useful, and easy for AI systems to extract.',
                '',
            ];
            if ($includeTaskContext) {
                $lines[] = 'Task context:';
                $lines[] = '- Article title: '.$title;
                if (trim($keyword) !== '') {
                    $lines[] = '- Core keyword: '.$keyword;
                }
                if (trim($knowledgeContext) !== '') {
                    $lines[] = '- Reference knowledge:';
                    $lines[] = $knowledgeContext;
                }
                $lines[] = '';
            }
            $lines[] = '[Core requirements]';
            $lines[] = '- Lead with conclusions, then explain them.';
            $lines[] = '- Be concrete, specific, and scenario-aware. Avoid generic filler and abstract fluff.';
            $lines[] = '- Every section must add a clear piece of information: a criterion, example, step, trade-off, caution, or boundary.';
            $lines[] = '- Use headings, bullets, and tables only when they increase information density.';
            $lines[] = '- Do not write like a formal report unless the topic truly requires it.';
            $lines[] = '';
            $lines[] = '[Structure requirements]';
            foreach ($this->englishAdaptiveStructure($articleType, $title, $recentTitles) as $line) {
                $lines[] = $line;
            }

            return implode("\n", $lines);
        }

        $lines = [
            '【角色】',
            '你是一名擅长 GEO 内容生产的资深编辑。请输出简短、有趣、信息密度高、可直接发布的 Markdown 文章。',
            '',
        ];
        if ($includeTaskContext) {
            $lines[] = '【任务上下文】';
            $lines[] = '- 文章标题：'.$title;
            if (trim($keyword) !== '') {
                $lines[] = '- 核心关键词：'.$keyword;
            }
            if (trim($knowledgeContext) !== '') {
                $lines[] = '- 参考知识：';
                $lines[] = $knowledgeContext;
            }
            $lines[] = '';
        }
        $lines[] = '【底层要求】';
        $lines[] = '- 先给结论，再做解释。';
        $lines[] = '- 表达要深入、具体，不要泛泛而谈，不要写成空洞报告。';
        $lines[] = '- 每个小节都必须提供明确增量信息，例如判断依据、真实场景、对比维度、步骤、边界或注意事项。';
        $lines[] = '- 能用列表或表格讲清的内容，不要硬写成冗长大段。';
        $lines[] = '- 除非主题真的需要，不要写成“行业发展趋势报告”“分析与研究”那种正式报告体。';
        $lines[] = '';
        $lines[] = '【结构要求】';
        foreach ($this->chineseAdaptiveStructure($articleType, $title, $recentTitles) as $line) {
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{article_type?:string,writing_style?:string,length_mode?:string,length_min?:int|null,length_max?:int|null}  $generationProfile
     */
    private function appendCustomPromptReference(string $customPrompt, array $generationProfile): string
    {
        if ($customPrompt === '') {
            return '';
        }

        if ($this->isLikelyEnglishPrompt($customPrompt)) {
            return "[Additional prompt reference]\nUse the following as supplemental guidance only when it does not conflict with the article type, style, brevity, and specificity requirements above:\n".$customPrompt;
        }

        return "【补充提示】\n以下内容仅作为补充参考；如果与上面的文章类型、语言风格、短篇高信息密度要求冲突，以上面的要求为准：\n".$customPrompt;
    }

    /**
     * @return array{article_type:string,writing_style:string,length_mode:string,length_min:int|null,length_max:int|null}
     */
    private function resolveGenerationProfile(Task $task, string $title, string $keyword): array
    {
        $allowedTypes = $this->normalizeTaskOptions($task->article_type_options, [
            'explainer', 'comparison', 'decision', 'tutorial',
        ]);
        $allowedStyles = $this->normalizeTaskOptions($task->writing_style_options, [
            'professional', 'consultant', 'editorial', 'educational', 'friendly',
        ]);

        $articleTypeMode = trim((string) ($task->article_type_mode ?? 'smart_random'));
        $writingStyleMode = trim((string) ($task->writing_style_mode ?? 'random'));
        $lengthMode = trim((string) ($task->length_mode ?? 'short'));
        [$lengthMin, $lengthMax] = $this->resolveLengthRange($lengthMode, $task);

        return [
            'article_type' => $this->pickArticleType($articleTypeMode, $allowedTypes, $title, $keyword),
            'writing_style' => $this->pickWritingStyle($writingStyleMode, $allowedStyles),
            'length_mode' => in_array($lengthMode, ['short', 'medium', 'long', 'custom'], true) ? $lengthMode : 'short',
            'length_min' => $lengthMin,
            'length_max' => $lengthMax,
        ];
    }

    private function promptHasKnownContextVariables(string $prompt): bool
    {
        return preg_match('/\{\{\s*(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1;
    }

    /**
     * 渲染任务上下文变量，兼容 {{Knowledge}} 与 {{knowledge}} 等大小写写法。
     *
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            'knowledge' => $context['knowledge'],
            default => '',
        };
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['title', 'keyword', 'knowledge'], true);
    }

    private function appendSmartPromptContext(string $prompt, string $title, string $keyword, string $knowledgeContext): string
    {
        if ($this->isLikelyEnglishPrompt($prompt)) {
            $lines = [
                'Task context:',
                '- Article title: '.$title,
            ];
            if (trim($keyword) !== '') {
                $lines[] = '- Core keyword: '.$keyword;
            }
            if (trim($knowledgeContext) !== '') {
                $lines[] = '- Reference knowledge:';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【任务上下文】',
            '- 文章标题：'.$title,
        ];
        if (trim($keyword) !== '') {
            $lines[] = '- 核心关键词：'.$keyword;
        }
        if (trim($knowledgeContext) !== '') {
            $lines[] = '- 参考知识：';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    /**
     * @param  array{article_type?:string,writing_style?:string,length_mode?:string,length_min?:int|null,length_max?:int|null}  $writingProfile
     */
    private function appendWritingProfileGuidance(string $prompt, array $writingProfile): string
    {
        $articleType = trim((string) ($writingProfile['article_type'] ?? ''));
        $writingStyle = trim((string) ($writingProfile['writing_style'] ?? ''));
        if ($articleType === '' && $writingStyle === '') {
            return $prompt;
        }

        if ($this->isLikelyEnglishPrompt($prompt)) {
            $lines = ['[Writing Profile]'];
            if ($articleType !== '') {
                $lines[] = '- Article type: '.$this->englishArticleTypeLabel($articleType);
                foreach ($this->englishArticleTypeRules($articleType) as $rule) {
                    $lines[] = '  - '.$rule;
                }
            }
            if ($writingStyle !== '') {
                $lines[] = '- Writing style: '.$this->englishWritingStyleLabel($writingStyle);
                foreach ($this->englishWritingStyleRules($writingStyle) as $rule) {
                    $lines[] = '  - '.$rule;
                }
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = ['【写作画像】'];
        if ($articleType !== '') {
            $lines[] = '- 文章类型：'.$this->chineseArticleTypeLabel($articleType);
            foreach ($this->chineseArticleTypeRules($articleType) as $rule) {
                $lines[] = '  - '.$rule;
            }
        }
        if ($writingStyle !== '') {
            $lines[] = '- 语言风格：'.$this->chineseWritingStyleLabel($writingStyle);
            foreach ($this->chineseWritingStyleRules($writingStyle) as $rule) {
                $lines[] = '  - '.$rule;
            }
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    /**
     * @param  array{length_mode?:string,length_min?:int|null,length_max?:int|null}  $generationProfile
     */
    private function appendLengthGuidance(string $prompt, array $generationProfile): string
    {
        $lengthMode = trim((string) ($generationProfile['length_mode'] ?? 'short'));
        $lengthMin = isset($generationProfile['length_min']) ? (int) $generationProfile['length_min'] : null;
        $lengthMax = isset($generationProfile['length_max']) ? (int) $generationProfile['length_max'] : null;
        if ($lengthMin === null || $lengthMax === null) {
            [$defaultMin, $defaultMax] = $this->defaultLengthRange($lengthMode);
            $lengthMin ??= $defaultMin;
            $lengthMax ??= $defaultMax;
        }
        if ($lengthMin === null || $lengthMax === null) {
            return $prompt;
        }

        if ($this->isLikelyEnglishPrompt($prompt)) {
            $lines = [
                '[Length and Density]',
                '- Target length: about '.$lengthMin.'-'.$lengthMax.' words.',
                '- The article must be complete within that target length. If space is tight, reduce scope instead of ending mid-article.',
                '- It is better to write a slightly shorter complete piece than a longer unfinished draft.',
                '- Keep the article concise, information-dense, and specific.',
                '- Do not pad with generic background, repetitive transitions, or obvious filler.',
                '- Every paragraph should add a concrete insight, example, criterion, step, or caution.',
                '- Be detailed in substance, not verbose in wording. Avoid vague high-level statements.',
            ];

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【篇幅控制】',
            '- 目标篇幅：约 '.$lengthMin.'-'.$lengthMax.' 字。',
            '- 必须在目标篇幅内完成整篇文章；如果篇幅紧张，就缩小范围，不要中途停住。',
            '- 宁可少写一点，也不要写到一半戛然而止，结尾必须完整收束。',
            '- 尽量保持短小精悍、信息密度高、表达具体。',
            '- 不要为了凑字数补背景、补空话、补重复过渡句。',
            '- 每一段都提供新的有效信息，例如判断依据、场景、步骤、边界或注意事项。',
            '- 要深入具体，不要泛泛而谈；宁可写短，也不要写成啰嗦的 AI 套话长文。',
        ];

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function appendBrandGuard(string $prompt, ?string $taskName, string $title, string $keyword, string $knowledgeContext): string
    {
        if (! $this->isTraderAiBrandContext((string) $taskName, $title, $keyword, $knowledgeContext)) {
            return $prompt;
        }

        if ($this->isLikelyEnglishPrompt($prompt.' '.$title.' '.$keyword.' '.$knowledgeContext)) {
            $lines = [
                '[Brand guard]',
                '- This task must stay focused on trader.ai product marketing, use cases, and conversion intent.',
                '- Do not use GPT-5.2, MiniMax, or other generic model names as the main topic unless the article is explicitly a trader.ai comparison.',
                '- Prefer trader.ai, AI trading platform, AI trading bot, trading agent, live trading, signal subscription, and related product vocabulary.',
            ];

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【品牌约束】',
            '- 本任务必须围绕 trader.ai 的产品、功能、使用场景和转化诉求写作。',
            '- 不要把 GPT-5.2、MiniMax 这类泛模型名当作主标题，除非正文明确是在对比 trader.ai 相关能力。',
            '- 优先使用 trader.ai、AI交易平台、AI交易机器人、交易代理、实盘AI交易、信号订阅网络等语义。',
        ];

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function finalPromptInstruction(string $prompt): string
    {
        if ($this->isLikelyEnglishPrompt($prompt)) {
            return 'Please output only the final article body in Markdown. Do not include thinking, chain-of-thought, explanations about how you will write, prompt echoes, or placeholders.';
        }

        return '请直接输出最终文章正文（Markdown），不要输出思考过程，不要解释你将如何写作，不要重复提示词、不要输出占位符。';
    }

    /**
     * @return list<string>
     */
    private function traderAiOffTopicTerms(): array
    {
        return [
            'GPT-5.2',
            'MiniMax-M2.1',
            'OpenClaw',
        ];
    }

    private function isTraderAiBrandContext(string ...$parts): bool
    {
        $haystack = mb_strtolower(trim(implode(' ', $parts)), 'UTF-8');
        if ($haystack === '') {
            return false;
        }

        return preg_match('/trader\s*\.?\s*ai/iu', $haystack) === 1
            || str_contains($haystack, 'traderai');
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId <= 0) {
            return '';
        }

        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->first(['id', 'content']);
        if (! $knowledgeBase) {
            return '';
        }

        $content = trim((string) ($knowledgeBase->content ?? ''));
        if ($content === '') {
            return '';
        }

        $chunkCount = KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
        if ($chunkCount <= 0) {
            $this->knowledgeChunkSyncService->sync($knowledgeBaseId, $content);
        }

        $query = trim($title."\n".$keyword);
        $context = $this->fetchKnowledgeContextFromChunks($knowledgeBaseId, $query, 4, 2400);
        if ($context !== '') {
            return $context;
        }

        return mb_strlen($content, 'UTF-8') > 2400 ? mb_substr($content, 0, 2400, 'UTF-8') : $content;
    }

    /**
     * 从 knowledge_chunks 中检索相关片段。
     */
    private function fetchKnowledgeContextFromChunks(int $knowledgeBaseId, string $query, int $limit, int $maxChars): string
    {
        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseId, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content', 'embedding_json', 'embedding_model_id', 'embedding_dimensions'])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : ($a['chunk_index'] <=> $b['chunk_index']);
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
    }

    /**
     * 判断 chunk 是否保存了真实 embedding，而不是 fallback hash 向量。
     */
    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * 按任务图片配置插入 Markdown 配图并返回被选中的图片列表。
     *
     * @return array{content:string,images:list<Image>}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content): array
    {
        $libraryId = (int) ($task->image_library_id ?? 0);
        $imageCount = max(0, (int) ($task->image_count ?? 0));
        if ($libraryId <= 0 || $imageCount <= 0) {
            return ['content' => $content, 'images' => []];
        }

        /** @var list<Image> $images */
        $images = Image::query()
            ->where('library_id', $libraryId)
            ->inRandomOrder()
            ->limit($imageCount)
            ->get(['id', 'file_path', 'original_name'])
            ->all();
        if ($images === []) {
            return ['content' => $content, 'images' => []];
        }

        $markdownBlocks = [];
        foreach ($images as $image) {
            $path = trim((string) ($image->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            $path = ImageUrlNormalizer::toPublicUrl($path);
            $alt = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            $markdownBlocks[] = '!['.($alt !== '' ? $alt : 'image').']('.$path.')';
        }

        if ($markdownBlocks !== []) {
            $content = $this->insertImagesByParagraphInterval($content, $markdownBlocks);
        }

        return ['content' => $content, 'images' => $images];
    }

    /**
     * 按段落间隔插入图片，避免全部堆在文末。
     *
     * @param  list<string>  $markdownBlocks
     */
    private function insertImagesByParagraphInterval(string $content, array $markdownBlocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $markdownBlocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($paragraphs === []) {
            return $trimmed."\n\n".implode("\n\n", $markdownBlocks);
        }

        $paragraphCount = count($paragraphs);
        $imageCount = count($markdownBlocks);
        $interval = max(1, (int) floor($paragraphCount / ($imageCount + 1)));

        $parts = [];
        $imageIndex = 0;
        foreach ($paragraphs as $index => $paragraph) {
            $parts[] = trim((string) $paragraph);
            $nextParagraphPosition = $index + 1;

            if (
                $imageIndex < $imageCount
                && $nextParagraphPosition % $interval === 0
                && $nextParagraphPosition < $paragraphCount
            ) {
                $parts[] = $markdownBlocks[$imageIndex];
                $imageIndex++;
            }
        }

        while ($imageIndex < $imageCount) {
            $parts[] = $markdownBlocks[$imageIndex];
            $imageIndex++;
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * 调用任务配置模型生成正文。
     */
    private function generateContent(AiModel $aiModel, string $contentPrompt, array $generationProfile = []): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API 地址为空');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('worker', $driver, $providerUrl, $apiKey);
        $agent = new MarkdownContentWriterAgent(
            instructions: $this->isLikelyEnglishPrompt($contentPrompt)
                ? 'You are a professional English content writer. Output a polished, publishable Markdown article only.'
                : '你是专业中文写作助手，请输出高质量、可发布的 Markdown 文章。'
        );

        try {
            $response = $agent->prompt($contentPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);
        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new RuntimeException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性');
            }

            throw new RuntimeException('AI返回空正文');
        }

        $content = $this->sanitizeGeneratedContent($content);
        $content = $this->applyGeneratedContentLengthPolicy($content, $generationProfile);

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
    }

    /**
     * 从正文提取摘要，避免把完整提示词原文当摘要。
     */
    private function buildExcerpt(string $content, string $title = ''): string
    {
        $excerpt = ArticleSummaryGenerator::fromMarkdown($content, $title, 180);
        if ($excerpt === '') {
            return 'AI 生成内容摘要';
        }

        return $excerpt;
    }

    /**
     * @param  array<int, string>|mixed  $rawOptions
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function normalizeTaskOptions(mixed $rawOptions, array $defaults): array
    {
        $options = is_array($rawOptions) ? $rawOptions : [];
        if (! is_array($rawOptions) && is_string($rawOptions) && trim($rawOptions) !== '') {
            $decoded = json_decode($rawOptions, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }
        $filtered = [];
        foreach ($options as $option) {
            $value = trim((string) $option);
            if ($value !== '' && in_array($value, $defaults, true) && ! in_array($value, $filtered, true)) {
                $filtered[] = $value;
            }
        }

        return $filtered !== [] ? $filtered : $defaults;
    }

    /**
     * @param  list<string>  $allowedTypes
     */
    private function pickArticleType(string $mode, array $allowedTypes, string $title, string $keyword): string
    {
        if ($mode === 'fixed') {
            return $allowedTypes[0] ?? 'explainer';
        }

        if ($mode === 'smart_random') {
            $routed = array_values(array_intersect($this->routeArticleTypes($title, $keyword), $allowedTypes));
            if ($routed !== []) {
                return $routed[0];
            }
        }

        return $allowedTypes[random_int(0, count($allowedTypes) - 1)] ?? 'explainer';
    }

    /**
     * @param  list<string>  $allowedStyles
     */
    private function pickWritingStyle(string $mode, array $allowedStyles): string
    {
        if ($mode === 'fixed') {
            return $allowedStyles[0] ?? 'professional';
        }

        return $allowedStyles[random_int(0, count($allowedStyles) - 1)] ?? 'professional';
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private function resolveLengthRange(string $lengthMode, Task $task): array
    {
        if ($lengthMode === 'custom') {
            $min = max(120, (int) ($task->length_min ?? 0));
            $max = max($min, (int) ($task->length_max ?? 0));

            return [$min > 0 ? $min : null, $max > 0 ? $max : null];
        }

        return $this->defaultLengthRange($lengthMode);
    }

    /**
     * @return list<string>
     */
    private function chineseAdaptiveStructure(string $articleType, string $title, array $recentTitles = []): array
    {
        $variants = match ($articleType) {
            'comparison' => [
                [
                    '# '.$title,
                    '## 先看结论',
                    '- 用 3-5 条要点直接说明差异、取舍和推荐判断。',
                    '## 关键差异',
                    '## 适合谁 / 不适合谁',
                    '## 快速对比表',
                    '## 最终建议',
                ],
                [
                    '# '.$title,
                    '## 一句话判断',
                    '- 先把结论说清，再展开比较依据。',
                    '## 场景差异',
                    '## 选择建议',
                    '## 对比表',
                    '## 结尾建议',
                ],
                [
                    '# '.$title,
                    '## 先做选择',
                    '- 先讲适用场景，再讲差异与取舍。',
                    '## 核心区别',
                    '## 各自适合谁',
                    '## 选型清单',
                    '## 最后建议',
                ],
            ],
            'decision' => [
                [
                    '# '.$title,
                    '## 先说结论',
                    '- 直接告诉读者什么人适合、什么人不适合、优先怎么选。',
                    '## 决策标准',
                    '## 不同场景怎么选',
                    '## 容易踩坑的点',
                    '## 最终建议',
                ],
                [
                    '# '.$title,
                    '## 直接结论',
                    '- 用最短路径说明推荐方向。',
                    '## 判断依据',
                    '## 场景分流',
                    '## 常见误区',
                    '## 选购建议',
                ],
                [
                    '# '.$title,
                    '## 一眼判断',
                    '- 先明确适合谁，再解释为什么。',
                    '## 取舍维度',
                    '## 适用场景',
                    '## 不适合谁',
                    '## 最后建议',
                ],
            ],
            'tutorial' => [
                [
                    '# '.$title,
                    '## 先看结果',
                    '- 先告诉读者做完能得到什么，以及适合谁照着做。',
                    '## 开始前要准备什么',
                    '## 具体步骤',
                    '## 常见错误与避坑',
                    '## 最后总结',
                ],
                [
                    '# '.$title,
                    '## 先定目标',
                    '- 先明确你会完成什么，再进入步骤。',
                    '## 准备清单',
                    '## 操作步骤',
                    '## 验证方法',
                    '## 收尾建议',
                ],
                [
                    '# '.$title,
                    '## 先上手',
                    '- 先给最小可行路径，再展开细节。',
                    '## 前置条件',
                    '## 步骤拆解',
                    '## 容易出错的地方',
                    '## 复盘建议',
                ],
            ],
            default => [
                [
                    '# '.$title,
                    '## 核心结论',
                    '- 用 3-5 条短要点先讲明白这个主题最重要的判断。',
                    '## 它到底是什么',
                    '## 为什么这件事重要',
                    '## 常见误区 / 关键边界',
                    '## 最后总结',
                ],
                [
                    '# '.$title,
                    '## 一句话先说清',
                    '- 用简短判断开场，再慢慢展开。',
                    '## 基本定义',
                    '## 实际场景',
                    '## 常见误解',
                    '## 结语',
                ],
                [
                    '# '.$title,
                    '## 先给答案',
                    '- 先讲这个东西最关键的作用，再解释边界。',
                    '## 怎么理解它',
                    '## 什么时候有用',
                    '## 什么时候要谨慎',
                    '## 总结',
                ],
            ],
        };

        return $this->selectStructureVariant($variants, $articleType, $title, $recentTitles);
    }

    /**
     * @return list<string>
     */
    private function englishAdaptiveStructure(string $articleType, string $title, array $recentTitles = []): array
    {
        $variants = match ($articleType) {
            'comparison' => [
                [
                    '# '.$title,
                    '## Quick Answer',
                    '- Use 3-5 bullets to summarize the key differences, trade-offs, and recommendation.',
                    '## Key Differences',
                    '## Who Each Option Fits',
                    '## Comparison Table',
                    '## Final Recommendation',
                ],
                [
                    '# '.$title,
                    '## Bottom Line',
                    '- Start with the recommendation, then explain the trade-off.',
                    '## Scenario Differences',
                    '## Best Fit by Use Case',
                    '## Comparison Matrix',
                    '## Closing Advice',
                ],
                [
                    '# '.$title,
                    '## Short Answer',
                    '- State the decision path first.',
                    '## Where They Differ',
                    '## Who Should Choose What',
                    '## Decision Table',
                    '## Final Pick',
                ],
            ],
            'decision' => [
                [
                    '# '.$title,
                    '## Quick Answer',
                    '- Tell the reader who should choose what, and why.',
                    '## Decision Criteria',
                    '## Which Option Fits Which Scenario',
                    '## Common Mistakes',
                    '## Final Recommendation',
                ],
                [
                    '# '.$title,
                    '## Bottom Line',
                    '- Give the recommendation first.',
                    '## Evaluation Criteria',
                    '## Best Fit Scenarios',
                    '## Pitfalls to Avoid',
                    '## Final Advice',
                ],
                [
                    '# '.$title,
                    '## Direct Answer',
                    '- Explain the selection rule in one paragraph.',
                    '## What Matters Most',
                    '## Fit by Scenario',
                    '## Common Traps',
                    '## Takeaway',
                ],
            ],
            'tutorial' => [
                [
                    '# '.$title,
                    '## What You Will Achieve',
                    '- Clarify the outcome and who this guide is for.',
                    '## What To Prepare First',
                    '## Step-by-Step Guide',
                    '## Common Mistakes and Fixes',
                    '## Final Notes',
                ],
                [
                    '# '.$title,
                    '## What You Need',
                    '- Start with prerequisites and expected results.',
                    '## Setup',
                    '## Step-by-Step Walkthrough',
                    '## Troubleshooting',
                    '## Wrap-Up',
                ],
                [
                    '# '.$title,
                    '## Start Here',
                    '- Show the quickest path to success.',
                    '## Preparation Checklist',
                    '## Execution Steps',
                    '## Common Errors',
                    '## Summary',
                ],
            ],
            default => [
                [
                    '# '.$title,
                    '## Key Takeaways',
                    '- Summarize the most important judgments in 3-5 bullets.',
                    '## What It Actually Is',
                    '## Why It Matters',
                    '## Misconceptions and Boundaries',
                    '## Final Notes',
                ],
                [
                    '# '.$title,
                    '## The Short Version',
                    '- Start with the answer, then expand.',
                    '## Core Definition',
                    '## Real-World Use',
                    '## Common Misunderstandings',
                    '## Closing Thoughts',
                ],
                [
                    '# '.$title,
                    '## Quick Summary',
                    '- Explain the key point up front.',
                    '## What It Means',
                    '## When It Helps',
                    '## When To Be Careful',
                    '## Bottom Line',
                ],
            ],
        };

        return $this->selectStructureVariant($variants, $articleType, $title, $recentTitles);
    }

    /**
     * @param  list<array{0:string,1:string,2?:string,3?:string,4?:string,5?:string,6?:string}>  $variants
     * @return list<string>
     */
    private function selectStructureVariant(array $variants, string $articleType, string $title, array $recentTitles = []): array
    {
        if ($variants === []) {
            return [];
        }

        $recentSignature = json_encode($this->titleDiversityService->countRecentTitleFamilies($recentTitles), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $seed = crc32($articleType.'|'.$title.'|'.$recentSignature);
        $index = (int) ($seed % count($variants));

        return $variants[$index];
    }

    /**
     * @param  array{article_type?:string,writing_style?:string}  $generationProfile
     */
    private function rewriteGeneratedTitle(string $title, string $keyword, array $generationProfile, array $recentTitles = []): string
    {
        return $this->titleDiversityService->rewriteTitle(
            $title,
            $keyword,
            (string) ($generationProfile['article_type'] ?? 'explainer'),
            $recentTitles
        );
    }

    /**
     * @return list<string>
     */
    private function loadRecentTitleHistory(int $taskId, int $taskLimit = 6, int $globalLimit = 12): array
    {
        $taskTitles = Article::query()
            ->where('task_id', $taskId)
            ->orderByDesc('id')
            ->limit($taskLimit)
            ->pluck('title')
            ->map(static fn ($title) => trim((string) $title))
            ->filter(static fn (string $title) => $title !== '')
            ->values()
            ->all();

        $globalTitles = Article::query()
            ->orderByDesc('id')
            ->limit($globalLimit)
            ->pluck('title')
            ->map(static fn ($title) => trim((string) $title))
            ->filter(static fn (string $title) => $title !== '')
            ->values()
            ->all();

        return array_values(array_unique(array_merge($taskTitles, $globalTitles)));
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private function defaultLengthRange(string $lengthMode): array
    {
        return match ($lengthMode) {
            'medium' => [600, 1000],
            'long' => [1000, 1600],
            'short' => [400, 800],
            default => [null, null],
        };
    }

    /**
     * @return list<string>
     */
    private function routeArticleTypes(string $title, string $keyword): array
    {
        $haystack = mb_strtolower(trim($title.' '.$keyword), 'UTF-8');
        $types = [];

        if (
            str_contains($haystack, ' versus ')
            || str_contains($haystack, ' vs ')
            || str_starts_with($haystack, 'vs ')
            || str_ends_with($haystack, ' vs')
            || str_contains($haystack, ' compare ')
            || str_contains($haystack, 'comparison')
            || str_contains($haystack, '对比')
            || str_contains($haystack, '区别')
            || str_contains($haystack, '哪个好')
        ) {
            $types[] = 'comparison';
        }

        if (
            str_contains($haystack, ' best ')
            || str_starts_with($haystack, 'best ')
            || str_contains($haystack, ' top ')
            || str_starts_with($haystack, 'top ')
            || str_contains($haystack, ' worth ')
            || str_contains($haystack, ' choose ')
            || str_contains($haystack, ' selection ')
            || str_contains($haystack, ' buy ')
            || str_contains($haystack, '推荐')
            || str_contains($haystack, '排行')
            || str_contains($haystack, '排名')
            || str_contains($haystack, '怎么选')
            || str_contains($haystack, '值不值得')
        ) {
            $types[] = 'decision';
        }

        if (
            str_contains($haystack, 'how to')
            || str_contains($haystack, ' tutorial ')
            || str_starts_with($haystack, 'tutorial ')
            || str_contains($haystack, ' guide ')
            || str_contains($haystack, ' setup ')
            || str_contains($haystack, ' install ')
            || str_contains($haystack, ' configure ')
            || str_contains($haystack, '教程')
            || str_contains($haystack, '步骤')
            || str_contains($haystack, '指南')
            || str_contains($haystack, '如何')
            || str_contains($haystack, '怎么')
            || str_contains($haystack, '配置')
            || str_contains($haystack, '搭建')
            || str_contains($haystack, '安装')
        ) {
            $types[] = 'tutorial';
        }

        if ($types === []) {
            $types[] = 'explainer';
        }

        if (! in_array('explainer', $types, true)) {
            $types[] = 'explainer';
        }

        return array_values(array_unique($types));
    }

    private function englishArticleTypeLabel(string $articleType): string
    {
        return match ($articleType) {
            'comparison' => 'Comparison article',
            'decision' => 'Decision-making article',
            'tutorial' => 'Tutorial article',
            default => 'Explainer article',
        };
    }

    /**
     * @return list<string>
     */
    private function englishArticleTypeRules(string $articleType): array
    {
        return match ($articleType) {
            'comparison' => [
                'Highlight meaningful differences, trade-offs, and decision criteria.',
                'Use tables or side-by-side bullets when they improve clarity.',
            ],
            'decision' => [
                'Guide the reader toward a practical choice based on scenarios and constraints.',
                'State who should choose which option and under what conditions.',
            ],
            'tutorial' => [
                'Organize the article around actionable steps, prerequisites, and pitfalls.',
                'Explain why each step matters instead of listing commands mechanically.',
            ],
            default => [
                'Clarify what the topic is, why it matters, and how readers should understand it.',
                'Prioritize explanation, definitions, context, and common misconceptions.',
            ],
        };
    }

    private function englishWritingStyleLabel(string $writingStyle): string
    {
        return match ($writingStyle) {
            'consultant' => 'Consultative',
            'editorial' => 'Editorial analysis',
            'educational' => 'Teaching-focused',
            'friendly' => 'Friendly and approachable',
            default => 'Professional and trustworthy',
        };
    }

    /**
     * @return list<string>
     */
    private function englishWritingStyleRules(string $writingStyle): array
    {
        return match ($writingStyle) {
            'consultant' => [
                'Write like an advisor helping a business team evaluate options.',
                'Favor practical judgment, trade-offs, and operational implications.',
            ],
            'editorial' => [
                'Write like a sharp industry analyst with clear framing and synthesis.',
                'Keep the tone restrained and evidence-aware, not sensational.',
            ],
            'educational' => [
                'Break down ideas patiently so a motivated reader can learn step by step.',
                'Use definitions, examples, and transitions that reduce cognitive load.',
            ],
            'friendly' => [
                'Keep the tone warm, clear, and easy to follow without becoming casual fluff.',
                'Prefer concrete sentences over jargon-heavy phrasing.',
            ],
            default => [
                'Keep the tone precise, credible, and business-ready.',
                'Use clean, direct sentences instead of decorative language.',
            ],
        };
    }

    private function chineseArticleTypeLabel(string $articleType): string
    {
        return match ($articleType) {
            'comparison' => '比较型',
            'decision' => '决策型',
            'tutorial' => '教程型',
            default => '解释型',
        };
    }

    /**
     * @return list<string>
     */
    private function chineseArticleTypeRules(string $articleType): array
    {
        return match ($articleType) {
            'comparison' => [
                '重点写清差异点、取舍条件和比较维度，不要只做平铺罗列。',
                '适合用表格或并列清单帮助读者快速抓住区别。',
            ],
            'decision' => [
                '重点帮助读者做选择，要说明什么场景适合什么方案。',
                '结论要面向行动，明确推荐逻辑和适用边界。',
            ],
            'tutorial' => [
                '正文要围绕步骤、前置条件、关键细节和常见坑点展开。',
                '不仅写怎么做，还要解释每一步为什么重要。',
            ],
            default => [
                '重点解释概念、背景、原理和常见误区，帮助读者真正理解主题。',
                '优先做清晰拆解，而不是急着下购买或排行结论。',
            ],
        };
    }

    private function chineseWritingStyleLabel(string $writingStyle): string
    {
        return match ($writingStyle) {
            'consultant' => '咨询顾问型',
            'editorial' => '媒体解读型',
            'educational' => '教学拆解型',
            'friendly' => '口语亲和型',
            default => '专业可信型',
        };
    }

    /**
     * @return list<string>
     */
    private function chineseWritingStyleRules(string $writingStyle): array
    {
        return match ($writingStyle) {
            'consultant' => [
                '语气像在给团队做方案建议，强调判断、取舍和业务影响。',
                '多写场景建议和决策依据，少写空泛修辞。',
            ],
            'editorial' => [
                '语气像行业编辑或分析师，擅长归纳趋势和提炼重点。',
                '保持克制，不要写成夸张营销稿。',
            ],
            'educational' => [
                '语气像耐心讲解的老师，拆步骤、讲例子、降低理解门槛。',
                '段落过渡要顺，让读者能顺着逻辑一路看懂。',
            ],
            'friendly' => [
                '语气亲和、好懂，但不要口水化，也不要牺牲专业度。',
                '多用直白句式，减少过度抽象的表达。',
            ],
            default => [
                '语气专业、稳重、可信，适合商业和行业内容场景。',
                '优先用清晰结论和事实支撑，而不是花哨表达。',
            ],
        };
    }

    private function sanitizeGeneratedContent(string $content): string
    {
        $content = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $content));
        $content = trim((string) preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $content));
        $content = trim((string) preg_replace('/```(?:markdown|md)?\s*(.*?)\s*```/is', '$1', $content));
        $content = $this->stripCommonAiPreamble($content);

        if (! $this->looksLikePublishableArticleBody($content)) {
            throw new RuntimeException('AI 生成结果不包含可发布的文章正文');
        }

        return $content;
    }

    /**
     * @param  array{length_mode?:string,length_min?:int|null,length_max?:int|null}  $generationProfile
     */
    private function applyGeneratedContentLengthPolicy(string $content, array $generationProfile): string
    {
        $lengthMode = trim((string) ($generationProfile['length_mode'] ?? 'short'));
        $lengthMin = isset($generationProfile['length_min']) ? (int) $generationProfile['length_min'] : null;
        $lengthMax = isset($generationProfile['length_max']) ? (int) $generationProfile['length_max'] : null;
        if ($lengthMin === null || $lengthMax === null) {
            [$defaultMin, $defaultMax] = $this->defaultLengthRange($lengthMode);
            $lengthMin ??= $defaultMin;
            $lengthMax ??= $defaultMax;
        }

        if ($lengthMax === null || $lengthMax <= 0) {
            return $content;
        }

        return $content;
    }

    private function trimMarkdownContentToLength(string $content, int $maxChars): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $maxChars <= 0) {
            return '';
        }

        $blocks = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($blocks === []) {
            return mb_substr($trimmed, 0, $maxChars, 'UTF-8');
        }

        $result = [];
        $used = 0;
        foreach ($blocks as $block) {
            $block = trim((string) $block);
            if ($block === '') {
                continue;
            }

            $blockLength = mb_strlen($block, 'UTF-8');
            $separatorLength = $result === [] ? 0 : 2;
            if ($used + $separatorLength + $blockLength > $maxChars) {
                break;
            }

            $result[] = $block;
            $used += $separatorLength + $blockLength;
        }

        if ($result === []) {
            return mb_substr($trimmed, 0, $maxChars, 'UTF-8');
        }

        $output = trim(implode("\n\n", $result));
        if (mb_strlen($output, 'UTF-8') > $maxChars) {
            $output = mb_substr($output, 0, $maxChars, 'UTF-8');
        }

        return $output;
    }

    private function stripCommonAiPreamble(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        $patterns = [
            '/\A(?:好的[，,！!\s]*|当然[，,\s]*|下面[是为您]*[：:\s]*|以下[是为您]*[：:\s]*)+/u',
            '/\A用户需要我生成一篇关于.*?(?:文章内容[：:]?|正文[：:]?|如下[：:]?)(?:\R+|\s+)/us',
            '/\A(?:这是一篇关于.*?的文章[：:]?|以下是文章(?:正文|内容)?[：:]?)(?:\R+|\s+)/us',
        ];

        foreach ($patterns as $pattern) {
            $trimmed = trim((string) preg_replace($pattern, '', $trimmed));
        }

        $articleStart = $this->extractArticleStart($trimmed);

        return $articleStart !== '' ? $articleStart : $trimmed;
    }

    private function extractArticleStart(string $content): string
    {
        $patterns = [
            '/(?=^#\s+.+$)/um',
            '/(?=^##\s+.+$)/um',
            '/(?=^\S.{0,120}\R\R)/um',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === 1) {
                $offset = (int) ($matches[0][1] ?? 0);

                return trim(substr($content, $offset));
            }
        }

        return '';
    }

    private function looksLikePublishableArticleBody(string $content): bool
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return false;
        }

        $plain = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $trimmed) ?: $trimmed;
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '' || mb_strlen($plain, 'UTF-8') < 80) {
            return false;
        }

        $blockedPhrases = [
            '用户需要我生成一篇关于',
            '我会从定义',
            '我将从定义',
            '下面是文章内容',
            '以下是文章内容',
        ];

        foreach ($blockedPhrases as $phrase) {
            if (str_contains($plain, $phrase)) {
                return false;
            }
        }

        return preg_match('/^#{1,3}\s+\S+/um', $trimmed) === 1
            || preg_match('/\R\R/u', $trimmed) === 1;
    }

    /**
     * 兼容 enc:v1 历史格式解密 API Key。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * 优先使用 pgvector 执行数据库向量检索，命中则返回候选块。
     *
     * @return list<array{chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        $rows = DB::select(
            '
                SELECT chunk_index, content,
                       (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks
                WHERE knowledge_base_id = ?
                  AND embedding_vector IS NOT NULL
                ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                LIMIT ?
            ',
            [$vectorLiteral, $knowledgeBaseId, $vectorLiteral, max(1, $candidateLimit)]
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
            ];
        }

        return $results;
    }

    /**
     * 仅在 PostgreSQL 且 pgvector 可用时启用向量检索。
     */
    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 从候选块拼装知识上下文，按片段顺序输出。
     *
     * @param  list<array{chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => $a['chunk_index'] <=> $b['chunk_index']);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $parts[] = '【知识片段'.($index + 1)."】\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }
}
