<?php

namespace App\Support\GeoFlow;

use App\Models\Article;
use Illuminate\Support\Carbon;
use ZipArchive;

final class ArticleMarkdownExporter
{
    /**
     * @return array{path:string, file_name:string}
     */
    public function buildZipArchive(): array
    {
        $exportedAt = now();
        $articles = Article::query()
            ->with([
                'author:id,name',
                'task:id,name',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'title',
                'slug',
                'excerpt',
                'content',
                'keywords',
                'status',
                'review_status',
                'author_id',
                'task_id',
                'meta_description',
                'is_ai_generated',
                'is_hot',
                'is_featured',
                'created_at',
                'updated_at',
                'published_at',
            ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'geoflow-articles-');
        $zipPath = $tempPath.'.zip';
        @unlink($tempPath);

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException('Failed to create export archive.');
        }

        $zip->addFromString('manifest.md', $this->buildManifest($articles->count(), $exportedAt));

        $usedNames = [];
        foreach ($articles as $article) {
            $zip->addFromString(
                'articles/'.$this->uniqueArticleFileName((string) $article->title, (string) $article->slug, (int) $article->id, $usedNames),
                $this->buildArticleMarkdown($article)
            );
        }

        $zip->close();

        return [
            'path' => $zipPath,
            'file_name' => 'geoflow-articles-'.now()->format('Ymd-His').'.zip',
        ];
    }

    private function buildManifest(int $count, Carbon $exportedAt): string
    {
        return implode("\n", [
            '# GEOFlow Article Export',
            '',
            'exported_at: '.$this->formatDateTime($exportedAt),
            'article_count: '.$count,
            'format: zip-with-one-markdown-per-article',
            'directory: articles/',
            '',
        ]);
    }

    private function buildArticleMarkdown(Article $article): string
    {
        return implode("\n", [
            '# '.$article->title,
            '',
            '```yaml',
            'id: '.(int) $article->id,
            'title: '.$this->sanitizeScalar((string) $article->title),
            'slug: '.$this->sanitizeScalar((string) $article->slug),
            'status: '.$this->sanitizeScalar((string) $article->status),
            'review_status: '.$this->sanitizeScalar((string) $article->review_status),
            'author: '.$this->sanitizeScalar((string) ($article->author->name ?? '')),
            'task: '.$this->sanitizeScalar((string) ($article->task->name ?? '')),
            'keywords: '.$this->sanitizeScalar((string) ($article->keywords ?? '')),
            'excerpt: '.$this->sanitizeScalar((string) ($article->excerpt ?? '')),
            'meta_description: '.$this->sanitizeScalar((string) ($article->meta_description ?? '')),
            'is_ai_generated: '.((int) ($article->is_ai_generated ?? 0)),
            'is_hot: '.($article->is_hot ? '1' : '0'),
            'is_featured: '.($article->is_featured ? '1' : '0'),
            'created_at: '.$this->formatDateTime($article->created_at),
            'updated_at: '.$this->formatDateTime($article->updated_at),
            'published_at: '.$this->formatDateTime($article->published_at),
            '```',
            '',
            '### CONTENT',
            '',
            rtrim((string) $article->content),
            '',
        ]);
    }

    /**
     * @param  array<string, int>  $usedNames
     */
    private function uniqueArticleFileName(string $title, string $slug, int $articleId, array &$usedNames): string
    {
        $baseName = $this->normalizeFileStem($title);
        if ($baseName === '') {
            $baseName = $this->normalizeFileStem($slug);
        }

        if ($baseName === '') {
            $baseName = 'article-'.$articleId;
        }

        if (! array_key_exists($baseName, $usedNames)) {
            $usedNames[$baseName] = 1;

            return $baseName.'.md';
        }

        $usedNames[$baseName]++;

        return $baseName.'-'.$usedNames[$baseName].'.md';
    }

    private function normalizeFileStem(string $value): string
    {
        $normalized = trim($value);
        $normalized = preg_replace('/[\\\\\\/:*?"<>|]+/u', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/[\x00-\x1F\x7F]+/u', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " .-\t\n\r\0\x0B");

        return $normalized;
    }

    private function sanitizeScalar(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return str_replace(['"', '\\'], ['\"', '\\\\'], $normalized);
    }

    private function formatDateTime(null|Carbon|string $value): string
    {
        if ($value instanceof Carbon) {
            return $value->copy()->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP');
        }

        if (is_string($value) && $value !== '') {
            return Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d\TH:i:sP');
        }

        return '';
    }
}
