<?php

declare(strict_types=1);

namespace App\Tests\TemplateKit;

use Cvolve\TemplateKit\Import\ConversionException;
use Cvolve\TemplateKit\Import\ImportResult;
use Cvolve\TemplateKit\Import\TemplateImporter;
use Cvolve\TemplateKit\Package\TemplatePackageWriter;
use Cvolve\TemplateKit\Presets;
use Cvolve\TemplateKit\Render\DocumentRenderer;
use Cvolve\TemplateKit\Render\SampleCv;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TemplateImporterTest extends KernelTestCase
{
    private TemplateImporter $importer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->importer = static::getContainer()->get(TemplateImporter::class);
    }

    private function renderSample(ImportResult $result): string
    {
        $p = $result->package;
        $assets = static fn (string $path): ?string => isset($p->assets[$path]) ? DocumentRenderer::dataUri($path, $p->assets[$path]) : null;

        return static::getContainer()->get(DocumentRenderer::class)->render($p->twig, $p->css, $p->manifest, SampleCv::context($p->schema), \Closure::fromCallable($assets));
    }

    private function file(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().'/'.uniqid('imp', true).'-'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'impzip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    public function testMustacheHtmlFile(): void
    {
        $html = <<<'HTML'
            <html><head><title>Clean Mustache</title><style>h1 { color: #123456; }</style></head>
            <body><h1>{{header.name}}</h1>{{#sections}}<h2>{{title}}</h2>{{#items}}<p>{{period}}</p>{{/items}}{{/sections}}</body></html>
            HTML;
        $result = $this->importer->importFile($this->file('clean.html', $html), 'clean.html');

        self::assertSame(ImportResult::FORMAT_MUSTACHE, $result->format);
        self::assertSame('Clean Mustache', $result->package->manifest['name']);
        self::assertSame('clean-mustache', $result->package->slug());
        self::assertStringContainsString('#123456', $result->package->css);
        self::assertCount(9, $result->package->schema['sections']);
        self::assertStringContainsString('<h1>Alex Morgan</h1>', $this->renderSample($result));
        self::assertNotNull($result->source);
    }

    public function testJsonResumeThemeZip(): void
    {
        $path = $this->zip([
            'jsonresume-theme-tidy/package.json' => '{"name": "jsonresume-theme-tidy", "description": "A tidy theme", "author": "Jane Theme"}',
            'jsonresume-theme-tidy/index.js' => 'module.exports = { render() {} }',
            'jsonresume-theme-tidy/resume.hbs' => '<html><head><style>{{{css}}}</style></head><body><h1>{{resume.basics.name}}</h1>{{#each resume.work}}<h2>{{position}}</h2>{{/each}}{{> skills}}</body></html>',
            'jsonresume-theme-tidy/partials/skills.hbs' => '{{#each resume.skills}}<b>{{name}}</b>{{/each}}',
            'jsonresume-theme-tidy/style.css' => 'h1 { font-family: "Tidy"; } @font-face { font-family: Tidy; src: url("./assets/tidy.woff2"); }',
            'jsonresume-theme-tidy/assets/tidy.woff2' => 'FONTDATA',
        ]);
        $result = $this->importer->importFile($path, 'jsonresume-theme-tidy.zip');

        self::assertSame(ImportResult::FORMAT_JSON_RESUME, $result->format);
        self::assertSame('Tidy', $result->package->manifest['name']);
        self::assertSame('Jane Theme', $result->package->manifest['author']);
        self::assertArrayHasKey('fonts/tidy.woff2', $result->package->assets);
        self::assertSame('fonts/tidy.woff2', $result->package->manifest['fonts'][0]['file']);
        self::assertContains('experience', array_keys($result->package->schema['sections']));
        self::assertStringContainsString('index.js', implode(' ', $result->warnings));

        $html = $this->renderSample($result);
        self::assertStringContainsString('<h1>Alex Morgan</h1>', $html);
        self::assertStringContainsString('<h2>Software Engineer</h2>', $html);
        self::assertStringContainsString('<b>DevOps</b>', $html);
        self::assertStringContainsString('data:font/woff2;base64', $html);
    }

    public function testZipWithIndexHtmlAndCss(): void
    {
        $path = $this->zip([
            'index.html' => '<html><head><link rel="stylesheet" href="css/main.css"></head><body><main>{{#regions.main}}<h2>{{title}}</h2>{{/regions.main}}</main></body></html>',
            'css/main.css' => 'main { padding: 10mm; }',
        ]);
        $result = $this->importer->importFile($path, 'my-design.zip');

        self::assertSame('My Design', $result->package->manifest['name']);
        self::assertStringContainsString('padding: 10mm', $result->package->css);
    }

    public function testTwigHtmlFile(): void
    {
        $result = $this->importer->importFile($this->file('t.twig', '<h1>{{ header.name|upper }}</h1>{% for s in regions.main %}{{ s.title }}{% endfor %}'), 'plain.twig');
        self::assertSame(ImportResult::FORMAT_TWIG_HTML, $result->format);
        self::assertStringContainsString('ALEX MORGAN', $this->renderSample($result));
    }

    public function testNativePackageAndPresetsRoundTrip(): void
    {
        $writer = new TemplatePackageWriter();
        foreach (Presets::all() as $slug => $dir) {
            $package = static::getContainer()->get(\Cvolve\TemplateKit\Package\TemplatePackageReader::class)->readDirectory($dir);
            $result = $this->importer->importFile($writer->toZip($package), $slug.'.cvolve');
            self::assertSame(ImportResult::FORMAT_CVOLVE, $result->format);
            self::assertSame($slug, $result->package->slug());
        }
    }

    public function testStaticHtmlNeedsAi(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('needs AI');
        $this->importer->importFile($this->file('cv.html', '<h1>Jane Doe</h1><p>Engineer</p>'), 'cv.html');
    }

    public function testStaticHtmlWithAiAndRepairRound(): void
    {
        $calls = 0;
        $ai = static function (string $system, string $prompt) use (&$calls): string {
            ++$calls;
            self::assertStringContainsString('Jane Doe', $prompt);
            // First answer breaks the sandbox (raw filter); the repair answer is valid
            $template = $calls === 1 ? '<h1>{{ header.name|raw }}</h1>' : '<h1>{{ header.name }}</h1>{% for s in regions.main %}<h2>{{ s.title }}</h2>{% endfor %}';

            return '```json'."\n".json_encode(['name' => 'Jane Style', 'template' => $template, 'css' => 'h1{color:#a00}', 'schema' => ['regions' => ['main'], 'sections' => ['summary' => [], 'experience' => []]], 'accent' => '#AA0000'])."\n```";
        };
        $result = $this->importer->importFile($this->file('cv.html', '<html><body><h1>Jane Doe</h1><p>Engineer</p></body></html>'), 'cv.html', \Closure::fromCallable($ai));

        self::assertSame(2, $calls);
        self::assertSame(ImportResult::FORMAT_RAW_HTML, $result->format);
        self::assertSame('Jane Style', $result->package->manifest['name']);
        self::assertSame('#AA0000', $result->package->manifest['docx']['accent']);
        self::assertStringContainsString('<h1>Alex Morgan</h1>', $this->renderSample($result));
    }

    public function testUnsafeArchiveRejected(): void
    {
        $this->expectException(ConversionException::class);
        $this->importer->importFile($this->zip(['../escape.html' => '<p>x</p>']), 'evil.zip');
    }
}
