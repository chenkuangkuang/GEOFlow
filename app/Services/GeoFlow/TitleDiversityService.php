<?php

namespace App\Services\GeoFlow;

class TitleDiversityService
{
    /**
     * @param  list<string>  $recentTitles
     */
    public function rewriteTitle(string $title, string $keyword, string $articleType, array $recentTitles = [], bool $forceDiversify = false): string
    {
        $original = trim($title);
        if ($original === '') {
            return trim($keyword) !== '' ? trim($keyword) : '未命名文章';
        }

        $subject = trim($keyword) !== '' ? trim($keyword) : $this->extractTitleSubject($original);
        if ($subject === '') {
            $subject = $original;
        }

        $articleType = trim($articleType) !== '' ? trim($articleType) : 'explainer';
        $isReportLike = $this->looksLikeReportTitle($original);
        $originalFamily = $this->identifyTitleFamily($original);

        if (! $forceDiversify && ! $isReportLike && $originalFamily === null) {
            return $original;
        }

        $variants = $this->buildTitleVariants($subject, $articleType);
        if ($variants === []) {
            return $original;
        }

        $recentFamilyCounts = $this->countRecentTitleFamilies($recentTitles);
        $blockedFamily = $this->recentFamilyStreak($recentTitles, 3);

        if ($blockedFamily !== null) {
            $variants = array_values(array_filter(
                $variants,
                static fn (array $variant): bool => ($variant['family'] ?? null) !== $blockedFamily
            ));
        }

        $recentTitleSet = [];
        foreach ($recentTitles as $recentTitle) {
            $normalizedRecentTitle = trim((string) $recentTitle);
            if ($normalizedRecentTitle !== '') {
                $recentTitleSet[$normalizedRecentTitle] = true;
            }
        }

        if ($recentTitleSet !== []) {
            $deduplicatedVariants = array_values(array_filter(
                $variants,
                static fn (array $variant): bool => ! isset($recentTitleSet[trim((string) ($variant['title'] ?? ''))])
            ));

            if ($deduplicatedVariants !== []) {
                $variants = $deduplicatedVariants;
            }
        }

        if ($variants === []) {
            $variants = $this->buildTitleVariants($subject, $articleType);
        }

        if (! $forceDiversify && ! $isReportLike && $originalFamily !== null) {
            $bestVariantCount = $this->lowestRecentFamilyCount($variants, $recentFamilyCounts);
            if (($recentFamilyCounts[$originalFamily] ?? 0) <= $bestVariantCount) {
                return $original;
            }
        }

        $selectedVariant = $this->selectTitleVariant($variants, $recentFamilyCounts, $subject.'|'.$articleType.'|'.$original);

        return (string) ($selectedVariant['title'] ?? $original);
    }

    public function inferArticleType(string $title, string $keyword = ''): string
    {
        $haystack = mb_strtolower(trim($title.' '.$keyword), 'UTF-8');

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
            return 'comparison';
        }

        if (
            str_contains($haystack, '怎么做')
            || str_contains($haystack, '如何')
            || str_contains($haystack, '教程')
            || str_contains($haystack, '指南')
            || str_contains($haystack, '上手')
            || str_contains($haystack, '步骤')
        ) {
            return 'tutorial';
        }

        if (
            str_contains($haystack, '怎么选')
            || str_contains($haystack, '适合谁')
            || str_contains($haystack, '值得')
            || str_contains($haystack, '选型')
            || str_contains($haystack, '决策')
            || str_contains($haystack, '买不买')
        ) {
            return 'decision';
        }

