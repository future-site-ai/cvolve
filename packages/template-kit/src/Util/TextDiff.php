<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Util;

/**
 * Minimal line diff (longest common subsequence) for proposal previews and version comparison.
 */
final class TextDiff
{
    private const MAX_CELLS = 400_000;

    /**
     * @param list<string> $before
     * @param list<string> $after
     *
     * @return list<array{type: 'same'|'add'|'del', line: string}>
     */
    public static function lines(array $before, array $after): array
    {
        $n = \count($before);
        $m = \count($after);
        if ($n * $m > self::MAX_CELLS) {
            return [
                ...array_map(static fn (string $l) => ['type' => 'del', 'line' => $l], $before),
                ...array_map(static fn (string $l) => ['type' => 'add', 'line' => $l], $after),
            ];
        }

        // lcs[i][j] = LCS length of before[i..] and after[j..]
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                $lcs[$i][$j] = $before[$i] === $after[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($before[$i] === $after[$j]) {
                $out[] = ['type' => 'same', 'line' => $before[$i]];
                ++$i;
                ++$j;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['type' => 'del', 'line' => $before[$i++]];
            } else {
                $out[] = ['type' => 'add', 'line' => $after[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['type' => 'del', 'line' => $before[$i++]];
        }
        while ($j < $m) {
            $out[] = ['type' => 'add', 'line' => $after[$j++]];
        }

        return $out;
    }

    /**
     * @param list<array{type: string, line: string}> $diff
     */
    public static function hasChanges(array $diff): bool
    {
        foreach ($diff as $row) {
            if ($row['type'] !== 'same') {
                return true;
            }
        }

        return false;
    }
}
