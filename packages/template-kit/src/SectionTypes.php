<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit;

/**
 * The catalogue of CV section types and the JSON shape of their content.
 *
 * - "text" sections store   {"text": "..."}
 * - "items" sections store  {"items": [{<fields>, "visible": true, "bullets": [{"text": "...", "visible": true}]}]}
 *
 * Inline emphasis is allowed in every text value: **bold** and *italic*.
 */
final class SectionTypes
{
    public const LOCALES = [
        'en' => 'English', 'it' => 'Italiano', 'fr' => 'Français', 'de' => 'Deutsch',
        'es' => 'Español', 'pt' => 'Português', 'nl' => 'Nederlands', 'ar' => 'العربية',
    ];

    public const TYPES = [
        'summary' => [
            'kind' => 'text',
            'label' => 'Professional summary',
        ],
        'experience' => [
            'kind' => 'items',
            'label' => 'Work experience',
            'fields' => ['title' => 'Job title', 'organization' => 'Company', 'location' => 'Location', 'start' => 'Start', 'end' => 'End', 'description' => 'Description', 'tech' => 'Tech stack'],
            'bullets' => true,
        ],
        'projects' => [
            'kind' => 'items',
            'label' => 'Projects',
            'fields' => ['name' => 'Project', 'role' => 'Role', 'start' => 'Start', 'end' => 'End', 'url' => 'URL', 'tech' => 'Tech stack', 'description' => 'Description'],
            'bullets' => true,
        ],
        'education' => [
            'kind' => 'items',
            'label' => 'Education',
            'fields' => ['degree' => 'Degree', 'institution' => 'Institution', 'location' => 'Location', 'start' => 'Start', 'end' => 'End', 'grade' => 'Grade', 'description' => 'Description'],
            'bullets' => false,
        ],
        'skills' => [
            'kind' => 'items',
            'label' => 'Skills',
            'fields' => ['group' => 'Group', 'skills' => 'Skills (comma separated)'],
            'bullets' => false,
        ],
        'languages' => [
            'kind' => 'items',
            'label' => 'Languages',
            'fields' => ['name' => 'Language', 'level' => 'Level', 'note' => 'Note'],
            'bullets' => false,
        ],
        'certifications' => [
            'kind' => 'items',
            'label' => 'Certifications',
            'fields' => ['name' => 'Certification', 'issuer' => 'Issuer', 'date' => 'Date', 'url' => 'URL'],
            'bullets' => false,
        ],
        'publications' => [
            'kind' => 'items',
            'label' => 'Publications',
            'fields' => ['title' => 'Title', 'authors' => 'Authors', 'venue' => 'Venue', 'date' => 'Date', 'url' => 'URL'],
            'bullets' => false,
        ],
        'custom' => [
            'kind' => 'items',
            'label' => 'Custom section',
            'fields' => ['title' => 'Title', 'subtitle' => 'Subtitle', 'date' => 'Date', 'description' => 'Description'],
            'bullets' => false,
        ],
    ];

    /** Fields edited with a textarea rather than a single-line input. */
    public const MULTILINE_FIELDS = ['description', 'skills', 'authors'];

    public const HEADER_FIELDS = [
        'name' => 'Full name', 'headline' => 'Headline', 'email' => 'Email', 'phone' => 'Phone',
        'location' => 'Address / location', 'website' => 'Website', 'linkedin' => 'LinkedIn', 'github' => 'GitHub',
        'nationality' => 'Nationality', 'dateOfBirth' => 'Date of birth', 'workAuthorization' => 'Work authorisation',
        'drivingLicence' => 'Driving licence', 'extra' => 'Extra line',
    ];

