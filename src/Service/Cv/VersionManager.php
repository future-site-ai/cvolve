<?php

declare(strict_types=1);

namespace App\Service\Cv;

use Cvolve\TemplateKit\SectionTypes;
use App\Entity\CvSection;
use App\Entity\CvVariant;
use App\Entity\CvVersion;
use App\Entity\Template;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Snapshots of CV variants: taken before every applied AI change, on demand, and before a restore.
 */
final class VersionManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TemplateRepository $templates,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(CvVariant $variant): array
    {
        $template = $variant->getCv()->getTemplate();

        return [
            'template_id' => $template->getId(),
            'template_name' => $template->getName(),
            'title' => $variant->getCv()->getTitle(),
            'locale' => $variant->getLocale(),
            'header' => $variant->getHeader(),
            'sections' => array_map(static fn (CvSection $s) => [
                'type' => $s->getType(),
                'title' => $s->getTitle(),
                'visible' => $s->isVisible(),
                'region' => $s->getRegion(),
                'content' => $s->getContent(),
                'sourceRefs' => $s->getSourceRefs(),
            ], $variant->getOrderedSections()),
        ];
    }

    /**
     * Record a version (persisted; the caller flushes).
     */
    public function record(CvVariant $variant, string $label, string $reason = CvVersion::REASON_MANUAL): CvVersion
    {
        $version = new CvVersion($variant->getCv(), $variant->getLocale(), $label, $reason, $this->snapshot($variant));
        $this->em->persist($version);

        return $version;
    }

    /**
     * Restore a version into its variant, after snapshotting the current state.
     */
    public function restore(CvVersion $version): CvVariant
    {
        $cv = $version->getCv();
        $variant = $cv->getVariant($version->getLocale()) ?? new CvVariant($cv, $version->getLocale());
        if ($variant->getId() !== null) {
            $this->record($variant, sprintf('Before restoring "%s"', $version->getLabel()), CvVersion::REASON_RESTORE);
        } else {
            $this->em->persist($variant);
        }

        $snapshot = $version->getSnapshot();
        $template = isset($snapshot['template_id']) ? $this->templates->find($snapshot['template_id']) : null;
        if ($template instanceof Template && $this->templates->isAvailableFor($template, $cv->getOwner())) {
            $cv->setTemplate($template);
        }
        $this->load($variant, $snapshot);

        return $variant;
    }

    /**
     * Replace a variant's header and sections with snapshot data.
     *
     * @param array<string, mixed> $snapshot
     */
    public function load(CvVariant $variant, array $snapshot): void
    {
        foreach ($variant->getSections()->toArray() as $section) {
            $variant->getSections()->removeElement($section);
        }
        $variant->setHeader(\is_array($snapshot['header'] ?? null) ? $snapshot['header'] : []);
        foreach (\is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [] as $data) {
            if (!\is_array($data) || !SectionTypes::exists((string) ($data['type'] ?? ''))) {
                continue;
            }
            $type = (string) $data['type'];
            $section = new CvSection($type, (string) ($data['title'] ?? SectionTypes::defaultTitle($type, $variant->getLocale())), SectionTypes::normalize($type, \is_array($data['content'] ?? null) ? $data['content'] : []));
            $section->setVisible((bool) ($data['visible'] ?? true));
            $section->setRegion(isset($data['region']) && \is_string($data['region']) ? $data['region'] : null);
            $section->setSourceRefs(\is_array($data['sourceRefs'] ?? null) ? array_values(array_filter($data['sourceRefs'], 'is_string')) : []);
            $variant->addSection($section);
        }
        $variant->touch();
    }

    /**
     * Plain-text rendering of a snapshot, for comparing versions.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return list<string>
     */
    public static function lines(array $snapshot): array
    {
        $lines = ['Template: '.($snapshot['template_name'] ?? '?')];
        foreach (\is_array($snapshot['header'] ?? null) ? $snapshot['header'] : [] as $field => $value) {
            if (\is_scalar($value) && (string) $value !== '') {
                $lines[] = sprintf('%s: %s', $field, \is_bool($value) ? ($value ? 'yes' : 'no') : $value);
            }
        }
        foreach (\is_array($snapshot['sections'] ?? null) ? $snapshot['sections'] : [] as $section) {
            $type = (string) ($section['type'] ?? '');
            if (!SectionTypes::exists($type)) {
                continue;
            }
            $lines[] = '';
            $lines[] = sprintf('## %s%s', $section['title'] ?? $type, ($section['visible'] ?? true) ? '' : ' [hidden]');
            array_push($lines, ...SectionTypes::toLines($type, SectionTypes::normalize($type, \is_array($section['content'] ?? null) ? $section['content'] : [])));
        }

        return $lines;
    }
}
