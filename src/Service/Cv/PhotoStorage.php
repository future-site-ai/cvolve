<?php

declare(strict_types=1);

namespace App\Service\Cv;

use App\Entity\User;
use App\Service\Storage;

/**
 * Profile photos, re-encoded as square-cropped JPEGs so uploads cannot carry
 * anything but pixels.
 */
final class PhotoStorage
{
    private const SIZE = 480;

    public function __construct(private readonly Storage $storage)
    {
    }

    /**
     * Store an image file for the user and return the stored file name.
     */
    public function store(User $user, string $sourcePath): string
    {
        $contents = (string) file_get_contents($sourcePath);
        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new \InvalidArgumentException('The photo must be a JPEG, PNG or WebP image.');
        }

        // Center-crop to a square and scale down
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);
        $target = min(self::SIZE, $side);
        $square = imagecreatetruecolor($target, $target);
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $image, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $target, $target, $side, $side);

        ob_start();
        imagejpeg($square, null, 88);
        $jpeg = (string) ob_get_clean();

        $name = sprintf('%d-%s.jpg', $user->getId(), bin2hex(random_bytes(6)));
        $this->storage->write('photos/'.$name, $jpeg);

        return $name;
    }

    public function remove(?string $name): void
    {
        if ($name !== null && preg_match('/^[\w-]+\.jpg$/', $name)) {
            $this->storage->remove('photos/'.$name);
        }
    }

    public function dataUri(string $name): ?string
    {
        return preg_match('/^[\w-]+\.jpg$/', $name) ? $this->storage->dataUri('photos/'.$name) : null;
    }

    public function path(string $name): ?string
    {
        return preg_match('/^[\w-]+\.jpg$/', $name) && $this->storage->exists('photos/'.$name) ? $this->storage->path('photos', $name) : null;
    }
}
