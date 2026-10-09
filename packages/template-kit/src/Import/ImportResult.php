<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

use Cvolve\TemplateKit\Package\TemplatePackage;

final class ImportResult
{
    public const FORMAT_CVOLVE = 'cvolve';
    public const FORMAT_MUSTACHE = 'mustache-html';
    public const FORMAT_TWIG_HTML = 'twig-html';
    public const FORMAT_JSON_RESUME = 'json-resume';
    public const FORMAT_RAW_HTML = 'raw-html';

    /**
     * @param list<string> $warnings
     * @param string|null  $source   the editable source markup when it differs from the Twig (e.g. Mustache)
     */
    public function __construct(
        public readonly TemplatePackage $package,
        public readonly string $format,
        public readonly array $warnings = [],
        public readonly ?string $source = null,
    ) {
    }
}
