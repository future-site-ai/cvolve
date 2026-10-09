<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

final class ConversionException extends \RuntimeException
{
    /** Static HTML without placeholders was imported without an AI completion. */
    public const NEEDS_AI = 1;

    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors, public readonly array $warnings = [], int $code = 0)
    {
        parent::__construct(implode(' ', $errors), $code);
    }
}
