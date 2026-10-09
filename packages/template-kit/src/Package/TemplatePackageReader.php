<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Package;

use Cvolve\TemplateKit\Render\SampleCv;
use Cvolve\TemplateKit\Sandbox\TemplateSandbox;
use Cvolve\TemplateKit\SectionTypes;
use Twig\Error\Error as TwigError;

/**
 * Reads and validates a .cvolve package (a zip, or an unpacked directory for the bundled presets).
 *
 * Package layout:
 *   manifest.json        name, slug, version, format, author, licence, description, page, fonts, docx
 *   template.html.twig   sandboxed Twig rendering the <body> content
 *   style.css            print-ready A4 CSS
 *   schema.json          regions + supported sections and their limits
 *   thumbnail.png        optional preview image
 *   fonts/*              optional bundled fonts (woff2, woff, ttf, otf)
 */
final class TemplatePackageReader
{
    public const FORMAT = 'cvolve-template';
    public const FORMAT_VERSION = 1;

    private const MAX_ENTRIES = 60;
    private const MAX_FILE_SIZE = 5_000_000;
    private const MAX_TOTAL_SIZE = 15_000_000;
    private const MAX_TWIG_SIZE = 200_000;

    private const ALLOWED_FILES = '#^(manifest\.json|schema\.json|template\.html\.twig|style\.css|thumbnail\.(png|jpe?g)|README\.md|LICEN[CS]E(\.txt|\.md)?|fonts/[A-Za-z0-9._-]+\.(woff2?|ttf|otf))$#';

    /** Raw HTML a template may not emit (CSS lives in style.css; no scripts, frames or remote loads). */
    private const FORBIDDEN_MARKUP = [
        '#<\s*script#i' => 'Scripts are not allowed in templates.',
        '#<\s*(iframe|frame|object|embed|applet|form|input|button|base|link|meta|style|noscript|template|portal)\b#i' => 'The <%s> element is not allowed in templates.',
        '#\son[a-z]+\s*=#i' => 'Inline event handlers (on...=) are not allowed.',
        '#javascript\s*:#i' => 'javascript: URLs are not allowed.',
        '#\bsrcdoc\b#i' => 'srcdoc is not allowed.',
        '#url\s*\(#i' => 'url() is not allowed in the template markup; use style.css.',
        '#<\s*img\b[^>]*\ssrc\s*=\s*["\']\s*(?!\{\{)#i' => 'Images must use a template variable as src (e.g. {{ header.photo }}).',
    ];

    public function __construct(
        private readonly TemplateSandbox $sandbox,
        private readonly CssSanitizer $cssSanitizer,
    ) {
    }

