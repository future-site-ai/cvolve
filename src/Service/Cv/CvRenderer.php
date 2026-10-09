<?php

declare(strict_types=1);

namespace App\Service\Cv;

use Cvolve\TemplateKit\SectionTypes;
use App\Entity\CvVariant;
use App\Entity\Template;
use App\Service\Storage;
use Cvolve\TemplateKit\Render\SampleCv;
use Cvolve\TemplateKit\Render\DocumentRenderer;

/**
 * Renders a CV variant through its template into one self-contained HTML document
 * (CSS, fonts and photo inlined). The same document feeds the live preview, the
 * public share page and the PDF export, so all three look identical.
 */
final class CvRenderer
{
    public const MODE_SCREEN = DocumentRenderer::MODE_SCREEN;
    public const MODE_PRINT = DocumentRenderer::MODE_PRINT;

    public function __construct(
        private readonly DocumentRenderer $documents,
        private readonly Storage $storage,
        private readonly PhotoStorage $photos,
    ) {
    }

    public function render(CvVariant $variant, string $mode = self::MODE_SCREEN, ?Template $template = null): string
    {
        $template ??= $variant->getCv()->getTemplate();

        return $this->document($template, $this->context($variant, $template), $mode, $variant->getCv()->getTitle());
    }

    public function renderSample(Template $template, string $mode = self::MODE_SCREEN): string
    {
        return $this->document($template, SampleCv::context($template->getSchema()), $mode, $template->getName());
    }

    /**
     * The variables a template receives. Only visible sections, entries and bullets are passed.
     *
     * @return array<string, mixed>
     */
    public function context(CvVariant $variant, ?Template $template = null): array
    {
        $template ??= $variant->getCv()->getTemplate();
        $locale = $variant->getLocale();
        $header = [];
        foreach (array_keys(SectionTypes::HEADER_FIELDS) as $field) {
            $header[$field] = \is_scalar($variant->getHeader()[$field] ?? null) ? (string) $variant->getHeader()[$field] : '';
        }
        $header['photo'] = null;
        $photo = $variant->getOwner()->getMasterProfile()?->getPersonal()->getPhoto();
        if (($variant->getHeader()['showPhoto'] ?? false) && $photo !== null) {
            $header['photo'] = $this->photos->dataUri($photo);
        }

        $regions = array_fill_keys($template->getRegions(), []);
        $sections = [];
        foreach ($variant->getOrderedSections() as $section) {
            if (!$section->isVisible() || !$template->supportsSection($section->getType())) {
                continue;
            }
            $region = $section->getRegion();
            if ($region === null || !\array_key_exists($region, $regions)) {
                $region = $template->getDefaultRegion($section->getType());
            }
            $content = SectionTypes::normalize($section->getType(), $section->getContent());
            $items = [];
            foreach ($content['items'] ?? [] as $item) {
                if (!$item['visible']) {
                    continue;
                }
                if (isset($item['bullets'])) {
                    $item['bullets'] = array_values(array_map(
                        static fn (array $b) => $b['text'],
                        array_filter($item['bullets'], static fn (array $b) => $b['visible']),
                    ));
                }
                unset($item['visible']);
                $items[] = $item;
            }
            if (($content['text'] ?? '') === '' && $items === []) {
                continue;
            }
            $entry = [
                'id' => $section->getId(),
                'type' => $section->getType(),
                'title' => $section->getTitle(),
                'region' => $region,
                'text' => $content['text'] ?? '',
                'items' => $items,
            ];
            $sections[] = $entry;
            $regions[$region][] = $entry;
        }

        return [
            'cv' => ['title' => $variant->getCv()->getTitle(), 'locale' => $locale, 'dir' => $locale === 'ar' ? 'rtl' : 'ltr'],
            'header' => $header,
            'sections' => $sections,
            'regions' => $regions,
            'labels' => SectionTypes::labels($locale),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function document(Template $template, array $context, string $mode, string $title): string
    {
        $assets = fn (string $path): ?string => $this->storage->dataUri('templates/'.$template->getStorageKey().'/'.$path);

        return $this->documents->render($template->getTwigSource(), $template->getCss(), $template->getManifest(), $context, \Closure::fromCallable($assets), $mode, $title);
    }
}
