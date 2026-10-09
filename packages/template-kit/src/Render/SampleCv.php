<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Render;

use Cvolve\TemplateKit\SectionTypes;

/**
 * A fictional CV in render-context shape, used to validate imported templates
 * and to preview a template in the gallery.
 */
final class SampleCv
{
    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public const VARIANTS = ['default' => 'Typical CV', 'long' => 'Long CV (3+ pages)', 'minimal' => 'Minimal CV (no photo, few entries)'];

    public static function context(array $schema, string $locale = 'en', string $variant = 'default', ?string $photo = null): array
    {
        $sections = [
            ['type' => 'summary', 'text' => 'Product-minded software engineer with 7 years of experience building **reliable web platforms** for healthcare and finance. Comfortable owning features end to end, from data model to deployment.'],
            ['type' => 'experience', 'items' => [
                ['title' => 'Senior Software Engineer', 'organization' => 'Northwind Health', 'location' => 'Milan, Italy', 'start' => 'Mar 2021', 'end' => 'Present', 'description' => '', 'tech' => 'PHP 8, Symfony, PostgreSQL, Docker', 'bullets' => [
                    '**Patient portal:** led a team of four to ship a GDPR-compliant portal used by 120k patients.',
                    'Cut API response times by 45% by reworking the caching layer.',
                    'Introduced CI/CD with automated tests, reducing release time from days to hours.',
                ]],
                ['title' => 'Software Engineer', 'organization' => 'Fabrikam Payments', 'location' => 'Lisbon, Portugal', 'start' => 'Jan 2018', 'end' => 'Feb 2021', 'description' => '', 'tech' => 'Python, Django, React', 'bullets' => [
                    'Built the merchant onboarding flow (KYC) processing 2,000 applications a month.',
                    'Mentored three junior developers through code reviews and pairing.',
                ]],
            ]],
            ['type' => 'projects', 'items' => [
                ['name' => 'OpenClinic', 'role' => 'Maintainer', 'start' => '2022', 'end' => 'Present', 'url' => 'https://github.com/example/openclinic', 'tech' => 'Symfony, Vue', 'description' => 'Open-source appointment scheduler for small clinics.', 'bullets' => ['600+ GitHub stars, used by 40 clinics.']],
            ]],
            ['type' => 'education', 'items' => [
                ['degree' => 'M.Sc. Computer Engineering', 'institution' => 'Politecnico di Milano', 'location' => 'Milan, Italy', 'start' => '2015', 'end' => '2017', 'grade' => '110/110', 'description' => 'Thesis on distributed caching.'],
            ]],
            ['type' => 'skills', 'items' => [
                ['group' => 'Back-end', 'skills' => 'PHP, Symfony, Python, Django, REST'],
                ['group' => 'Data', 'skills' => 'PostgreSQL, Redis, Elasticsearch'],
                ['group' => 'DevOps', 'skills' => 'Docker, GitHub Actions, Linux'],
            ]],
            ['type' => 'languages', 'items' => [
                ['name' => 'English', 'level' => 'C1', 'note' => ''],
                ['name' => 'Italian', 'level' => 'Native', 'note' => ''],
            ]],
            ['type' => 'certifications', 'items' => [
                ['name' => 'Docker Certified Associate', 'issuer' => 'Docker', 'date' => '2024', 'url' => ''],
            ]],
            ['type' => 'publications', 'items' => [
                ['title' => 'Caching strategies for clinical APIs', 'authors' => 'A. Rossi, B. Bianchi', 'venue' => 'IEEE Software', 'date' => '2023', 'url' => ''],
            ]],
            ['type' => 'custom', 'title' => 'Volunteering', 'items' => [
                ['title' => 'Mentor', 'subtitle' => 'Code Club', 'date' => '2019 – Present', 'description' => 'Weekly coding sessions for teenagers.'],
            ]],
        ];

        if ($variant === 'long') {
            // Repeat jobs and bullets so templates can be checked across page breaks
            $jobs = $sections[1]['items'];
            foreach ([2016, 2013, 2010] as $year) {
                foreach ($jobs as $job) {
                    $job['start'] = 'Jan '.$year;
                    $job['end'] = 'Dec '.($year + 2);
                    $job['bullets'] = [...$job['bullets'], ...$job['bullets']];
                    $sections[1]['items'][] = $job;
                }
            }
        } elseif ($variant === 'minimal') {
            $sections = array_values(array_filter($sections, static fn (array $s) => \in_array($s['type'], ['summary', 'experience', 'education', 'skills'], true)));
            $sections[1]['items'] = \array_slice($sections[1]['items'], 0, 1);
        }

        $supported = \is_array($schema['sections'] ?? null) ? $schema['sections'] : [];
        $regionNames = \is_array($schema['regions'] ?? null) && $schema['regions'] !== [] ? array_values($schema['regions']) : ['main'];
        $out = [];
        $regions = array_fill_keys($regionNames, []);
        $id = 1;
        foreach ($sections as $section) {
            $type = $section['type'];
            if (!\array_key_exists($type, $supported)) {
                continue;
            }
            $region = $supported[$type]['region'] ?? $regionNames[0];
            $region = \in_array($region, $regionNames, true) ? $region : $regionNames[0];
            $entry = [
                'id' => $id++,
                'type' => $type,
                'title' => $section['title'] ?? SectionTypes::defaultTitle($type, $locale),
                'region' => $region,
                'text' => $section['text'] ?? '',
                'items' => $section['items'] ?? [],
            ];
            $out[] = $entry;
            $regions[$region][] = $entry;
        }

        return ContextEnricher::enrich([
            'cv' => ['title' => 'Sample CV', 'locale' => $locale, 'dir' => $locale === 'ar' ? 'rtl' : 'ltr'],
            'header' => [
                'name' => 'Alex Morgan', 'headline' => 'Senior Software Engineer', 'email' => 'alex.morgan@example.com',
                'phone' => '+39 000 000 0000', 'location' => 'Milan, Italy', 'website' => 'https://alexmorgan.dev',
                'linkedin' => 'https://linkedin.com/in/alexmorgan', 'github' => 'https://github.com/alexmorgan',
                'nationality' => 'Italian', 'dateOfBirth' => '', 'workAuthorization' => 'EU citizen', 'drivingLicence' => 'B',
                'extra' => '', 'photo' => $variant === 'minimal' ? null : ($photo ?? self::avatar()),
            ],
            'sections' => $out,
            'regions' => $regions,
            'labels' => SectionTypes::labels($locale),
        ]);
    }

    /**
     * A neutral placeholder portrait (SVG data URI) for previews.
     */
    public static function avatar(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="#c7d2de"/>'
            .'<circle cx="100" cy="78" r="38" fill="#8a9bb0"/><path d="M28 200c6-48 38-72 72-72s66 24 72 72z" fill="#8a9bb0"/></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
