<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Sandbox;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Small helpers available to sandboxed CV templates.
 */
final class TemplateHelpersExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            // **bold** and *italic* inside escaped text
            new TwigFilter('md', [self::class, 'inlineMarkdown'], ['is_safe' => ['html']]),
            // "Apr 2023 – Present" from an item with start/end
            new TwigFilter('period', [self::class, 'period']),
            // "https://www.linkedin.com/in/x/" -> "linkedin.com/in/x"
            new TwigFilter('pretty_url', [self::class, 'prettyUrl']),
            new TwigFilter('initials', [self::class, 'initials']),
            // Mustache/Handlebars section semantics: list stays a list, a map or truthy scalar becomes [value], falsy becomes []
            new TwigFilter('as_list', [self::class, 'asList']),
        ];
    }

    public static function inlineMarkdown(mixed $text): string
    {
        $html = htmlspecialchars(\is_scalar($text) ? (string) $text : '', \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/su', '<em>$1</em>', $html);

        return nl2br($html, false);
    }

    /**
     * @param array<string, mixed>|mixed $item
     */
    public static function period(mixed $item, string $separator = ' – '): string
    {
        if (!\is_array($item)) {
            return '';
        }
        $start = trim((string) ($item['start'] ?? ''));
        $end = trim((string) ($item['end'] ?? ($item['date'] ?? '')));
        if ($start !== '' && $end !== '') {
            return $start.$separator.$end;
        }

        return $start !== '' ? $start : $end;
    }

    public static function prettyUrl(mixed $url): string
    {
        $url = \is_scalar($url) ? trim((string) $url) : '';

        return rtrim((string) preg_replace('#^(https?://)?(www\.)?#i', '', $url), '/');
    }

    /**
     * @return array<mixed>
     */
    public static function asList(mixed $value): array
    {
        if (\is_array($value)) {
            return $value === [] || array_is_list($value) ? $value : [$value];
        }

        return $value === null || $value === false || $value === '' || $value === 0 ? [] : [$value];
    }

    public static function initials(mixed $name): string
    {
        $words = preg_split('/\s+/u', \is_scalar($name) ? trim((string) $name) : '') ?: [];
        $initials = '';
        foreach (\array_slice(array_filter($words), 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials;
    }
}
