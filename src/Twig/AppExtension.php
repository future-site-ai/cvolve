<?php

declare(strict_types=1);

namespace App\Twig;

use Cvolve\TemplateKit\SectionTypes;
use Cvolve\TemplateKit\Sandbox\TemplateHelpersExtension;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('chat_markdown', [$this, 'chatMarkdown'], ['is_safe' => ['html']]),
            new TwigFilter('cv_inline', [TemplateHelpersExtension::class, 'inlineMarkdown'], ['is_safe' => ['html']]),
            new TwigFilter('diff_compact', [$this, 'diffCompact']),
            new TwigFilter('section_summary', [$this, 'sectionSummary']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('section_label', static fn (string $type) => SectionTypes::exists($type) ? SectionTypes::get($type)['label'] : $type),
            new TwigFunction('locale_name', static fn (string $locale) => SectionTypes::LOCALES[$locale] ?? $locale),
        ];
    }

    /**
     * Safe subset of Markdown for assistant replies: paragraphs, "- " lists, **bold**, *italic*, `code`, links.
     */
    public function chatMarkdown(?string $text): string
    {
        $html = '';
        $inList = false;
        foreach (preg_split('/\R/', htmlspecialchars((string) $text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8')) ?: [] as $line) {
            $line = $this->inline($line);
            if (preg_match('/^\s*[-*•]\s+(.*)$/u', $line, $m)) {
                $html .= ($inList ? '' : '<ul>').'<li>'.$m[1].'</li>';
                $inList = true;
                continue;
            }
            if ($inList) {
                $html .= '</ul>';
                $inList = false;
            }
            $html .= trim($line) === '' ? '' : '<p>'.$line.'</p>';
        }

        return $html.($inList ? '</ul>' : '');
    }

    private function inline(string $line): string
    {
        $line = (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $line);
        $line = (string) preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/u', '<em>$1</em>', $line);
        $line = (string) preg_replace('/`([^`]+)`/u', '<code>$1</code>', $line);

        // [label](url) with http(s) or site-relative targets only
        return (string) preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/)[^\s)]+)\)/u', static fn (array $m) => sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', $m[2], $m[1]), $line);
    }

    /**
     * Keep changed lines with a little context; fold long unchanged runs.
     *
     * @param list<array{type: string, line: string}> $diff
     *
     * @return list<array{type: string, line: string}>
     */
    public function diffCompact(array $diff, int $context = 2): array
    {
        $keep = [];
        foreach ($diff as $i => $row) {
            if ($row['type'] !== 'same') {
                for ($j = max(0, $i - $context); $j <= min(\count($diff) - 1, $i + $context); ++$j) {
                    $keep[$j] = true;
                }
            }
        }
        $out = [];
        $skipped = 0;
        foreach ($diff as $i => $row) {
            if (isset($keep[$i]) || $row['type'] !== 'same') {
                if ($skipped > 0) {
                    $out[] = ['type' => 'collapsed', 'line' => sprintf('… %d unchanged line%s', $skipped, $skipped > 1 ? 's' : '')];
                    $skipped = 0;
                }
                $out[] = $row;
            } else {
                ++$skipped;
            }
        }
        if ($skipped > 0) {
            $out[] = ['type' => 'collapsed', 'line' => sprintf('… %d unchanged line%s', $skipped, $skipped > 1 ? 's' : '')];
        }

        return $out;
    }

    /**
     * One-line description of a CV section for the editor's section list.
     *
     * @param array<string, mixed> $content
     */
    public function sectionSummary(array $content, string $type): string
    {
        if (!SectionTypes::exists($type)) {
            return '';
        }
        $content = SectionTypes::normalize($type, $content);
        if (SectionTypes::isText($type)) {
            $text = strip_tags(str_replace('**', '', (string) $content['text']));

            return $text === '' ? 'Empty' : mb_strimwidth($text, 0, 110, '…');
        }
        $items = $content['items'];
        $visible = array_filter($items, static fn (array $i) => $i['visible']);
        $first = array_key_first(SectionTypes::fields($type));
        $names = array_map(static fn (array $i) => str_replace('**', '', (string) $i[$first]), \array_slice(array_values($visible), 0, 3));

        return sprintf('%d of %d shown%s', \count($visible), \count($items), $names !== [] ? ': '.mb_strimwidth(implode(', ', $names), 0, 80, '…') : '');
    }
}