    private const TITLES = [
        'summary' => ['en' => 'Profile', 'it' => 'Profilo', 'fr' => 'Profil', 'de' => 'Profil', 'es' => 'Perfil', 'pt' => 'Perfil', 'nl' => 'Profiel', 'ar' => 'الملف الشخصي'],
        'experience' => ['en' => 'Work Experience', 'it' => 'Esperienza Lavorativa', 'fr' => 'Expérience Professionnelle', 'de' => 'Berufserfahrung', 'es' => 'Experiencia Laboral', 'pt' => 'Experiência Profissional', 'nl' => 'Werkervaring', 'ar' => 'الخبرة العملية'],
        'projects' => ['en' => 'Key Projects', 'it' => 'Progetti', 'fr' => 'Projets', 'de' => 'Projekte', 'es' => 'Proyectos', 'pt' => 'Projetos', 'nl' => 'Projecten', 'ar' => 'المشاريع'],
        'education' => ['en' => 'Education', 'it' => 'Istruzione', 'fr' => 'Formation', 'de' => 'Ausbildung', 'es' => 'Educación', 'pt' => 'Formação', 'nl' => 'Opleiding', 'ar' => 'التعليم'],
        'skills' => ['en' => 'Skills', 'it' => 'Competenze', 'fr' => 'Compétences', 'de' => 'Kenntnisse', 'es' => 'Habilidades', 'pt' => 'Competências', 'nl' => 'Vaardigheden', 'ar' => 'المهارات'],
        'languages' => ['en' => 'Languages', 'it' => 'Lingue', 'fr' => 'Langues', 'de' => 'Sprachen', 'es' => 'Idiomas', 'pt' => 'Idiomas', 'nl' => 'Talen', 'ar' => 'اللغات'],
        'certifications' => ['en' => 'Certifications', 'it' => 'Certificazioni', 'fr' => 'Certifications', 'de' => 'Zertifikate', 'es' => 'Certificaciones', 'pt' => 'Certificações', 'nl' => 'Certificeringen', 'ar' => 'الشهادات'],
        'publications' => ['en' => 'Publications', 'it' => 'Pubblicazioni', 'fr' => 'Publications', 'de' => 'Publikationen', 'es' => 'Publicaciones', 'pt' => 'Publicações', 'nl' => 'Publicaties', 'ar' => 'المنشورات'],
        'custom' => ['en' => 'Additional Information', 'it' => 'Ulteriori Informazioni', 'fr' => 'Informations Complémentaires', 'de' => 'Weitere Angaben', 'es' => 'Información Adicional', 'pt' => 'Informações Adicionais', 'nl' => 'Aanvullende Informatie', 'ar' => 'معلومات إضافية'],
    ];

    /** Fixed words a template may print (template variable "labels"). */
    private const LABELS = [
        'en' => ['contact' => 'Contact', 'personal' => 'Personal', 'present' => 'Present', 'nationality' => 'Nationality', 'born' => 'Born', 'work_authorization' => 'Work authorisation', 'driving_licence' => 'Driving licence', 'tech' => 'Tech', 'grade' => 'Grade'],
        'it' => ['contact' => 'Contatti', 'personal' => 'Dati personali', 'present' => 'Presente', 'nationality' => 'Nazionalità', 'born' => 'Nato il', 'work_authorization' => 'Permesso di lavoro', 'driving_licence' => 'Patente', 'tech' => 'Tecnologie', 'grade' => 'Voto'],
        'fr' => ['contact' => 'Contact', 'personal' => 'Informations personnelles', 'present' => 'Présent', 'nationality' => 'Nationalité', 'born' => 'Né le', 'work_authorization' => 'Autorisation de travail', 'driving_licence' => 'Permis de conduire', 'tech' => 'Technologies', 'grade' => 'Note'],
        'de' => ['contact' => 'Kontakt', 'personal' => 'Persönliches', 'present' => 'heute', 'nationality' => 'Staatsangehörigkeit', 'born' => 'Geboren', 'work_authorization' => 'Arbeitserlaubnis', 'driving_licence' => 'Führerschein', 'tech' => 'Technologien', 'grade' => 'Note'],
        'es' => ['contact' => 'Contacto', 'personal' => 'Datos personales', 'present' => 'Actualidad', 'nationality' => 'Nacionalidad', 'born' => 'Nacimiento', 'work_authorization' => 'Permiso de trabajo', 'driving_licence' => 'Permiso de conducir', 'tech' => 'Tecnologías', 'grade' => 'Nota'],
    ];

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return array<string, mixed> */
    public static function get(string $type): array
    {
        if (!self::exists($type)) {
            throw new \InvalidArgumentException(sprintf('Unknown section type "%s". Known types: %s.', $type, implode(', ', array_keys(self::TYPES))));
        }

        return self::TYPES[$type];
    }

    public static function isText(string $type): bool
    {
        return self::get($type)['kind'] === 'text';
    }

    /** @return array<string, string> field => label */
    public static function fields(string $type): array
    {
        return self::get($type)['fields'] ?? [];
    }

    public static function hasBullets(string $type): bool
    {
        return (bool) (self::get($type)['bullets'] ?? false);
    }

    public static function defaultTitle(string $type, string $locale = 'en'): string
    {
        return self::TITLES[$type][$locale] ?? self::TITLES[$type]['en'] ?? ucfirst($type);
    }

    /** @return array<string, string> */
    public static function labels(string $locale): array
    {
        return (self::LABELS[$locale] ?? []) + self::LABELS['en'];
    }

    /** @return array<string, mixed> */
    public static function emptyContent(string $type): array
    {
        return self::isText($type) ? ['text' => ''] : ['items' => []];
    }

