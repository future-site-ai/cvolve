<?php

declare(strict_types=1);

namespace App\Service\Import;

use Smalot\PdfParser\Parser;

/**
 * Pulls plain text out of uploaded Markdown, text, JSON, PDF and DOCX files.
 */
final class TextExtractor
{
    public const MAX_CHARS = 120_000;

    public const KINDS = ['md' => 'markdown', 'markdown' => 'markdown', 'txt' => 'markdown', 'json' => 'json', 'pdf' => 'pdf', 'docx' => 'docx'];

    public static function kindFor(string $filename): ?string
    {
        return self::KINDS[strtolower(pathinfo($filename, \PATHINFO_EXTENSION))] ?? null;
    }

    public function extract(string $path, string $kind): string
    {
        $text = match ($kind) {
            'pdf' => (new Parser())->parseFile($path)->getText(),
            'docx' => $this->docx($path),
            default => (string) file_get_contents($path),
        };
        $text = trim((string) preg_replace("/[ \t]+\n/", "\n", str_replace("\r\n", "\n", $text)));
        if ($text === '') {
            throw new \InvalidArgumentException('No text could be read from this file. Scanned (image-only) PDFs are not supported.');
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    private function docx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('This is not a valid DOCX file.');
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = (string) preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br/>#'], ["\n", "\t", "\n"], $xml);

        return html_entity_decode(strip_tags($xml), \ENT_QUOTES | \ENT_XML1, 'UTF-8');
    }
}
