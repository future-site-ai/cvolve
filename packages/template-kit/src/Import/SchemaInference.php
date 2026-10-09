<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

use Cvolve\TemplateKit\SectionTypes;

/**
 * Guesses schema.json for templates imported without one: which regions the markup
 * uses and which section types it renders.
 */
final class SchemaInference
{
    private const RESUME_KEYS = [
        'work' => 'experience', 'education' => 'education', 'skills' => 'skills', 'languages' => 'languages',
        'projects' => 'projects', 'publications' => 'publications', 'certificates' => 'certifications',
        'volunteer' => 'custom', 'awards' => 'custom', 'interests' => 'custom', 'references' => 'custom', 'summary' => 'summary',
    ];

    private const SIDE_REGION = '/^(side|sidebar|aside|left|right|secondary|column2|col2|rail)$/';
    private const SIDE_TYPES = ['skills', 'languages', 'certifications', 'custom'];

    /**
     * @return array{regions: list<string>, sections: array<string, array<string, string>>}
     */
    public function infer(string $twig): array
    {
        preg_match_all('/regions\.([a-z][a-z0-9_]*)/', $twig, $m);
        $regions = array_values(array_unique($m[1]));
        if ($regions === []) {
            $regions = ['main'];
        }

        $types = [];
        $all = array_keys(SectionTypes::TYPES);
        foreach ($all as $type) {
            if (preg_match('/is_'.$type.'\b|by_type\.'.$type.'\b|[\'"]'.$type.'[\'"]/', $twig)) {
                $types[] = $type;
            }
        }
        foreach (self::RESUME_KEYS as $key => $type) {
            if (preg_match('/resume\.(basics\.)?'.$key.'\b|\(resume\.'.$key.' \?\?/', $twig)) {
                $types[] = $type;
            }
        }
        // A generic loop over sections or regions renders every type
        if ($types === [] || preg_match('/for\s+\w+\s+in\s+\(?(sections|regions\.\w+)\b/', $twig)) {
            $types = [...$types, ...$all];
        }
        $types = array_values(array_intersect($all, $types));

        $side = array_values(array_filter($regions, static fn (string $r) => (bool) preg_match(self::SIDE_REGION, $r)))[0] ?? null;
        $main = array_values(array_filter($regions, static fn (string $r) => $r !== $side))[0] ?? $regions[0];

        $sections = [];
        foreach ($types as $type) {
            $sections[$type] = ['region' => $side !== null && \in_array($type, self::SIDE_TYPES, true) ? $side : $main];
        }

        return ['regions' => $regions, 'sections' => $sections];
    }

    /**
     * Fonts declared with @font-face that point into fonts/.
     *
     * @return list<array{family: string, file: string, weight: string, style: string}>
     */
    public function fonts(string $css): array
    {
        $fonts = [];
        preg_match_all('#@font-face\s*\{([^}]*)\}#i', $css, $blocks);
        foreach ($blocks[1] as $block) {
            if (!preg_match('#font-family\s*:\s*["\']?([^;"\']+)#i', $block, $family) || !preg_match('#url\(\s*["\']?(fonts/[A-Za-z0-9._-]+)#i', $block, $file)) {
                continue;
            }
            $weight = preg_match('#font-weight\s*:\s*(\d{3})#i', $block, $w) ? $w[1] : '400';
            $style = preg_match('#font-style\s*:\s*italic#i', $block) ? 'italic' : 'normal';
            $fonts[] = ['family' => trim($family[1]), 'file' => $file[1], 'weight' => $weight, 'style' => $style];
        }

        return $fonts;
    }
}
