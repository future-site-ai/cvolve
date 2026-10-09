<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Render;

/**
 * Maps a CVolve render context to the JSON Resume schema (https://jsonresume.org/schema),
 * so themes written for JSON Resume can render CVolve CVs.
 */
final class JsonResumeMapper
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function fromContext(array $context): array
    {
        $h = \is_array($context['header'] ?? null) ? $context['header'] : [];
        $profiles = [];
        foreach (['linkedin' => 'LinkedIn', 'github' => 'GitHub'] as $key => $network) {
            $url = (string) ($h[$key] ?? '');
            if ($url !== '') {
                $profiles[] = ['network' => $network, 'url' => self::absolute($url), 'username' => basename(rtrim($url, '/'))];
            }
        }

        $resume = [
            'basics' => [
                'name' => (string) ($h['name'] ?? ''),
                'label' => (string) ($h['headline'] ?? ''),
                'image' => $h['photo'] ?? null,
                'picture' => $h['photo'] ?? null,
                'email' => (string) ($h['email'] ?? ''),
                'phone' => (string) ($h['phone'] ?? ''),
                'url' => self::absolute((string) ($h['website'] ?? '')),
                'website' => self::absolute((string) ($h['website'] ?? '')),
                'summary' => '',
                'location' => ['address' => (string) ($h['location'] ?? ''), 'city' => (string) ($h['location'] ?? '')],
                'profiles' => $profiles,
            ],
            'work' => [], 'volunteer' => [], 'education' => [], 'awards' => [], 'certificates' => [],
            'publications' => [], 'skills' => [], 'languages' => [], 'interests' => [], 'references' => [], 'projects' => [],
        ];

        foreach (\is_array($context['sections'] ?? null) ? $context['sections'] : [] as $section) {
            $items = \is_array($section['items'] ?? null) ? $section['items'] : [];
            switch ($section['type'] ?? '') {
                case 'summary':
                    $resume['basics']['summary'] = (string) ($section['text'] ?? '');
                    break;
                case 'experience':
                    foreach ($items as $i) {
                        $resume['work'][] = [
                            'name' => $i['organization'] ?? '', 'company' => $i['organization'] ?? '', 'position' => $i['title'] ?? '',
                            'location' => $i['location'] ?? '', 'startDate' => $i['start'] ?? '', 'endDate' => $i['end'] ?? '',
                            'summary' => $i['description'] ?? '', 'highlights' => $i['bullets'] ?? [], 'url' => '',
                        ];
                    }
                    break;
                case 'projects':
                    foreach ($items as $i) {
                        $resume['projects'][] = [
                            'name' => $i['name'] ?? '', 'description' => $i['description'] ?? '', 'highlights' => $i['bullets'] ?? [],
                            'startDate' => $i['start'] ?? '', 'endDate' => $i['end'] ?? '', 'url' => $i['url'] ?? '',
                            'roles' => ($i['role'] ?? '') !== '' ? [$i['role']] : [], 'keywords' => self::split($i['tech'] ?? ''),
                        ];
                    }
                    break;
                case 'education':
                    foreach ($items as $i) {
                        $resume['education'][] = [
                            'institution' => $i['institution'] ?? '', 'area' => $i['degree'] ?? '', 'studyType' => '',
                            'startDate' => $i['start'] ?? '', 'endDate' => $i['end'] ?? '', 'score' => $i['grade'] ?? '',
                            'gpa' => $i['grade'] ?? '', 'courses' => ($i['description'] ?? '') !== '' ? [$i['description']] : [],
                        ];
                    }
                    break;
                case 'skills':
                    foreach ($items as $i) {
                        $resume['skills'][] = ['name' => $i['group'] ?? '', 'level' => '', 'keywords' => self::split($i['skills'] ?? '')];
                    }
                    break;
                case 'languages':
                    foreach ($items as $i) {
                        $resume['languages'][] = ['language' => $i['name'] ?? '', 'fluency' => $i['level'] ?? ''];
                    }
                    break;
                case 'certifications':
                    foreach ($items as $i) {
                        $resume['certificates'][] = ['name' => $i['name'] ?? '', 'issuer' => $i['issuer'] ?? '', 'date' => $i['date'] ?? '', 'url' => $i['url'] ?? ''];
                    }
                    break;
                case 'publications':
                    foreach ($items as $i) {
                        $resume['publications'][] = ['name' => $i['title'] ?? '', 'publisher' => $i['venue'] ?? '', 'releaseDate' => $i['date'] ?? '', 'url' => $i['url'] ?? '', 'summary' => $i['authors'] ?? ''];
                    }
                    break;
                case 'custom':
                    self::custom($resume, (string) ($section['title'] ?? ''), $items);
                    break;
            }
        }

        return self::compat(self::plain($resume));
    }

    /**
     * Values that popular theme generators compute in their index.js before rendering
     * (emailBool, workBool, capitalName, startDateYear…), so those themes work unchanged.
     *
     * @param array<string, mixed> $resume
     *
     * @return array<string, mixed>
     */
    private static function compat(array $resume): array
    {
        $b = &$resume['basics'];
        $b['capitalName'] = mb_strtoupper((string) $b['name']);
        $b['capitalLabel'] = mb_strtoupper((string) $b['label']);
        foreach (['email' => 'emailBool', 'phone' => 'phoneBool', 'image' => 'pictureBool', 'website' => 'websiteBool', 'summary' => 'aboutBool'] as $field => $flag) {
            $resume[$flag] = !empty($b[$field]);
        }
        foreach (['work', 'volunteer', 'education', 'awards', 'certificates', 'publications', 'skills', 'languages', 'interests', 'references', 'projects'] as $list) {
            $resume[$list.'Bool'] = $resume[$list] !== [];
        }
        foreach (['work' => 'workHighlights', 'volunteer' => 'volunterHighlights', 'projects' => 'projectHighlights'] as $list => $flag) {
            foreach ($resume[$list] as &$item) {
                $item[$flag] = !empty($item['highlights']);
                $item['boolHighlights'] = !empty($item['highlights']);
                foreach (['startDate', 'endDate'] as $date) {
                    $value = (string) ($item[$date] ?? '');
                    $hasYear = (bool) preg_match('/\d{4}/', $value, $y);
                    $item[$date.'Year'] = $hasYear ? $y[0] : $value;
                    $item[$date.'Month'] = $hasYear && trim((string) preg_replace('/\d{4}/', '', $value)) !== '' ? trim((string) preg_replace('/\d{4}/', '', $value)).' ' : '';
                }
            }
            unset($item);
        }
        foreach ($resume['education'] as &$item) {
            $item['educationCourses'] = !empty($item['courses']);
            $item['gpaBool'] = !empty($item['gpa']);
        }
        unset($item, $b);
        $resume['skillsLevel'] = false;

        return $resume;
    }

    /**
     * JSON Resume text is plain: drop CVolve's **bold** / *italic* markers.
     */
    private static function plain(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map([self::class, 'plain'], $value);
        }
        if (!\is_string($value) || str_starts_with($value, 'data:')) {
            return $value;
        }

        return (string) preg_replace(['/\*\*(.+?)\*\*/su', '/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/su'], '$1', $value);
    }

    /**
     * Custom sections go to the closest JSON Resume list, judged by their title.
     *
     * @param array<string, mixed>        $resume
     * @param list<array<string, mixed>>  $items
     */
    private static function custom(array &$resume, string $title, array $items): void
    {
        $t = mb_strtolower($title);
        foreach ($items as $i) {
            $text = trim(($i['title'] ?? '').' '.($i['description'] ?? ''));
            if (preg_match('/volunt/u', $t)) {
                $resume['volunteer'][] = ['organization' => $i['subtitle'] ?? ($i['title'] ?? ''), 'position' => $i['title'] ?? '', 'startDate' => $i['date'] ?? '', 'summary' => $i['description'] ?? '', 'highlights' => []];
            } elseif (preg_match('/award|honou?r|prem/u', $t)) {
                $resume['awards'][] = ['title' => $i['title'] ?: ($i['description'] ?? ''), 'date' => $i['date'] ?? '', 'awarder' => $i['subtitle'] ?? '', 'summary' => $i['title'] ? ($i['description'] ?? '') : ''];
            } elseif (preg_match('/interest|hobb/u', $t)) {
                $resume['interests'][] = ['name' => $text, 'keywords' => []];
            } elseif (preg_match('/referen/u', $t)) {
                $resume['references'][] = ['name' => $i['title'] ?? '', 'reference' => $i['description'] ?? ''];
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', \is_scalar($value) ? (string) $value : '')), static fn (string $s) => $s !== ''));
    }

    private static function absolute(string $url): string
    {
        $url = trim($url);

        return $url === '' || preg_match('#^[a-z]+://#i', $url) ? $url : 'https://'.$url;
    }
}
