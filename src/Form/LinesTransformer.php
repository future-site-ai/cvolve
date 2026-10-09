<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * list<string> <-> textarea with one entry per line.
 *
 * @implements DataTransformerInterface<list<string>, string>
 */
final class LinesTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        return \is_array($value) ? implode("\n", $value) : '';
    }

    /** @return list<string> */
    public function reverseTransform(mixed $value): array
    {
        $lines = preg_split('/\R/', (string) $value) ?: [];

        return array_values(array_filter(array_map(static fn (string $l) => trim((string) preg_replace('/^\s*[-*•]\s+/u', '', $l)), $lines), static fn (string $l) => $l !== ''));
    }
}
