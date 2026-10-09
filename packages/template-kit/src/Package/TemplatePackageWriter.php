<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Package;

/**
 * Writes a package as a .cvolve zip.
 */
final class TemplatePackageWriter
{
    public function toZip(TemplatePackage $package, ?string $path = null): string
    {
        $path ??= (string) tempnam(sys_get_temp_dir(), 'cvolve');
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the .cvolve archive.');
        }
        foreach ($this->toFiles($package) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $path;
    }

    /**
     * @return array<string, string>
     */
    public function toFiles(TemplatePackage $package): array
    {
        $manifest = $package->manifest;
        $manifest['format'] = TemplatePackageReader::FORMAT;
        $manifest['formatVersion'] = TemplatePackageReader::FORMAT_VERSION;
        $json = static fn (array $data): string => json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n";

        return [
            'manifest.json' => $json($manifest),
            'schema.json' => $json($package->schema),
            'template.html.twig' => $package->twig,
            'style.css' => $package->css,
            ...$package->assets,
        ];
    }
}
