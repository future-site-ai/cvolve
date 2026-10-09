<?php

declare(strict_types=1);

namespace App\Service\Template;

use Cvolve\TemplateKit\Package\TemplatePackage;
use Cvolve\TemplateKit\Package\TemplatePackageReader;
use App\Entity\Template;
use App\Entity\User;
use App\Repository\TemplateRepository;
use App\Service\Ai\AiTextService;
use App\Service\Storage;
use Cvolve\TemplateKit\Import\ConversionException;
use Cvolve\TemplateKit\Import\TemplateImporter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Installs validated packages as Template entities, and exports them back to .cvolve files.
 */
final class TemplateInstaller
{
    public function __construct(
        private readonly TemplatePackageReader $reader,
        private readonly TemplateRepository $templates,
        private readonly EntityManagerInterface $em,
        private readonly Storage $storage,
        private readonly TemplateImporter $importer,
        private readonly AiTextService $ai,
    ) {
    }

    /**
     * Install a package as a global preset (owner null) or a private template.
     * Installing a slug that the same owner already has updates it in place.
     */
    public function install(TemplatePackage $package, ?User $owner = null): Template
    {
        $template = $this->templates->findOneBy(['slug' => $package->slug(), 'owner' => $owner]);
        if ($template === null) {
            $template = new Template($package->slug(), $package->name());
            $template->setOwner($owner);
            $this->em->persist($template);
        }

        $manifest = $package->manifest;
        $template
            ->setName($package->name())
            ->setVersion((string) $manifest['version'])
            ->setAuthor(isset($manifest['author']) ? (string) $manifest['author'] : null)
            ->setLicence(isset($manifest['licence']) ? (string) $manifest['licence'] : null)
            ->setDescription(isset($manifest['description']) ? (string) $manifest['description'] : null)
            ->setManifest($manifest)
            ->setSchema($package->schema)
            ->setTwigSource($package->twig)
            ->setCss($package->css)
            ->touch();

        $this->storage->remove('templates/'.$template->getStorageKey());
        foreach ($package->assets as $name => $contents) {
            $this->storage->write('templates/'.$template->getStorageKey().'/'.$name, $contents);
        }

        $this->em->flush();

        return $template;
    }

    public function installZip(string $path, ?User $owner = null): Template
    {
        return $this->install($this->reader->readZip($path), $owner);
    }

    /**
     * Import any supported format (.cvolve, CVolve HTML, Twig HTML, JSON Resume theme, HTML zip,
     * or static HTML converted by the configured AI provider).
     *
     * @return array{template: Template, warnings: list<string>, format: string}
     *
     * @throws ConversionException
     */
    public function importFile(string $path, string $originalName, ?User $owner = null): array
    {
        $complete = $this->ai->isAvailable() ? fn (string $system, string $prompt): string => $this->ai->complete($system, $prompt) : null;
        try {
            $result = $this->importer->importFile($path, $originalName, $complete, $owner !== null ? ['author' => $owner->getFullName()] : []);
        } catch (ConversionException $e) {
            if ($e->getCode() !== ConversionException::NEEDS_AI) {
                throw $e;
            }
            throw new ConversionException(['This HTML is a finished CV without placeholders. Converting it needs AI: configure AI_PROVIDER, convert it in the CVolve Template Maker, or add Mustache placeholders such as {{header.name}}.'], $e->warnings, $e->getCode());
        }
        $template = $this->install($result->package, $owner);
        $template->setSourceFormat($result->format);
        $this->em->flush();

        return ['template' => $template, 'warnings' => $result->warnings, 'format' => $result->format];
    }

    public function installDirectory(string $dir, ?User $owner = null): Template
    {
        return $this->install($this->reader->readDirectory($dir), $owner);
    }

    public function thumbnailPath(Template $template): ?string
    {
        foreach (['thumbnail.png', 'thumbnail.jpg', 'thumbnail.jpeg'] as $name) {
            $relative = 'templates/'.$template->getStorageKey().'/'.$name;
            if ($this->storage->exists($relative)) {
                return $this->storage->path($relative);
            }
        }

        return null;
    }

    /**
     * Pack a template into a .cvolve zip and return its temporary path.
     */
    public function export(Template $template): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cvolve');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $manifest = $template->getManifest();
        $manifest['format'] = TemplatePackageReader::FORMAT;
        $manifest['formatVersion'] = TemplatePackageReader::FORMAT_VERSION;
        $zip->addFromString('manifest.json', json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
        $zip->addFromString('schema.json', json_encode($template->getSchema(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
        $zip->addFromString('template.html.twig', $template->getTwigSource());
        $zip->addFromString('style.css', $template->getCss());

        $assetDir = $this->storage->path('templates', $template->getStorageKey());
        if (is_dir($assetDir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($assetDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), ltrim(substr($file->getPathname(), \strlen($assetDir)), '/'));
                }
            }
        }
        $zip->close();

        return $path;
    }
}
