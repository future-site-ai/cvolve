<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Render;

use Cvolve\TemplateKit\Sandbox\TemplateSandbox;

/**
 * Turns a template (Twig + CSS + manifest) and a render context into one self-contained
 * HTML document: fonts are inlined as data URIs, so the same document works for the live
 * preview, share pages and Chromium PDF rendering.
 */
final class DocumentRenderer
{
    public const MODE_SCREEN = 'screen';
    public const MODE_PRINT = 'print';

    public function __construct(private readonly TemplateSandbox $sandbox)
    {
    }

    /**
     * @param array<string, mixed>              $manifest
     * @param array<string, mixed>              $context
     * @param \Closure(string): (string|null)    $assetUri maps "fonts/x.woff2" to a data URI (null if missing)
     */
    public function render(string $twig, string $css, array $manifest, array $context, \Closure $assetUri, string $mode = self::MODE_SCREEN, string $title = 'CV'): string
    {
        $context = ContextEnricher::enrich($context);
        $body = $this->sandbox->render($twig, $context);
        $css = $this->fontFaces($manifest, $assetUri).$this->inlineAssets($css, $assetUri);
        $page = ($manifest['page'] ?? 'A4') === 'Letter' ? 'Letter' : 'A4';
        [$width, $height] = $page === 'Letter' ? ['215.9mm', '279.4mm'] : ['210mm', '297mm'];

        $screen = '';
        if ($mode === self::MODE_SCREEN) {
            // Show the body as a sheet of paper, with faint guides where pages end
            $screen = <<<CSS
                @media screen {
                  html { background: #d4d8de; padding: 24px 0; }
                  body { width: {$width}; min-height: {$height}; margin: 0 auto; box-shadow: 0 6px 30px rgba(15, 23, 42, .18); position: relative; }
                  body::after { content: ""; position: absolute; inset: 0; pointer-events: none;
                    background: repeating-linear-gradient(to bottom, transparent 0, transparent calc({$height} - 1px), rgba(220, 38, 38, .45) calc({$height} - 1px), rgba(220, 38, 38, .45) {$height}); }
                }
                CSS;
        }

        return sprintf(
            "<!DOCTYPE html>\n<html lang=\"%s\" dir=\"%s\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n<title>%s</title>\n<style>@page { size: %s; }\n%s\n%s</style>\n</head>\n<body class=\"cvolve cvolve-%s\">\n%s\n</body>\n</html>\n",
            htmlspecialchars((string) ($context['cv']['locale'] ?? 'en')),
            htmlspecialchars((string) ($context['cv']['dir'] ?? 'ltr')),
            htmlspecialchars($title),
            $page,
            $css,
            $screen,
            $mode,
            $body,
        );
    }

    /**
     * @param array<string, mixed>           $manifest
     * @param \Closure(string): (string|null) $assetUri
     */
    private function fontFaces(array $manifest, \Closure $assetUri): string
    {
        $css = '';
        foreach (\is_array($manifest['fonts'] ?? null) ? $manifest['fonts'] : [] as $font) {
            if (!\is_array($font) || !\is_string($font['file'] ?? null) || !\is_string($font['family'] ?? null)) {
                continue;
            }
            $uri = $assetUri($font['file']);
            if ($uri === null) {
                continue;
            }
            $css .= sprintf(
                "@font-face { font-family: \"%s\"; src: url(\"%s\"); font-weight: %s; font-style: %s; font-display: block; }\n",
                str_replace('"', '', $font['family']),
                $uri,
                preg_match('/^\d{3}( \d{3})?$/', (string) ($font['weight'] ?? '')) ? $font['weight'] : '400',
                ($font['style'] ?? 'normal') === 'italic' ? 'italic' : 'normal',
            );
        }

        return $css;
    }

    /**
     * @param \Closure(string): (string|null) $assetUri
     */
    private function inlineAssets(string $css, \Closure $assetUri): string
    {
        return (string) preg_replace_callback('#url\(\s*([\'"]?)(fonts/[A-Za-z0-9._-]+)\1\s*\)#', static function (array $m) use ($assetUri) {
            $uri = $assetUri($m[2]);

            return $uri !== null ? 'url("'.$uri.'")' : 'none';
        }, $css);
    }

    /**
     * Data URI for a binary asset (fonts and images bundled in packages).
     */
    public static function dataUri(string $path, string $contents): string
    {
        $mime = match (strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
