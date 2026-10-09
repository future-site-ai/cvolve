<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Package;

final class InvalidTemplatePackageException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid template package: '.implode(' ', $errors));
    }
}
