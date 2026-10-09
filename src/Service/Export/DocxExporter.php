<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\CvVariant;
use App\Service\Cv\CvRenderer;
use Cvolve\TemplateKit\SectionTypes;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\SimpleType\TblWidth;

/**
 * Builds an editable A4 Word document from the same render context the HTML templates use.
 *
 * Word cannot reproduce arbitrary CSS layouts, so DOCX output is a clean single-column
 * document styled with the template's "docx" settings (accent colour and font) rather
 * than a pixel copy of the PDF. Sections from every region are included, main region first.
 */
final class DocxExporter
{
    public function __construct(private readonly CvRenderer $renderer)
    {
    }

    public function export(CvVariant $variant): string
    {
        $template = $variant->getCv()->getTemplate();
        $context = $this->renderer->context($variant);
        $docx = \is_array($template->getManifest()['docx'] ?? null) ? $template->getManifest()['docx'] : [];
        $accent = ltrim(\is_string($docx['accent'] ?? null) && preg_match('/^#?[0-9a-fA-F]{6}$/', $docx['accent']) ? $docx['accent'] : '1F4E79', '#');
        $font = \is_string($docx['font'] ?? null) && preg_match('/^[A-Za-z0-9 ]{1,40}$/', $docx['font']) ? $docx['font'] : 'Calibri';

        $word = new PhpWord();
        $word->setDefaultFontName($font);
        $word->setDefaultFontSize(10);
        $word->getDocInfo()->setTitle($variant->getCv()->getTitle())->setCreator((string) $context['header']['name']);
        $word->addTitleStyle(1, ['size' => 11, 'bold' => true, 'color' => $accent, 'allCaps' => true], ['spaceBefore' => 200, 'spaceAfter' => 60, 'borderBottomSize' => 6, 'borderBottomColor' => $accent]);
        $word->addNumberingStyle('cvBullets', ['type' => 'multilevel', 'levels' => [['format' => 'bullet', 'text' => '•', 'left' => 300, 'hanging' => 200, 'tabPos' => 300]]]);

        $section = $word->addSection([
            'paperSize' => 'A4',
            'marginTop' => Converter::cmToTwip(1.6), 'marginBottom' => Converter::cmToTwip(1.6),
            'marginLeft' => Converter::cmToTwip(1.8), 'marginRight' => Converter::cmToTwip(1.8),
        ]);

        $this->header($section, $context['header'], $context['labels'], $accent);

        $regions = $context['regions'];
        $order = array_keys($regions);
        usort($order, static fn (string $a, string $b) => ($b === 'main') <=> ($a === 'main'));
        foreach ($order as $region) {
            foreach ($regions[$region] as $entry) {
                $section->addTitle(htmlspecialchars($entry['title']), 1);
                $this->sectionBody($section, $entry, $context['labels']);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'cvolve-docx');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary file.');
        }
        IOFactory::createWriter($word, 'Word2007')->save($path);
        $contents = (string) file_get_contents($path);
        unlink($path);

        return $contents;
    }

    /**
     * @param array<string, mixed>  $header
     * @param array<string, string> $labels
     */
    private function header(AbstractContainer $section, array $header, array $labels, string $accent): void
    {
        $table = $section->addTable(['width' => 100 * 50, 'unit' => TblWidth::PERCENT, 'cellMargin' => 0]);
        $row = $table->addRow();
        $text = $row->addCell((int) round(Converter::cmToTwip(13.4)));
        $text->addText(htmlspecialchars((string) $header['name']), ['size' => 22, 'bold' => true, 'color' => $accent], ['spaceAfter' => 0]);
        if ($header['headline'] !== '') {
            $text->addText(htmlspecialchars((string) $header['headline']), ['size' => 12, 'color' => '555555'], ['spaceAfter' => 80]);
        }
        $contact = array_filter([
            $header['location'], $header['phone'], $header['email'],
            self::pretty($header['linkedin']), self::pretty($header['github']), self::pretty($header['website']),
        ]);
        $text->addText(htmlspecialchars(implode('  ·  ', $contact)), ['size' => 9, 'color' => '444444'], ['spaceAfter' => 0]);
        $personal = array_filter([
            $header['nationality'] !== '' ? $labels['nationality'].': '.$header['nationality'] : '',
            $header['workAuthorization'] !== '' ? $labels['work_authorization'].': '.$header['workAuthorization'] : '',
            $header['drivingLicence'] !== '' ? $labels['driving_licence'].': '.$header['drivingLicence'] : '',
            $header['extra'],
        ]);
        if ($personal !== []) {
            $text->addText(htmlspecialchars(implode('  ·  ', $personal)), ['size' => 8.5, 'color' => '666666'], ['spaceAfter' => 0]);
        }

        if (\is_string($header['photo'] ?? null) && str_starts_with($header['photo'], 'data:image/')) {
            $binary = base64_decode((string) substr($header['photo'], (int) strpos($header['photo'], ',') + 1), true);
            if ($binary !== false) {
                $row->addCell((int) round(Converter::cmToTwip(3)))->addImage($binary, ['width' => 72, 'height' => 72, 'alignment' => Jc::END]);
            }
        }
        $section->addTextBreak(1, ['size' => 4]);
    }