    /**
     * Coerce loosely shaped content (from forms, the AI or an import) into the canonical shape.
     *
     * @param array<mixed> $content
     *
     * @return array<string, mixed>
     */
    public static function normalize(string $type, array $content): array
    {
        if (self::isText($type)) {
            return ['text' => self::str($content['text'] ?? '')];
        }

        $items = [];
        foreach (\is_array($content['items'] ?? null) ? $content['items'] : [] as $raw) {
            if (!\is_array($raw)) {
                continue;
            }
            $items[] = self::normalizeItem($type, $raw);
        }

        return ['items' => $items];
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function normalizeItem(string $type, array $raw): array
    {
        $item = [];
        foreach (array_keys(self::fields($type)) as $field) {
            $value = $raw[$field] ?? '';
            // Skills may arrive as a list
            if ($field === 'skills' && \is_array($value)) {
                $value = implode(', ', array_map(static fn ($v) => self::str($v), $value));
            }
            $item[$field] = self::str($value);
        }
        $item['visible'] = !\array_key_exists('visible', $raw) || filter_var($raw['visible'], \FILTER_VALIDATE_BOOL);

        if (self::hasBullets($type)) {
            $bullets = [];
            foreach (\is_array($raw['bullets'] ?? null) ? $raw['bullets'] : [] as $bullet) {
                $text = self::str(\is_array($bullet) ? ($bullet['text'] ?? '') : $bullet);
                if ($text === '') {
                    continue;
                }
                $visible = !\is_array($bullet) || !\array_key_exists('visible', $bullet) || filter_var($bullet['visible'], \FILTER_VALIDATE_BOOL);
                $bullets[] = ['text' => $text, 'visible' => $visible];
            }
            $item['bullets'] = $bullets;
        }

        return $item;
    }

    /**
     * Check content against the template's rules for this section type.
     *
     * @param array<string, mixed> $content normalized content
     * @param array<string, mixed> $rules   schema.json "sections.<type>" entry
     *
     * @return list<string> human readable problems
     */
    public static function validate(string $type, array $content, array $rules): array
    {
        $errors = [];
        if (self::isText($type)) {
            $max = (int) ($rules['maxLength'] ?? 0);
            $length = mb_strlen((string) ($content['text'] ?? ''));
            if ($max > 0 && $length > $max) {
                $errors[] = sprintf('The %s is %d characters long; this template allows at most %d.', $type, $length, $max);
            }

            return $errors;
        }

        $visible = array_values(array_filter($content['items'] ?? [], static fn (array $i) => $i['visible'] ?? true));
        $maxItems = (int) ($rules['maxItems'] ?? 0);
        if ($maxItems > 0 && \count($visible) > $maxItems) {
            $errors[] = sprintf('The %s section shows %d entries; this template allows at most %d.', $type, \count($visible), $maxItems);
        }
        $maxBullets = (int) ($rules['maxBullets'] ?? 0);
        if ($maxBullets > 0 && self::hasBullets($type)) {
            foreach ($visible as $i => $item) {
                $count = \count(array_filter($item['bullets'] ?? [], static fn (array $b) => $b['visible'] ?? true));
                if ($count > $maxBullets) {
                    $errors[] = sprintf('Entry #%d of %s shows %d bullets; this template allows at most %d.', $i, $type, $count, $maxBullets);
                }
            }
        }

        return $errors;
    }

    /**
     * Plain-text rendering used for diffs, version comparison and AI context.
     *
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    public static function toLines(string $type, array $content): array
    {
        if (self::isText($type)) {
            $text = (string) ($content['text'] ?? '');

            return $text === '' ? [] : (preg_split('/\R/', $text) ?: []);
        }

        $lines = [];
        foreach ($content['items'] ?? [] as $index => $item) {
            $hidden = ($item['visible'] ?? true) ? '' : '[hidden] ';
            $values = [];
            foreach (array_keys(self::fields($type)) as $field) {
                if (($item[$field] ?? '') !== '' && $field !== 'description') {
                    $values[] = $item[$field];
                }
            }
            $lines[] = sprintf('#%d %s%s', $index, $hidden, implode(' | ', $values));
            if (($item['description'] ?? '') !== '') {
                foreach (preg_split('/\R/', (string) $item['description']) ?: [] as $line) {
                    $lines[] = '    '.$line;
                }
            }
            foreach ($item['bullets'] ?? [] as $b => $bullet) {
                $lines[] = sprintf('    %d.%d %s- %s', $index, $b, ($bullet['visible'] ?? true) ? '' : '[hidden] ', $bullet['text']);
            }
        }

        return $lines;
    }

    /**
     * A compact description of every content shape, for AI system prompts.
     */
    public static function describeForAi(): string
    {
        $out = [];
        foreach (self::TYPES as $type => $def) {
            if ($def['kind'] === 'text') {
                $out[] = sprintf('- %s: {"text": "..."}', $type);
                continue;
            }
            $fields = implode(', ', array_map(static fn (string $f) => sprintf('"%s"', $f), array_keys($def['fields'])));
            $bullets = $def['bullets'] ? ', "bullets": [{"text": "...", "visible": true}]' : '';
            $out[] = sprintf('- %s: {"items": [{%s, "visible": true%s}]}', $type, $fields, $bullets);
        }

        return implode("\n", $out);
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
