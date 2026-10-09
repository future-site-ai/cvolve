<?php

declare(strict_types=1);

namespace App\Service\Cv;

use Cvolve\TemplateKit\SectionTypes;
use App\Entity\CvVariant;

/**
 * Rule-based review of a CV: missing fields, template limits, length, date
 * consistency, weak bullets and (with a job description) keyword coverage.
 */
final class CvChecker
{
    /** Rough characters of body text that fit on one A4 page in a typical template. */
    private const CHARS_PER_PAGE = 3600;

    private const STOPWORDS = [
        'the', 'and', 'for', 'with', 'you', 'your', 'our', 'are', 'will', 'from', 'that', 'this', 'have', 'has', 'who', 'all',
        'can', 'not', 'but', 'any', 'their', 'they', 'into', 'about', 'more', 'other', 'such', 'also', 'able', 'work', 'working',
        'team', 'teams', 'role', 'job', 'year', 'years', 'experience', 'strong', 'good', 'great', 'new', 'plus', 'well', 'including',
        'what', 'how', 'why', 'when', 'where', 'which', 'using', 'use', 'used', 'within', 'across', 'per', 'via', 'etc', 'must',
        'should', 'would', 'could', 'like', 'skills', 'knowledge', 'ability', 'looking', 'join', 'part', 'help', 'make', 'based',
        'offer', 'company', 'candidate', 'requirements', 'responsibilities', 'nice', 'one', 'two', 'three', 'least', 'than',
        'need', 'needs', 'want', 'matter', 'matters', 'seeking', 'ideal', 'preferred', 'required', 'bonus', 'environment', 'opportunity',
    ];

