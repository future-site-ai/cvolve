<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit;

/**
 * The preset templates bundled with the kit (unpacked .cvolve packages).
 */
final class Presets
{
    public static function directory(): string
    {
        return \dirname(__DIR__).'/resources/presets';
    }

    /**
     * @return array<string, string> slug => directory
     */
    public static function all(): array
    {
        $presets = [];
        foreach (glob(self::directory().'/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $presets[basename($dir)] = $dir;
        }

        return $presets;
    }
}