    /**
     * @param array<string, mixed>  $entry
     * @param array<string, string> $labels
     */
    private function sectionBody(AbstractContainer $section, array $entry, array $labels): void
    {
        $type = $entry['type'];
        if (SectionTypes::isText($type)) {
            foreach (preg_split('/\R{2,}/', (string) $entry['text']) ?: [] as $paragraph) {
                $this->rich($section->addTextRun(['alignment' => Jc::BOTH, 'spaceAfter' => 80]), $paragraph);
            }

            return;
        }

        foreach ($entry['items'] as $item) {
            switch ($type) {
                case 'skills':
                    $run = $section->addTextRun(['spaceAfter' => 40]);
                    $run->addText(htmlspecialchars($item['group']).': ', ['bold' => true]);
                    $this->rich($run, $item['skills']);
                    continue 2;
                case 'languages':
                    $run = $section->addTextRun(['spaceAfter' => 20]);
                    $run->addText(htmlspecialchars($item['name']), ['bold' => true]);
                    $run->addText(htmlspecialchars(rtrim(' — '.$item['level'].($item['note'] !== '' ? ' ('.$item['note'].')' : ''), ' —')));
                    continue 2;
                case 'certifications':
                    $run = $section->addTextRun(['spaceAfter' => 20]);
                    $run->addText(htmlspecialchars($item['name']), ['bold' => true]);
                    $run->addText(htmlspecialchars(implode(' — ', array_filter([$item['issuer'], $item['date']])) !== '' ? ' — '.implode(' — ', array_filter([$item['issuer'], $item['date']])) : ''));
                    continue 2;
                case 'publications':
                    $run = $section->addTextRun(['spaceAfter' => 60]);
                    $this->rich($run, $item['authors']);
                    $run->addText(' — ');
                    $run->addText(htmlspecialchars('"'.$item['title'].'"'), ['italic' => true]);
                    $run->addText(htmlspecialchars(implode(', ', array_filter([' '.$item['venue'], $item['date']]))));
                    continue 2;
            }

            [$title, $subtitle] = match ($type) {
                'experience' => [$item['title'], implode(', ', array_filter([$item['organization'], $item['location']]))],
                'projects' => [$item['name'], $item['role']],
                'education' => [$item['degree'], implode(', ', array_filter([$item['institution'], $item['location']]))],
                default => [$item['title'] ?? '', $item['subtitle'] ?? ''],
            };
            $period = $type === 'custom' ? ($item['date'] ?? '') : implode(' – ', array_filter([$item['start'] ?? '', $item['end'] ?? '']));

            $run = $section->addTextRun(['spaceBefore' => 100, 'spaceAfter' => 0, 'tabs' => [new \PhpOffice\PhpWord\Style\Tab('right', (int) round(Converter::cmToTwip(17.4)))]]);
            if ($title !== '') {
                $this->rich($run, $title, ['bold' => true, 'size' => 10.5]);
            }
            if ($period !== '') {
                $run->addText("\t".htmlspecialchars($period), ['size' => 9, 'color' => '666666', 'bold' => true]);
            }
            if ($subtitle !== '') {
                $section->addText(htmlspecialchars($subtitle), ['italic' => true, 'color' => '555555', 'size' => 9.5], ['spaceAfter' => 30]);
            }
            if (($item['grade'] ?? '') !== '') {
                $section->addText(htmlspecialchars($labels['grade'].': '.$item['grade']), ['size' => 9.5], ['spaceAfter' => 20]);
            }
            if (($item['description'] ?? '') !== '') {
                $this->rich($section->addTextRun(['alignment' => Jc::BOTH, 'spaceAfter' => 30]), $item['description']);
            }
            foreach ($item['bullets'] ?? [] as $bullet) {
                $this->rich($section->addListItemRun(0, 'cvBullets', ['spaceAfter' => 10, 'alignment' => Jc::BOTH]), $bullet);
            }
            if (($item['tech'] ?? '') !== '') {
                $run = $section->addTextRun(['spaceAfter' => 30]);
                $run->addText(htmlspecialchars($labels['tech'].': '), ['bold' => true, 'italic' => true, 'size' => 9, 'color' => '555555']);
                $run->addText(htmlspecialchars($item['tech']), ['italic' => true, 'size' => 9, 'color' => '555555']);
            }
            if (($item['url'] ?? '') !== '') {
                $section->addText(htmlspecialchars(self::pretty($item['url'])), ['size' => 9, 'color' => '1F4E79'], ['spaceAfter' => 20]);
            }
        }
    }

    /**
     * Add text with **bold** and *italic* spans as separate runs.
     *
     * @param array<string, mixed> $base
     */
    private function rich(TextRun $run, string $text, array $base = []): void
    {
        $parts = preg_split('/(\*\*.+?\*\*|(?<![\*\w])\*(?!\s).+?(?<!\s)\*(?![\*\w]))/su', $text, -1, \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            if (str_starts_with($part, '**') && str_ends_with($part, '**') && \strlen($part) > 4) {
                $run->addText(htmlspecialchars(substr($part, 2, -2)), $base + ['bold' => true]);
            } elseif (str_starts_with($part, '*') && str_ends_with($part, '*') && \strlen($part) > 2) {
                $run->addText(htmlspecialchars(substr($part, 1, -1)), $base + ['italic' => true]);
            } else {
                $run->addText(htmlspecialchars($part), $base);
            }
        }
    }

    private static function pretty(mixed $url): string
    {
        return rtrim((string) preg_replace('#^(https?://)?(www\.)?#i', '', \is_scalar($url) ? trim((string) $url) : ''), '/');
    }
}
