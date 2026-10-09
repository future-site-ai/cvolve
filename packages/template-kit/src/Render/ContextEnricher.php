<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Render;

/**
 * Adds convenience values to a render context so every template syntax can use them:
 *   section.is_<type>   true for the section's type (handy in Mustache: {{#is_experience}})
 *   section.has_items   the section has visible entries
 *   item.period         "start – end" (or the date)
 *   item.heading        title / name / degree / group, whatever the type
 *   item.subheading     organization / institution / issuer / venue / role / level (+ location)
 *   by_type.<type>      the first visible section of that type
 *   resume              the CV in JSON Resume schema (for JSON Resume themes)
 */
final class ContextEnricher
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function enrich(array $context): array
    {
        if (isset($context['_enriched'])) {
            return $context;
        }
        $sections = array_map([self::class, 'section'], \is_array($context['sections'] ?? null) ? $context['sections'] : []);
        $context['sections'] = $sections;

        foreach (\is_array($context['regions'] ?? null) ? $context['regions'] : [] as $region => $list) {
            $context['regions'][$region] = array_map([self::class, 'section'], \is_array($list) ? $list : []);
        }

        $byType = [];
        foreach ($sections as $section) {
            $byType[$section['type']] ??= $section;
        }
        $context['by_type'] = $byType;
        $context['resume'] ??= JsonResumeMapper::fromContext($context);
        $context['_enriched'] = true;

        return $context;
    }

    /**
     * @param array<string, mixed> $section
     *
     * @return array<string, mixed>
     */
    private static function section(array $section): array
    {
        $section['is_'.($section['type'] ?? 'unknown')] = true;
        $items = [];
        foreach (\is_array($section['items'] ?? null) ? $section['items'] : [] as $item) {
            if (\is_array($item)) {
                $start = trim((string) ($item['start'] ?? ''));
                $end = trim((string) ($item['end'] ?? ($item['date'] ?? '')));
                $item['period'] ??= $start !== '' && $end !== '' ? $start.' – '.$end : ($start !== '' ? $start : $end);
                // Type-independent labels, so one loop can render any section's entries
                $item['heading'] ??= (string) ($item['title'] ?? $item['name'] ?? $item['degree'] ?? $item['group'] ?? '');
                $item['subheading'] ??= implode(', ', array_filter([
                    (string) ($item['organization'] ?? $item['institution'] ?? $item['issuer'] ?? $item['venue'] ?? $item['role'] ?? $item['level'] ?? $item['subtitle'] ?? ''),
                    (string) ($item['location'] ?? ''),
                ]));
            }
            $items[] = $item;
        }
        $section['items'] = $items;
        $section['has_items'] = $items !== [];

        return $section;
    }
}
