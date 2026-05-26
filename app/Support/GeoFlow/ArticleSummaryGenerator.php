<?php

namespace App\Support\GeoFlow;

final class ArticleSummaryGenerator
{
    public static function bestExcerpt(string $title, string $excerpt, string $content, int $limit = 180): string
    {
        $preferred = self::normalizeExcerptCandidate($excerpt, $title, $limit);
        if ($preferred !== '') {
            return $preferred;
        }

        return self::fromMarkdown($content, $title, $limit);
    }

    public static function fromMarkdown(string $content, string $title = '', int $limit = 180): string
    {
        $body = self::stripLeadingTitleHeading($content, $title);
        $body = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $body) ?? $body;

        $takeaway = self::extractTakeawayBlock($body);
        if ($takeaway !== '') {
            return self::limit(self::toPlainLine($takeaway), $limit);
        }

        foreach (preg_split('/(?:\r?\n){2,}/u', $body) ?: [] as $block) {
            $plain = self::normalizeContentBlock($block, $title);
            if ($plain === '') {
                continue;
            }

            return self::limit($plain, $limit);
        }

        return self::limit(self::toPlainLine($body), $limit);
    }

    public static function normalizeExcerptCandidate(string $excerpt, string $title, int $limit = 180): string
    {
        $excerpt = self::stripLeadingTitleHeading($excerpt, $title);
        $plain = self::toPlainLine($excerpt);
        if (self::looksLikeLowQualitySummary($plain, $title)) {
            return '';
        }

        return self::limit($plain, $limit);
    }

    public static function stripLeadingTitleHeading(string $content, string $title): string
    {
        $content = (string) $content;
        $title = trim($title);
        if ($title === '') {
            return trim($content);
        }

        $pattern = '/^\s*#\s*'.preg_quote($title, '/').'\s*(?:\r?\n)+/u';

        return trim((string) preg_replace($pattern, '', $content, 1));
    }

    private static function extractTakeawayBlock(string $body): string
    {
        $lines = preg_split('/\r?\n/u', $body) ?: [];
        $collecting = false;
        $bullets = [];

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                if ($collecting && $bullets !== []) {
                    break;
                }

                continue;
            }

            if (preg_match('/^#{2,6}\s*(核心摘要|摘要|Key Takeaways|Summary)\s*$/iu', $trimmed) === 1) {
                $collecting = true;
                continue;
            }

            if ($collecting && preg_match('/^#{1,6}\s+/u', $trimmed) === 1) {
                break;
            }

            if ($collecting && preg_match('/^(?:[-*+]\s+|\d+\.\s+)(.+)$/u', $trimmed, $matches) === 1) {
                $candidate = self::toPlainLine((string) ($matches[1] ?? ''));
                if ($candidate !== '') {
                    $bullets[] = $candidate;
                }
            }

            if (count($bullets) >= 2) {
                break;
            }
        }

        if ($bullets === []) {
            return '';
        }

        $separator = self::containsCjk(implode('', $bullets)) ? '；' : '; ';

        return implode($separator, $bullets);
    }

    private static function normalizeContentBlock(string $block, string $title): string
    {
        $plain = self::toPlainLine($block);
        if ($plain === '') {
            return '';
        }

        if (preg_match('/^(核心摘要|摘要|Key Takeaways|Summary|引言|Introduction)\b/iu', $plain) === 1) {
            return '';
        }

        if ($title !== '' && str_starts_with(mb_strtolower($plain, 'UTF-8'), mb_strtolower(trim($title), 'UTF-8'))) {
            return '';
        }

        return self::looksLikeLowQualitySummary($plain, $title) ? '' : $plain;
    }

    private static function looksLikeLowQualitySummary(string $plain, string $title): bool
    {
        $plain = trim($plain);
        if ($plain === '' || mb_strlen($plain, 'UTF-8') < 18) {
            return true;
        }

        if (preg_match('/^(核心摘要|摘要|Key Takeaways|Summary)$/iu', $plain) === 1) {
            return true;
        }

        $normalizedTitle = trim($title);
        if ($normalizedTitle !== '') {
            $lowerPlain = mb_strtolower($plain, 'UTF-8');
            $lowerTitle = mb_strtolower($normalizedTitle, 'UTF-8');

            if ($lowerPlain === $lowerTitle) {
                return true;
            }

            if (str_starts_with($lowerPlain, $lowerTitle) && mb_strlen($plain, 'UTF-8') <= mb_strlen($normalizedTitle, 'UTF-8') + 40) {
                return true;
            }
        }

        return false;
    }

    private static function toPlainLine(string $text): string
    {
        $text = preg_replace('/^\s*#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/^\s*(?:[-*+]\s+|\d+\.\s+)/mu', '', $text) ?? $text;
        $text = preg_replace('/[`#>*_\-\[\]\(\)\|]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private static function limit(string $text, int $limit): string
    {
        $limit = max(1, $limit);

        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    private static function containsCjk(string $text): bool
    {
        return preg_match('/\p{Han}/u', $text) === 1;
    }
}
