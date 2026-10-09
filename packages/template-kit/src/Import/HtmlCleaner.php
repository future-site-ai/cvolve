<?php

declare(strict_types=1);

namespace Cvolve\TemplateKit\Import;

/**
 * Prepares designer HTML for the sandbox: keeps the <body> content, moves <style> blocks
 * into the stylesheet, and removes scripts, remote resources and event handlers.
 * Works on text (not a DOM) so template tags inside attributes survive.
 */
final class HtmlCleaner
{
    /**
     * @return array{body: string, css: string, title: ?string, warnings: list<string>}
     */
    public function clean(string $html): array
    {
        $warnings = [];
        $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : null;

        $css = '';
        $html = (string) preg_replace_callback('#<style\b[^>]*>(.*?)</style>#is', static function (array $m) use (&$css) {
            $css .= trim($m[1])."\n";

            return '';
        }, $html);

        if (preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $m)) {
            $html = $m[1];
        } else {
            $html = (string) preg_replace(['#<!DOCTYPE[^>]*>#i', '#<head\b[^>]*>.*?</head>#is', '#</?html\b[^>]*>#i', '#</?body\b[^>]*>#i'], '', $html);
        }

        $count = 0;
        $html = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html, -1, $count);
        if ($count > 0) {
            $warnings[] = sprintf('Removed %d <script> block(s): templates cannot run JavaScript.', $count);
        }
        $html = (string) preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html);
        $html = (string) preg_replace('#<(link|meta|base)\b[^>]*>#i', '', $html, -1, $count);
        if ($count > 0) {
            $warnings[] = 'Removed <link>/<meta> tags: stylesheets must be part of the package and remote fonts are not loaded. Bundle fonts in fonts/.';
        }
        $html = (string) preg_replace('#<(iframe|object|embed|form|input|button|template|portal)\b[^>]*>(.*?</\1>)?#is', '', $html, -1, $count);
        if ($count > 0) {
            $warnings[] = 'Removed interactive or embedded elements (iframe, form, input, button…).';
        }
        $html = (string) preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html, -1, $count);
        if ($count > 0) {
            $warnings[] = sprintf('Removed %d inline event handler(s).', $count);
        }
        $html = (string) preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);

        // Images must come from a template variable (the profile photo) or be inline data
        $html = (string) preg_replace_callback('#<img\b[^>]*>#i', static function (array $m) use (&$warnings) {
            if (preg_match('#\ssrc\s*=\s*["\']\s*(\{\{|data:image/)#i', $m[0])) {
                return $m[0];
            }
            $warnings[] = 'Removed an <img> with a file or remote source; use {{ header.photo }} for the portrait or inline data: images.';

            return '';
        }, $html);
        // Inline style attributes may not use url()
        $html = (string) preg_replace('#url\s*\([^)]*\)#i', 'none', $html, -1, $count);
        if ($count > 0) {
            $warnings[] = 'Removed url() from inline styles; put backgrounds in the stylesheet.';
        }

        [$css, $cssWarnings] = $this->cleanCss($css);

        return ['body' => trim($html), 'css' => $css, 'title' => $title ?: null, 'warnings' => array_values(array_unique([...$warnings, ...$cssWarnings]))];
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    public function cleanCss(string $css): array
    {
        $warnings = [];
        $count = 0;
        $css = (string) preg_replace('#@import[^;]*;#i', '', $css, -1, $count);
        if ($count > 0) {
            $warnings[] = 'Removed @import rules (remote stylesheets and fonts are not loaded).';
        }
        // @font-face rules pointing outside fonts/ cannot work in the sandbox
        $css = (string) preg_replace_callback('#@font-face\s*\{[^}]*\}#i', static function (array $m) use (&$warnings) {
            if (preg_match('#url\(\s*["\']?fonts/#i', $m[0])) {
                return $m[0];
            }
            $warnings[] = 'Removed an @font-face that loads a remote or missing font; add the font file to fonts/ to use it.';

            return '';
        }, $css);
        $css = (string) preg_replace_callback('#url\(\s*(["\']?)(.*?)\1\s*\)#is', static function (array $m) use (&$warnings) {
            $target = trim($m[2]);
            if (preg_match('#^fonts/[A-Za-z0-9._-]+\.(woff2?|ttf|otf)$#', $target) || preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $target)) {
                return $m[0];
            }
            $warnings[] = sprintf('Removed url(%s): only bundled fonts and inline images are allowed.', mb_strimwidth($target, 0, 50, '…'));

            return 'none';
        }, $css);
        $css = (string) preg_replace('#expression\s*\(|behaviou?r\s*:|-moz-binding#i', '', $css);

        return [trim($css)."\n", array_values(array_unique($warnings))];
    }
}