        return 'explainer';
    }

    /**
     * @param  list<string>  $recentTitles
     * @return array<string,int>
     */
    public function countRecentTitleFamilies(array $recentTitles): array
    {
        $counts = [];
        foreach ($recentTitles as $recentTitle) {
            $family = $this->identifyTitleFamily((string) $recentTitle);
            if ($family === null) {
                continue;
            }

            $counts[$family] = ($counts[$family] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  list<string>  $recentTitles
     */
    public function recentFamilyStreak(array $recentTitles, int $threshold = 3): ?string
    {
        $streakFamily = null;
        $streak = 0;
        foreach ($recentTitles as $recentTitle) {
            $family = $this->identifyTitleFamily((string) $recentTitle);
            if ($family === null) {
                break;
            }

            if ($streakFamily === null) {
                $streakFamily = $family;
                $streak = 1;
                continue;
            }

            if ($family !== $streakFamily) {
                break;
            }

            $streak++;
            if ($streak >= $threshold) {
                return $streakFamily;
            }
        }

        return null;
    }

    private function extractTitleSubject(string $title): string
    {
        $subject = trim((string) preg_replace('/(行业发展趋势报告|深度分析与研究|深度分析|分析与研究|专业见解|完整指南|入门指南|全解析|深度解析|对比选型指南|决策指南|完整教程|上手指南|实操步骤|核心要点|为什么重要？|适合谁？|到底是什么？|该怎么选？|怎么选？|怎么做？|值不值得选？|决策前要看什么？|对比分析|差异在哪？|关键区别|报告|研究)$/u', '', $title));
        $subject = trim((string) preg_replace('/^关于/u', '', $subject));

        return trim($subject, " \t\n\r\0\x0B：:-");
    }

    private function looksLikeReportTitle(string $title): bool
    {
        return preg_match('/(报告|分析与研究|深度分析|专业见解|行业发展趋势)/u', $title) === 1;
    }

    /**
     * @return list<array{family:string,title:string}>
     */
    private function buildTitleVariants(string $subject, string $articleType): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            return [];
        }

        return match ($articleType) {
            'comparison' => [
                ['family' => 'comparison_select', 'title' => $subject.'怎么选？'],
                ['family' => 'comparison_analysis', 'title' => $subject.'对比分析'],
                ['family' => 'comparison_difference', 'title' => $subject.'差异在哪？'],
                ['family' => 'comparison_guide', 'title' => $subject.'对比选型指南'],
                ['family' => 'comparison_summary', 'title' => $subject.'关键区别'],
            ],
            'decision' => [
                ['family' => 'decision_select', 'title' => $subject.'该怎么选？'],
                ['family' => 'decision_guide', 'title' => $subject.'决策指南'],
                ['family' => 'decision_audience', 'title' => $subject.'适合谁？'],
                ['family' => 'decision_value', 'title' => $subject.'值不值得选？'],
                ['family' => 'decision_checklist', 'title' => $subject.'决策前要看什么？'],
            ],
            'tutorial' => [
                ['family' => 'tutorial_howto', 'title' => $subject.'怎么做？'],
                ['family' => 'tutorial_guide', 'title' => $subject.'上手指南'],
                ['family' => 'tutorial_steps', 'title' => $subject.'实操步骤'],
                ['family' => 'tutorial_complete', 'title' => $subject.'完整教程'],
                ['family' => 'tutorial_start', 'title' => $subject.'入门教程'],
            ],
            default => [
                ['family' => 'explainer_question', 'title' => $subject.'到底是什么？'],
                ['family' => 'explainer_guide', 'title' => $subject.'入门指南'],
                ['family' => 'explainer_analysis', 'title' => $subject.'全解析'],
                ['family' => 'explainer_audience', 'title' => $subject.'适合谁？'],
                ['family' => 'explainer_importance', 'title' => $subject.'为什么重要？'],
                ['family' => 'explainer_core', 'title' => $subject.'核心要点'],
            ],
        };
    }

    /**
     * @param  list<array{family:string,title:string}>  $variants
     * @param  array<string,int>  $recentFamilyCounts
     * @return array{family:string,title:string}
     */
    private function selectTitleVariant(array $variants, array $recentFamilyCounts, string $seedContext): array
    {
        if ($variants === []) {
            return [];
        }

        $bestScore = null;
        $bestVariants = [];
        foreach ($variants as $variant) {
            $score = (int) ($recentFamilyCounts[$variant['family']] ?? 0);
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $bestVariants = [$variant];
                continue;
            }

            if ($score === $bestScore) {
                $bestVariants[] = $variant;
            }
        }

        if ($bestVariants === []) {
            $bestVariants = $variants;
        }

        $index = (int) (crc32($seedContext) % max(1, count($bestVariants)));

        return $bestVariants[$index] ?? $bestVariants[0];
    }

    /**
     * @param  list<array{family:string,title:string}>  $variants
     * @param  array<string,int>  $recentFamilyCounts
     */
    private function lowestRecentFamilyCount(array $variants, array $recentFamilyCounts): int
    {
        $min = PHP_INT_MAX;
        foreach ($variants as $variant) {
            $count = (int) ($recentFamilyCounts[$variant['family']] ?? 0);
            if ($count < $min) {
                $min = $count;
            }
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    private function identifyTitleFamily(string $title): ?string
    {
        $normalized = trim($title);
        if ($normalized === '') {
            return null;
        }

        $patterns = [
            'explainer_question' => '/(到底是什么？|是什么？)$/u',
            'explainer_guide' => '/(入门指南|完整指南)$/u',
            'explainer_analysis' => '/(全解析|深度解析)$/u',
            'explainer_audience' => '/适合谁？$/u',
            'explainer_importance' => '/为什么重要？$/u',
            'explainer_core' => '/核心要点$/u',
            'comparison_select' => '/怎么选？$/u',
            'comparison_analysis' => '/对比分析$/u',
            'comparison_difference' => '/(差异在哪？|有什么区别？)$/u',
            'comparison_guide' => '/对比选型指南$/u',
            'comparison_summary' => '/关键区别$/u',
            'decision_select' => '/该怎么选？$/u',
            'decision_guide' => '/决策指南$/u',
            'decision_audience' => '/适合谁？$/u',
            'decision_value' => '/值不值得选？$/u',
            'decision_checklist' => '/决策前要看什么？$/u',
            'tutorial_howto' => '/怎么做？$/u',
            'tutorial_guide' => '/上手指南$/u',
            'tutorial_steps' => '/实操步骤$/u',
            'tutorial_complete' => '/完整教程$/u',
            'tutorial_start' => '/入门教程$/u',
        ];

        foreach ($patterns as $family => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $family;
            }
        }

        if ($this->looksLikeReportTitle($normalized)) {
            return 'report';
        }

        return null;
    }
}