    /**
     * @return array{findings: list<array{level: string, message: string}>, stats: array<string, mixed>}
     */
    public function check(CvVariant $variant, ?string $jobDescription = null, ?int $renderedPages = null): array
    {
        $template = $variant->getCv()->getTemplate();
        $header = $variant->getHeader();
        $findings = [];
        $add = static function (string $level, string $message) use (&$findings): void {
            $findings[] = ['level' => $level, 'message' => $message];
        };

        foreach (['name' => 'full name', 'email' => 'email address', 'phone' => 'phone number'] as $field => $label) {
            if (trim((string) ($header[$field] ?? '')) === '') {
                $add('error', sprintf('The header has no %s.', $label));
            }
        }
        if (trim((string) ($header['headline'] ?? '')) === '') {
            $add('warning', 'Add a headline (target job title) under your name.');
        }

        $sections = array_filter($variant->getOrderedSections(), static fn ($s) => $s->isVisible());
        $types = array_map(static fn ($s) => $s->getType(), $sections);
        if (!\in_array('summary', $types, true) && $template->supportsSection('summary')) {
            $add('warning', 'There is no professional summary.');
        }
        if (!\in_array('experience', $types, true)) {
            $add('warning', 'There is no work experience section.');
        }

        $chars = 0;
        $bulletCount = 0;
        $unquantified = 0;
        $dateFormats = [];
        $allText = [];
        foreach ($sections as $section) {
            $type = $section->getType();
            if (!$template->supportsSection($type)) {
                $add('warning', sprintf('"%s" is a %s section, which the template "%s" does not display.', $section->getTitle(), $type, $template->getName()));
                continue;
            }
            $content = SectionTypes::normalize($type, $section->getContent());
            foreach (SectionTypes::validate($type, $content, $template->getSectionRules()[$type] ?? []) as $error) {
                $add('error', $error);
            }
            $text = implode("\n", SectionTypes::toLines($type, $this->visibleOnly($type, $content)));
            if (trim($text) === '') {
                $add('info', sprintf('The section "%s" is empty and will not be printed.', $section->getTitle()));
            }
            $chars += mb_strlen($text);
            $allText[] = $section->getTitle()."\n".$text;

            foreach ($content['items'] ?? [] as $item) {
                if (!$item['visible']) {
                    continue;
                }
                foreach (['start', 'end', 'date'] as $field) {
                    if (($item[$field] ?? '') !== '') {
                        $dateFormats[$this->dateFormat($item[$field])] = $item[$field];
                    }
                }
                foreach ($item['bullets'] ?? [] as $bullet) {
                    if (!$bullet['visible']) {
                        continue;
                    }
                    ++$bulletCount;
                    if (!preg_match('/\d/', $bullet['text'])) {
                        ++$unquantified;
                    }
                    if (mb_strlen($bullet['text']) > 320) {
                        $add('warning', sprintf('A bullet is very long (%d characters): "%s…"', mb_strlen($bullet['text']), mb_substr($bullet['text'], 0, 60)));
                    }
                }
            }
        }

        unset($dateFormats['other']);
        if (\count($dateFormats) > 1) {
            $add('warning', 'Dates use mixed formats ('.implode(', ', array_map(static fn ($v) => '"'.$v.'"', $dateFormats)).'). Pick one style.');
        }
        if ($bulletCount > 0 && $unquantified / $bulletCount > 0.6) {
            $add('info', sprintf('%d of %d bullets contain no number. Quantified results (%%, €, users, time saved) read stronger.', $unquantified, $bulletCount));
        }

        $estimatedPages = max(1, (int) ceil($chars / self::CHARS_PER_PAGE));
        $pages = $renderedPages ?? $estimatedPages;
        $maxPages = (int) ($template->getManifest()['maxPages'] ?? 2);
        if ($pages > $maxPages) {
            $add('warning', sprintf('The CV runs to %s%d pages; this template is designed for at most %d. Hide or shorten entries.', $renderedPages === null ? 'about ' : '', $pages, $maxPages));
        }

        $stats = ['characters' => $chars, 'bullets' => $bulletCount, 'pages' => $pages, 'pages_measured' => $renderedPages !== null];

        if ($jobDescription !== null && trim($jobDescription) !== '') {
            $keywords = $this->keywords($jobDescription);
            $haystack = mb_strtolower(implode("\n", $allText).' '.($header['headline'] ?? ''));
            // Whole-word match so "aws" does not count as found inside "laws"
            $missing = array_values(array_filter($keywords, static fn (string $k) => !preg_match('/(?<![\p{L}\p{N}])'.preg_quote($k, '/').'(?![\p{L}\p{N}])/u', $haystack)));
            $coverage = $keywords === [] ? 100 : (int) round(100 * (\count($keywords) - \count($missing)) / \count($keywords));
            $stats['keyword_coverage'] = $coverage;
            $stats['missing_keywords'] = $missing;
            $add($coverage < 60 ? 'warning' : 'info', sprintf('The CV covers %d%% of the job description\'s key terms.%s', $coverage, $missing !== [] ? ' Missing: '.implode(', ', \array_slice($missing, 0, 15)).'.' : ''));
        }

        if ($findings === []) {
            $add('info', 'No problems found.');
        }

        return ['findings' => $findings, 'stats' => $stats];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private function visibleOnly(string $type, array $content): array
    {
        if (SectionTypes::isText($type)) {
            return $content;
        }
        $content['items'] = array_values(array_filter($content['items'], static fn (array $i) => $i['visible']));
        foreach ($content['items'] as &$item) {
            if (isset($item['bullets'])) {
                $item['bullets'] = array_values(array_filter($item['bullets'], static fn (array $b) => $b['visible']));
            }
        }

        return $content;
    }

    private function dateFormat(string $value): string
    {
        $value = trim($value);

        return match (true) {
            (bool) preg_match('/^(present|current|now|today|oggi|presente|heute|actualidad|présent)$/iu', $value) => 'other',
            (bool) preg_match('/^\p{L}{3,9}\.? \d{4}$/u', $value) => 'Mon YYYY',
            (bool) preg_match('/^\d{1,2}[\/.-]\d{4}$/', $value) => 'MM/YYYY',
            (bool) preg_match('/^\d{4}[\/.-]\d{1,2}$/', $value) => 'YYYY-MM',
            (bool) preg_match('/^\d{4}$/', $value) => 'YYYY',
            default => 'other',
        };
    }

    /**
     * The most frequent meaningful terms of a job description (including tech names like C++ or Node.js).
     *
     * @return list<string>
     */
    public function keywords(string $text, int $limit = 25): array
    {
        preg_match_all('/[\p{L}][\p{L}\p{N}+#.\/-]*[\p{L}\p{N}+#]|[\p{L}]{2}/u', mb_strtolower($text), $matches);
        $counts = [];
        foreach ($matches[0] as $word) {
            $word = rtrim($word, '.');
            if (mb_strlen($word) < 3 && !preg_match('/^(c#|c\+\+|go|r|ai|ml|ui|ux|qa)$/', $word)) {
                continue;
            }
            if (\in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }
        arsort($counts);

        return \array_slice(array_keys($counts), 0, $limit);
    }
}
