<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Local file storage under var/storage (a Docker volume in production):
 *   templates/<storageKey>/   thumbnails and fonts of installed templates
 *   photos/                   profile photos
 *   exports/                  generated PDF and DOCX files
 */
final class Storage
{
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire('%app.storage_dir%')]
        private readonly string $root,
    ) {
        $this->fs = new Filesystem();
    }

    public function path(string ...$parts): string
    {
        foreach ($parts as $part) {
            if (str_contains($part, '..')) {
                throw new \InvalidArgumentException('Invalid storage path.');
            }
        }

        return rtrim($this->root, '/').'/'.implode('/', array_map(static fn (string $p) => trim($p, '/'), $parts));
    }

    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);
        $this->fs->dumpFile($path, $contents);

        return $path;
    }

    public function read(string $relative): ?string
    {
        $path = $this->path($relative);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    public function exists(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function remove(string $relative): void
    {
        $this->fs->remove($this->path($relative));
    }

    public function dataUri(string $relative): ?string
    {
        $contents = $this->read($relative);
        if ($contents === null) {
            return null;
        }
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';
        // finfo does not always recognise font files
        $mime = match (strtolower(pathinfo($relative, \PATHINFO_EXTENSION))) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => $mime,
        };

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
