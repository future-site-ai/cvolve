<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

use Cvolve\TemplateKit\Ai\TemplateAi;
use Cvolve\TemplateKit\Package\InvalidTemplatePackageException;
use Cvolve\TemplateKit\Package\TemplatePackageReader;

/**
 * Imports templates in every supported format and returns a validated .cvolve package:
 *
 *   .cvolve / zip with manifest.json      native package
 *   .html with {{mustache}} tags          "CVolve HTML" (Mustache placeholders)
 *   .html / .twig with {% twig %} tags     Twig markup without a manifest
 *   JSON Resume theme (zip with *.hbs)     Handlebars theme, rendered against the JSON Resume schema
 *   zip with index.html + CSS (+ fonts/)   any of the HTML flavours above, packaged
 *   static HTML CV (no placeholders)       converted by AI (needs an AI completion closure)
 */
final class TemplateImporter
{
    private const MAX_ENTRIES = 400;
    private const MAX_TOTAL = 30_000_000;
    private const FONT = '/\.(woff2?|ttf|otf)$/i';

    public function __construct(
        private readonly TemplatePackageReader $reader,
        private readonly HtmlCleaner $cleaner,
        private readonly MustacheConverter $mustache,
        private readonly HandlebarsConverter $handlebars,
        private readonly SchemaInference $inference,
        private readonly TemplateAi $ai,
    ) {
    }