    public function readZip(string $path): TemplatePackage
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidTemplatePackageException(['The file is not a valid .cvolve (zip) archive.']);
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new InvalidTemplatePackageException([sprintf('The package has more than %d files.', self::MAX_ENTRIES)]);
            }

            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }
                $name = $stat['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
                    throw new InvalidTemplatePackageException([sprintf('Unsafe path in package: "%s".', $name)]);
                }
                if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                    continue;
                }
                if ($stat['size'] > self::MAX_FILE_SIZE) {
                    throw new InvalidTemplatePackageException([sprintf('"%s" is larger than %d MB.', $name, self::MAX_FILE_SIZE / 1_000_000)]);
                }
                $total += $stat['size'];
                if ($total > self::MAX_TOTAL_SIZE) {
                    throw new InvalidTemplatePackageException([sprintf('The unpacked package is larger than %d MB.', self::MAX_TOTAL_SIZE / 1_000_000)]);
                }
                $entries[$name] = $i;
            }

            // Accept packages zipped with a single top-level folder
            $prefix = $this->commonFolder(array_keys($entries));
            $files = [];
            foreach ($entries as $name => $index) {
                $relative = $prefix !== '' ? substr($name, \strlen($prefix)) : $name;
                $contents = $zip->getFromIndex($index);
                if ($contents === false) {
                    throw new InvalidTemplatePackageException([sprintf('Could not read "%s".', $name)]);
                }
                $files[$relative] = $contents;
            }
        } finally {
            $zip->close();
        }

        return $this->fromFiles($files);
    }

    public function readDirectory(string $dir): TemplatePackage
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getFilename() !== '.DS_Store') {
                $files[ltrim(str_replace('\\', '/', substr($file->getPathname(), \strlen(rtrim($dir, '/')))), '/')] = (string) file_get_contents($file->getPathname());
            }
        }

        return $this->fromFiles($files);
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    public function fromFiles(array $files): TemplatePackage
    {
        $errors = [];
        foreach (array_keys($files) as $name) {
            if (!preg_match(self::ALLOWED_FILES, $name)) {
                $errors[] = sprintf('File not allowed in a template package: "%s".', $name);
            }
        }
        foreach (['manifest.json', 'schema.json', 'template.html.twig', 'style.css'] as $required) {
            if (!isset($files[$required])) {
                $errors[] = sprintf('Missing required file "%s".', $required);
            }
        }
        if ($errors !== []) {
            throw new InvalidTemplatePackageException($errors);
        }

        $manifest = $this->decodeJson($files['manifest.json'], 'manifest.json', $errors);
        $schema = $this->decodeJson($files['schema.json'], 'schema.json', $errors);
        if ($errors !== []) {
            throw new InvalidTemplatePackageException($errors);
        }

        $errors = [
            ...$this->validateManifest($manifest, $files),
            ...$this->validateSchema($schema),
            ...$this->validateTwigSource($files['template.html.twig']),
            ...array_map(static fn (string $e) => 'style.css: '.$e, $this->cssSanitizer->check($files['style.css'])),
        ];
        if ($errors !== []) {
            throw new InvalidTemplatePackageException($errors);
        }

        $assets = array_filter($files, static fn (string $name) => str_starts_with($name, 'fonts/') || str_starts_with($name, 'thumbnail.'), \ARRAY_FILTER_USE_KEY);
        $package = new TemplatePackage($manifest, $schema, $files['template.html.twig'], $files['style.css'], $assets);

        // A trial render catches syntax errors and anything the sandbox forbids
        try {
            $html = $this->sandbox->render($package->twig, SampleCv::context($schema));
        } catch (TwigError $e) {
            throw new InvalidTemplatePackageException(['template.html.twig: '.$e->getMessage()]);
        }
        if (trim(strip_tags($html)) === '') {
            throw new InvalidTemplatePackageException(['template.html.twig renders no content for a sample CV.']);
        }

        return $package;
    }

    /**
     * @return list<string>
     */
    public function validateTwigSource(string $source): array
    {
        $errors = [];
        if (\strlen($source) > self::MAX_TWIG_SIZE) {
            $errors[] = sprintf('template.html.twig is larger than %d KB.', self::MAX_TWIG_SIZE / 1000);
        }
        foreach (self::FORBIDDEN_MARKUP as $pattern => $message) {
            if (preg_match($pattern, $source, $m)) {
                $errors[] = 'template.html.twig: '.sprintf($message, strtolower($m[1] ?? ''));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed>  $manifest
     * @param array<string, string> $files
     *
     * @return list<string>
     */
    private function validateManifest(array $manifest, array $files): array
    {
        $errors = [];
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            $errors[] = sprintf('manifest.json: "format" must be "%s".', self::FORMAT);
        }
        if ((int) ($manifest['formatVersion'] ?? 0) !== self::FORMAT_VERSION) {
            $errors[] = sprintf('manifest.json: "formatVersion" must be %d.', self::FORMAT_VERSION);
        }
        if (!\is_string($manifest['name'] ?? null) || trim($manifest['name']) === '' || mb_strlen($manifest['name']) > 120) {
            $errors[] = 'manifest.json: "name" is required (max 120 characters).';
        }
        if (!\is_string($manifest['slug'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', $manifest['slug'])) {
            $errors[] = 'manifest.json: "slug" is required (lowercase letters, digits and dashes).';
        }
        if (!\is_string($manifest['version'] ?? null) || !preg_match('/^\d+\.\d+(\.\d+)?$/', $manifest['version'])) {
            $errors[] = 'manifest.json: "version" must look like 1.0.0.';
        }
        if (isset($manifest['page']) && !\in_array($manifest['page'], ['A4', 'Letter'], true)) {
            $errors[] = 'manifest.json: "page" must be "A4" or "Letter".';
        }
        foreach (['author', 'licence', 'description'] as $field) {
            if (isset($manifest[$field]) && !\is_string($manifest[$field])) {
                $errors[] = sprintf('manifest.json: "%s" must be a string.', $field);
            }
        }
        foreach (\is_array($manifest['fonts'] ?? null) ? $manifest['fonts'] : [] as $i => $font) {
            $file = \is_array($font) ? ($font['file'] ?? null) : null;
            if (!\is_string($file) || !isset($files[$file]) || !str_starts_with($file, 'fonts/')) {
                $errors[] = sprintf('manifest.json: fonts[%d].file must reference a file in fonts/.', $i);
            }
            if (!\is_array($font) || !\is_string($font['family'] ?? null) || !preg_match('/^[A-Za-z0-9 _-]{1,60}$/', $font['family'])) {
                $errors[] = sprintf('manifest.json: fonts[%d].family is required (letters, digits, spaces).', $i);
            }
        }
        if (isset($manifest['docx']) && !\is_array($manifest['docx'])) {
            $errors[] = 'manifest.json: "docx" must be an object.';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private function validateSchema(array $schema): array
    {
        $errors = [];
        $regions = $schema['regions'] ?? ['main'];
        if (!\is_array($regions) || $regions === [] || array_filter($regions, static fn ($r) => !\is_string($r) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/', $r)) !== []) {
            $errors[] = 'schema.json: "regions" must be a list of names like ["sidebar", "main"].';
            $regions = ['main'];
        }
        $sections = $schema['sections'] ?? null;
        if (!\is_array($sections) || $sections === []) {
            return [...$errors, 'schema.json: "sections" must list at least one supported section type.'];
        }
        foreach ($sections as $type => $rules) {
            if (!SectionTypes::exists((string) $type)) {
                $errors[] = sprintf('schema.json: unknown section type "%s" (known: %s).', $type, implode(', ', array_keys(SectionTypes::TYPES)));
                continue;
            }
            if (!\is_array($rules)) {
                $errors[] = sprintf('schema.json: sections.%s must be an object.', $type);
                continue;
            }
            if (isset($rules['region']) && !\in_array($rules['region'], $regions, true)) {
                $errors[] = sprintf('schema.json: sections.%s.region "%s" is not a declared region.', $type, \is_scalar($rules['region']) ? $rules['region'] : '?');
            }
            foreach (['maxLength', 'maxItems', 'maxBullets'] as $limit) {
                if (isset($rules[$limit]) && (!\is_int($rules[$limit]) || $rules[$limit] < 1)) {
                    $errors[] = sprintf('schema.json: sections.%s.%s must be a positive integer.', $type, $limit);
                }
            }
        }

        return $errors;
    }

    /**
     * @param list<string> $errors
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $json, string $file, array &$errors): array
    {
        try {
            $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = sprintf('%s is not valid JSON: %s', $file, $e->getMessage());

            return [];
        }
        if (!\is_array($data) || array_is_list($data)) {
            $errors[] = sprintf('%s must contain a JSON object.', $file);

            return [];
        }

        return $data;
    }

    /**
     * @param list<string> $names
     */
    private function commonFolder(array $names): string
    {
        if ($names === [] || \in_array('manifest.json', $names, true)) {
            return '';
        }
        $first = explode('/', $names[0])[0].'/';
        foreach ($names as $name) {
            if (!str_starts_with($name, $first)) {
                return '';
            }
        }

        return $first;
    }
}
