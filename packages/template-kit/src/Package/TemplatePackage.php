<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Package;

/**
 * The validated contents of a .cvolve template package.
 */
final class TemplatePackage
{
    /**
     * @param array<string, mixed>  $manifest
     * @param array<string, mixed>  $schema
     * @param array<string, string> $assets relative path (thumbnail.png, fonts/x.woff2) => binary contents
     */
    public function __construct(
        public readonly array $manifest,
        public readonly array $schema,
        public readonly string $twig,
        public readonly string $css,
        public readonly array $assets = [],
    ) {
    }

    public function slug(): string
    {
        return (string) $this->manifest['slug'];
    }

    public function name(): string
    {
        return (string) $this->manifest['name'];
    }
}