    /**
     * @param \Closure(string, string): string|null $complete AI completion, needed only for static HTML
     * @param array<string, mixed>                  $manifest defaults for generated manifests (name, author…)
     */
    public function importFile(string $path, string $originalName, ?\Closure $complete = null, array $manifest = []): ImportResult
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);
        if (str_starts_with($head, "PK\x03\x04")) {
            // Native packages keep the strict zip checks of the reader
            $files = $this->unzip($path);
            $manifest['_fallbackName'] = self::nameFromFile($originalName);

            return $this->importFiles($files, $complete, $manifest);
        }

        $ext = strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        if (!\in_array($ext, ['html', 'htm', 'twig', 'mustache', 'hbs', 'handlebars'], true)) {
            throw new ConversionException([sprintf('Unsupported file type ".%s". Use .cvolve, .zip, .html, .twig, .mustache or .hbs.', $ext)]);
        }
        $manifest['_fallbackName'] = self::nameFromFile($originalName);

        return $this->importFiles([basename($originalName) => (string) file_get_contents($path)], $complete, $manifest);
    }

    /**
     * @param array<string, string>                 $files    relative path => contents
     * @param \Closure(string, string): string|null $complete
     * @param array<string, mixed>                  $manifest
     */
    public function importFiles(array $files, ?\Closure $complete = null, array $manifest = []): ImportResult
    {
        $files = $this->stripCommonFolder($files);

        if (isset($files['manifest.json'], $files['template.html.twig'])) {
            try {
                return new ImportResult($this->reader->fromFiles($files), ImportResult::FORMAT_CVOLVE);
            } catch (InvalidTemplatePackageException $e) {
                throw new ConversionException($e->errors);
            }
        }

        $hbs = array_values(array_filter(array_keys($files), static fn (string $f) => (bool) preg_match('/\.(hbs|handlebars|template)$/i', $f) && !str_contains($f, 'node_modules/')));
        // Themes often read their template in index.js: readFileSync(__dirname + "/resume.template")
        if (isset($files['index.js']) && preg_match_all('#["\'/]([\w./-]+\.(?:hbs|handlebars|template|html))["\']#', $files['index.js'], $refs)) {
            foreach ($refs[1] as $ref) {
                $ref = ltrim((string) preg_replace('#^\./#', '', $ref), '/');
                if (isset($files[$ref]) && !\in_array($ref, $hbs, true)) {
                    $hbs[] = $ref;
                }
            }
        }
        $isTheme = $hbs !== [] && (isset($files['package.json']) || array_filter($hbs, static fn (string $f) => (bool) preg_match('/\.(hbs|handlebars)$/i', $f)) !== []);

        return $isTheme ? $this->fromJsonResumeTheme($files, $hbs, $manifest) : $this->fromHtml($files, $complete, $manifest);
    }

    /**
     * @param array<string, string> $files
     * @param list<string>          $hbs
     * @param array<string, mixed>  $manifest
     */
    private function fromJsonResumeTheme(array $files, array $hbs, array $manifest): ImportResult
    {
        if ($hbs === []) {
            throw new ConversionException(['This JSON Resume theme has no Handlebars (.hbs) template. Themes that render with JavaScript or other engines cannot be imported.']);
        }
        $main = null;
        foreach (['resume.hbs', 'resume.template', 'template.hbs', 'index.hbs', 'resume.handlebars', 'views/resume.hbs', 'src/resume.hbs', 'theme/resume.hbs'] as $candidate) {
            if (isset($files[$candidate])) {
                $main = $candidate;
                break;
            }
        }
        if ($main === null) {
            $sorted = $hbs;
            usort($sorted, static fn (string $a, string $b) => [substr_count($a, '/'), \strlen($a)] <=> [substr_count($b, '/'), \strlen($b)]);
            $main = $sorted[0];
        }
        $partials = array_intersect_key($files, array_flip(array_diff($hbs, [$main])));

        $warnings = [];
        if (isset($files['index.js'])) {
            $warnings[] = 'The theme\'s index.js was ignored: CVolve renders the template directly with JSON Resume data (common computed fields such as workBool are provided).';
        }
        if (array_filter(array_keys($files), static fn (string $f) => (bool) preg_match('/\.(less|scss|sass|styl)$/i', $f)) !== []) {
            $warnings[] = 'LESS/SASS sources cannot be compiled; only plain .css files were used.';
        }

        $package = $this->packageJson($files);
        if (($manifest['name'] ?? '') === '' && isset($package['name'])) {
            $manifest['name'] = ucwords(str_replace('-', ' ', preg_replace('/^(@[^\/]+\/)?jsonresume-theme-/', '', (string) $package['name']) ?? ''));
        }
        $manifest['description'] ??= isset($package['description']) && \is_string($package['description']) ? $package['description'] : 'Imported JSON Resume theme.';
        if (isset($package['author'])) {
            $manifest['author'] ??= \is_array($package['author']) ? (string) ($package['author']['name'] ?? '') : (string) $package['author'];
        }

        [$fonts, $files] = $this->collectFonts($files);
        $cleaned = $this->cleaner->clean($files[$main]);
        $css = $this->collectCss($files, $files[$main], $cleaned['css']);
        $dependencies = array_keys(array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? [])));
        if (\in_array('mustache', $dependencies, true) && !\in_array('handlebars', $dependencies, true)) {
            // Mustache themes: names resolve up the scope chain, root is the resume
            $converted = ['twig' => $this->mustache->toTwig($this->handlebars->inlinePartials($cleaned['body'], $partials), 'resume'), 'warnings' => []];
        } else {
            $converted = $this->handlebars->toTwig($cleaned['body'], $partials);
        }

        return $this->finish($converted['twig'], $css, $fonts, $manifest, ImportResult::FORMAT_JSON_RESUME, [...$warnings, ...$cleaned['warnings'], ...$converted['warnings']], $files, $cleaned['body']);
    }

    /**
     * @param array<string, string>                 $files
     * @param \Closure(string, string): string|null $complete
     * @param array<string, mixed>                  $manifest
     */
    private function fromHtml(array $files, ?\Closure $complete, array $manifest): ImportResult
    {
        $candidates = array_values(array_filter(array_keys($files), static fn (string $f) => (bool) preg_match('/\.(html?|twig|mustache)$/i', $f)));
        if ($candidates === []) {
            throw new ConversionException(['No template found: add an index.html (or .twig / .mustache / .hbs) file, or a manifest.json for a .cvolve package.']);
        }
        $main = \in_array('index.html', $candidates, true) ? 'index.html' : (\in_array('template.html', $candidates, true) ? 'template.html' : $candidates[0]);
        [$fonts, $files] = $this->collectFonts($files);
        $cleaned = $this->cleaner->clean($files[$main]);
        $css = $this->collectCss($files, $files[$main], $cleaned['css']);
        $manifest['name'] = ($manifest['name'] ?? '') !== '' ? $manifest['name'] : ($cleaned['title'] ?? $manifest['_fallbackName'] ?? 'Imported template');
        unset($manifest['_fallbackName']);
        $body = $cleaned['body'];
        $ext = strtolower(pathinfo($main, \PATHINFO_EXTENSION));

        if ($ext === 'twig' || str_contains($body, '{%')) {
            return $this->finish($body, $css, $fonts, $manifest, ImportResult::FORMAT_TWIG_HTML, $cleaned['warnings'], $files);
        }
        if ($ext === 'mustache' || str_contains($body, '{{')) {
            return $this->finish($this->mustache->toTwig($body), $css, $fonts, $manifest, ImportResult::FORMAT_MUSTACHE, $cleaned['warnings'], $files, $body);
        }

        if ($complete === null) {
            throw new ConversionException(['This HTML has no placeholders, so turning it into a template needs AI: choose an AI connection, or add Mustache placeholders such as {{header.name}}.'], $cleaned['warnings'], ConversionException::NEEDS_AI);
        }
        $manifest += ['format' => TemplatePackageReader::FORMAT, 'formatVersion' => TemplatePackageReader::FORMAT_VERSION, 'version' => '1.0.0'];
        $manifest['fonts'] = $this->inference->fonts($css);
        $manifest['slug'] ??= TemplateAi::slug((string) $manifest['name']);
        try {
            $package = $this->ai->convertHtml($complete, $body, $css, $manifest, $fonts);
        } catch (InvalidTemplatePackageException $e) {
            throw new ConversionException(['The AI conversion did not produce a valid template:', ...$e->errors], $cleaned['warnings']);
        }

        return new ImportResult($package, ImportResult::FORMAT_RAW_HTML, $cleaned['warnings']);
    }

    /**
     * @param array<string, string> $fonts
     * @param array<string, mixed>  $manifest
     * @param list<string>          $warnings
     * @param array<string, string> $files
     */
    private function finish(string $twig, string $css, array $fonts, array $manifest, string $format, array $warnings, array $files, ?string $source = null): ImportResult
    {
        $schema = isset($files['schema.json']) ? json_decode($files['schema.json'], true) : null;
        $schema = \is_array($schema) ? $schema : $this->inference->infer($twig);
        $name = (string) (($manifest['name'] ?? '') ?: ($manifest['_fallbackName'] ?? 'Imported template'));
        unset($manifest['_fallbackName']);
        $manifest = array_merge([
            'format' => TemplatePackageReader::FORMAT,
            'formatVersion' => TemplatePackageReader::FORMAT_VERSION,
            'version' => '1.0.0',
            'page' => 'A4',
            'maxPages' => 2,
            'licence' => 'MIT',
        ], array_filter($manifest, static fn ($v) => $v !== null && $v !== ''), [
            'name' => mb_substr($name, 0, 120),
            'fonts' => $this->inference->fonts($css),
        ]);
        $manifest['slug'] = isset($manifest['slug']) && preg_match('/^[a-z0-9][a-z0-9-]{1,60}$/', (string) $manifest['slug']) ? $manifest['slug'] : TemplateAi::slug($name);
        $manifest['description'] ??= 'Imported template.';

        try {
            $package = $this->reader->fromFiles([
                'manifest.json' => (string) json_encode($manifest),
                'schema.json' => (string) json_encode($schema),
                'template.html.twig' => $twig,
                'style.css' => $css,
            ] + $fonts);
        } catch (InvalidTemplatePackageException $e) {
            throw new ConversionException($e->errors, $warnings);
        }

        return new ImportResult($package, $format, array_values(array_unique($warnings)), $source);
    }

    /**
     * Stylesheets: <style> blocks, linked local .css files, then any other .css file.
     *
     * @param array<string, string> $files
     */
    private function collectCss(array $files, string $html, string $inline): string
    {
        $css = [];
        preg_match_all('#<link\b[^>]*href\s*=\s*["\']([^"\']+\.css)["\']#i', $html, $links);
        foreach ($links[1] as $href) {
            $path = ltrim((string) preg_replace('#^\./#', '', $href), '/');
            if (isset($files[$path])) {
                $css[$path] = $files[$path];
            }
        }
        foreach ($files as $name => $contents) {
            if (preg_match('/\.css$/i', $name) && !isset($css[$name]) && !str_contains($name, 'node_modules/')) {
                $css[$name] = $contents;
            }
        }
        [$clean] = $this->cleaner->cleanCss(implode("\n", $css));

        return trim($clean."\n".$inline)."\n";
    }

    /**
     * Move font files to fonts/ and point the CSS at them.
     *
     * @param array<string, string> $files
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function collectFonts(array $files): array
    {
        $fonts = [];
        foreach ($files as $name => $contents) {
            if (preg_match(self::FONT, $name) && !str_contains($name, 'node_modules/')) {
                $fonts['fonts/'.preg_replace('/[^A-Za-z0-9._-]/', '-', basename($name))] = $contents;
            }
        }
        if ($fonts !== []) {
            foreach ($files as $name => $contents) {
                if (preg_match('/\.(css|html?|hbs|handlebars)$/i', $name)) {
                    $files[$name] = (string) preg_replace_callback('#url\(\s*(["\']?)([^"\')]+\.(woff2?|ttf|otf))\1\s*\)#i', static fn (array $m) => 'url("fonts/'.preg_replace('/[^A-Za-z0-9._-]/', '-', basename($m[2])).'")', $contents);
                }
            }
        }

        return [$fonts, $files];
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, mixed>
     */
    private function packageJson(array $files): array
    {
        $data = isset($files['package.json']) ? json_decode($files['package.json'], true) : null;

        return \is_array($data) ? $data : [];
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ConversionException(['The file is not a valid zip archive.']);
        }
        $files = [];
        $total = 0;
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ConversionException([sprintf('The archive has more than %d files.', self::MAX_ENTRIES)]);
            }
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $stat = $zip->statIndex($i);
                if ($stat === false || str_ends_with($stat['name'], '/')) {
                    continue;
                }
                $name = $stat['name'];
                if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name)) {
                    throw new ConversionException([sprintf('Unsafe path in archive: "%s".', $name)]);
                }
                if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store' || preg_match('#(^|/)(node_modules|\.git)/#', $name)) {
                    continue;
                }
                $total += $stat['size'];
                if ($total > self::MAX_TOTAL) {
                    throw new ConversionException(['The unpacked archive is too large.']);
                }
                $files[$name] = (string) $zip->getFromIndex($i);
            }
        } finally {
            $zip->close();
        }

        return $files;
    }

    /**
     * @param array<string, string> $files
     *
     * @return array<string, string>
     */
    private function stripCommonFolder(array $files): array
    {
        $names = array_keys($files);
        if ($names === [] || array_filter($names, static fn (string $n) => !str_contains($n, '/')) !== []) {
            return $files;
        }
        $first = explode('/', $names[0])[0].'/';
        foreach ($names as $name) {
            if (!str_starts_with($name, $first)) {
                return $files;
            }
        }
        $out = [];
        foreach ($files as $name => $contents) {
            $out[substr($name, \strlen($first))] = $contents;
        }

        return $out;
    }

    private static function nameFromFile(string $filename): string
    {
        $base = (string) preg_replace('/\.(cvolve|zip|html?|twig|mustache|hbs|handlebars)$/i', '', basename($filename));
        $base = (string) preg_replace('/^jsonresume-theme-/', '', $base);

        return ucwords(trim(str_replace(['-', '_'], ' ', $base))) ?: 'Imported template';
    }
}
