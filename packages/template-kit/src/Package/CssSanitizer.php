<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Package;

/**
 * Validates template CSS: no imports, no remote resources, no legacy script hooks.
 * url() may only point at a bundled font (fonts/...) or an inline raster image.
 */
final class CssSanitizer
{
    public const MAX_SIZE = 200_000;

    /**
     * @return list<string> problems found (empty = safe)
     */
    public function check(string $css): array
    {
        $errors = [];
        if (\strlen($css) > self::MAX_SIZE) {
            $errors[] = sprintf('style.css is larger than %d KB.', self::MAX_SIZE / 1000);
        }
        // Decode escapes (e.g. "\\75 rl(") so they cannot hide a forbidden keyword
        $clean = $this->decodeEscapes($this->stripComments($css));

        foreach ([
            '/@import/i' => '@import is not allowed; bundle fonts in fonts/ instead.',
            '/@namespace/i' => '@namespace is not allowed.',
            '/expression\s*\(/i' => 'CSS expression() is not allowed.',
            '/javascript\s*:/i' => 'javascript: URLs are not allowed.',
            '/behaviou?r\s*:/i' => 'behavior: is not allowed.',
            '/-moz-binding/i' => '-moz-binding is not allowed.',
            '#</\s*style#i' => 'style.css must not contain </style>.',
        ] as $pattern => $message) {
            if (preg_match($pattern, $clean)) {
                $errors[] = $message;
            }
        }

        if (preg_match_all('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is', $clean, $matches)) {
            foreach ($matches[2] as $target) {
                if (!$this->isAllowedUrl(trim($target))) {
                    $errors[] = sprintf('url(%s) is not allowed: only fonts/<file> and data:image URLs may be used.', mb_substr($target, 0, 60));
                }
            }
        }

        return array_values(array_unique($errors));
    }

    public function stripComments(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }

    private function decodeEscapes(string $css): string
    {
        return (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static fn (array $m) => mb_chr((int) hexdec($m[1])) ?: '', $css);
    }

    private function isAllowedUrl(string $url): bool
    {
        return (bool) preg_match('#^fonts/[A-Za-z0-9._-]+\.(woff2?|ttf|otf)$#', $url)
            || (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#', $url);
    }
}
