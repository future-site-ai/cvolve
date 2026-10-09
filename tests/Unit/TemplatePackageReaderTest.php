<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Cvolve\TemplateKit\Package\CssSanitizer;
use Cvolve\TemplateKit\Package\InvalidTemplatePackageException;
use Cvolve\TemplateKit\Package\TemplatePackageReader;
use Cvolve\TemplateKit\Sandbox\TemplateSandbox;
use PHPUnit\Framework\TestCase;

final class TemplatePackageReaderTest extends TestCase
{
    private TemplatePackageReader $reader;

    protected function setUp(): void
    {
        $this->reader = new TemplatePackageReader(new TemplateSandbox(), new CssSanitizer());
    }

    /**
     * @return array<string, string>
     */
    private function validFiles(): array
    {
        return [
            'manifest.json' => json_encode(['format' => 'cvolve-template', 'formatVersion' => 1, 'name' => 'Test', 'slug' => 'test', 'version' => '1.0.0']),
            'schema.json' => json_encode(['regions' => ['main'], 'sections' => ['summary' => ['maxLength' => 500], 'experience' => []]]),
            'template.html.twig' => '<h1>{{ header.name }}</h1>{% for s in regions.main %}<h2>{{ s.title }}</h2>{{ s.text|md }}{% endfor %}',
            'style.css' => 'h1 { color: #333; } li::before { content: "\2022"; }',
        ];
    }

    public function testBundledPresetsAreValid(): void
    {
        foreach (\Cvolve\TemplateKit\Presets::all() as $dir) {
            $package = $this->reader->readDirectory($dir);
            self::assertNotEmpty($package->slug());
        }
    }

    public function testValidPackage(): void
    {
        $package = $this->reader->fromFiles($this->validFiles());
        self::assertSame('test', $package->slug());
    }

    /**
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function invalidPackages(): iterable
    {
        yield 'script tag' => [['template.html.twig' => '<script>alert(1)</script>{{ header.name }}'], 'Scripts are not allowed'];
        yield 'event handler' => [['template.html.twig' => '<div onclick="x()">{{ header.name }}</div>'], 'event handlers'];
        yield 'remote stylesheet link' => [['template.html.twig' => '<link rel="stylesheet" href="https://x">{{ header.name }}'], '<link>'];
        yield 'css import' => [['style.css' => '@import url("https://fonts.googleapis.com/css");'], '@import'];
        yield 'remote css url' => [['style.css' => 'body { background: url(https://evil.test/track.png); }'], 'url(https://evil.test'];
        yield 'escaped css url' => [['style.css' => 'body { background: \75 rl(https://evil.test/x.png); }'], 'url(https://evil.test'];
        yield 'javascript file' => [['app.js' => 'alert(1)'], 'File not allowed'];
        yield 'missing schema' => [['schema.json' => null], 'Missing required file "schema.json"'];
        yield 'bad slug' => [['manifest.json' => json_encode(['format' => 'cvolve-template', 'formatVersion' => 1, 'name' => 'T', 'slug' => 'Bad Slug!', 'version' => '1.0'])], '"slug"'];
        yield 'unknown section type' => [['schema.json' => json_encode(['sections' => ['hobbies' => []]])], 'unknown section type "hobbies"'];
        yield 'twig syntax error' => [['template.html.twig' => '{% if %}'], 'template.html.twig:'];
        yield 'sandbox violation' => [['template.html.twig' => '{{ include("x") }}'], 'template.html.twig:'];
    }

    /**
     * @param array<string, string|null> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPackages')]
    public function testInvalidPackagesAreRejected(array $override, string $expected): void
    {
        $files = array_filter(array_merge($this->validFiles(), $override), static fn ($v) => $v !== null);
        try {
            $this->reader->fromFiles($files);
            self::fail('The package should have been rejected.');
        } catch (InvalidTemplatePackageException $e) {
            self::assertStringContainsString($expected, implode("\n", $e->errors));
        }
    }

    public function testZipSlipIsRejected(): void
    {
        $path = $this->zip(['../evil.php' => '<?php echo 1;'] + $this->validFiles());
        $this->expectException(InvalidTemplatePackageException::class);
        $this->expectExceptionMessage('Unsafe path');
        $this->reader->readZip($path);
    }

    public function testZipWithTopLevelFolderIsAccepted(): void
    {
        $files = [];
        foreach ($this->validFiles() as $name => $contents) {
            $files['my-template/'.$name] = $contents;
        }
        self::assertSame('test', $this->reader->readZip($this->zip($files))->slug());
    }

    public function testNotAZip(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($path, 'not a zip');
        $this->expectException(InvalidTemplatePackageException::class);
        $this->reader->readZip($path);
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cvzip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }
}
