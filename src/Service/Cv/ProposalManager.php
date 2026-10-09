<?php

declare(strict_types=1);

namespace App\Service\Cv;

use Cvolve\TemplateKit\Util\TextDiff;
use Cvolve\TemplateKit\SectionTypes;
use App\Entity\AiProposal;
use App\Entity\ChatConversation;
use App\Entity\Cv;
use App\Entity\CvSection;
use App\Entity\CvVariant;
use App\Entity\CvVersion;
use App\Entity\Template;
use App\Repository\TemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The "AI proposes, user approves" workflow.
 *
 * Operations (validated when proposed, applied only on approval):
 *   {"op": "set_header", "header": {...partial header...}}
 *   {"op": "set_section", "section_id": 12, "content": {...}, "title"?: "...", "visible"?: true}
 *   {"op": "add_section", "type": "summary", "title"?: "...", "content": {...}, "position"?: 0, "region"?: "main"}
 *   {"op": "remove_section", "section_id": 12}
 *   {"op": "reorder_sections", "section_ids": [3, 1, 2]}
 *   {"op": "set_template", "template_id": 4}
 *   {"op": "create_variant", "locale": "it", "header": {...}, "sections": [{"type", "title", "content", "region", "visible"}]}
 */
final class ProposalManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VersionManager $versions,
        private readonly TemplateRepository $templates,
    ) {
    }

    /**
     * Validate and store a proposal.
     *
     * @param list<array<string, mixed>> $operations
     *
     * @throws ProposalException when an operation is invalid (the message is meant for the AI to fix its call)
     */
    public function propose(Cv $cv, CvVariant $variant, string $title, array $operations, string $source, ?ChatConversation $conversation = null, ?string $description = null): AiProposal
    {
        if ($operations === []) {
            throw new ProposalException('The proposal contains no changes.');
        }
        $normalized = [];
        foreach ($operations as $i => $operation) {
            try {
                $normalized[] = $this->normalizeOperation($cv, $variant, $operation);
            } catch (ProposalException $e) {
                throw new ProposalException(sprintf('Operation %d (%s): %s', $i, $operation['op'] ?? '?', $e->getMessage()), $e->errors);
            }
        }

        $proposal = new AiProposal($cv, $variant, $title, $normalized, $source);
        $proposal->setConversation($conversation)->setDescription($description);
        $this->em->persist($proposal);
        $this->em->flush();

        return $proposal;
    }

    /**
     * Apply a pending proposal, snapshotting the variant first.
     */
    public function apply(AiProposal $proposal): CvVariant
    {
        if (!$proposal->isPending()) {
            throw new ProposalException('This proposal was already '.$proposal->getStatus().'.');
        }
        $cv = $proposal->getCv();
        $variant = $proposal->getVariant() ?? $cv->getDefaultVariant();

        // Re-validate against the current state: the CV may have changed since the proposal was made
        $operations = [];
        foreach ($proposal->getOperations() as $operation) {
            $operations[] = $this->normalizeOperation($cv, $variant, $operation);
        }

        $this->versions->record($variant, 'Before AI: '.$proposal->getTitle(), CvVersion::REASON_AI);
        $result = $variant;
        foreach ($operations as $operation) {
            $result = $this->applyOperation($cv, $variant, $operation) ?? $result;
        }
        $variant->touch();
        $proposal->resolve(AiProposal::STATUS_APPLIED);
        $this->em->flush();

        return $result;
    }

    public function discard(AiProposal $proposal): void
    {
        if ($proposal->isPending()) {
            $proposal->resolve(AiProposal::STATUS_DISCARDED);
            $this->em->flush();
        }
    }

    /**
     * Before/after view of every operation, for the approval UI and API.
     *
     * @return list<array{label: string, diff: list<array{type: string, line: string}>}>
     */
    public function preview(AiProposal $proposal): array
    {
        $cv = $proposal->getCv();
        $variant = $proposal->getVariant() ?? $cv->getDefaultVariant();
        $changes = [];
        foreach ($proposal->getOperations() as $op) {
            $section = isset($op['section_id']) ? $variant->getSection((int) $op['section_id']) : null;
            switch ($op['op']) {
                case 'set_header':
                    $before = $this->headerLines($variant->getHeader());
                    $after = $this->headerLines(array_merge($variant->getHeader(), $op['header']));
                    $changes[] = ['label' => 'Header', 'diff' => TextDiff::lines($before, $after)];
                    break;
                case 'set_section':
                    $before = $section ? $this->sectionLines($section->getType(), $section->getTitle(), $section->getContent(), $section->isVisible()) : [];
                    $after = $section ? $this->sectionLines($section->getType(), $op['title'] ?? $section->getTitle(), $op['content'], $op['visible'] ?? $section->isVisible()) : [];
                    $changes[] = ['label' => $section ? $section->getTitle() : 'Section (deleted since)', 'diff' => TextDiff::lines($before, $after)];
                    break;
                case 'add_section':
                    $changes[] = ['label' => 'New section: '.$op['title'], 'diff' => TextDiff::lines([], $this->sectionLines($op['type'], $op['title'], $op['content'], true))];
                    break;
                case 'remove_section':
                    $changes[] = ['label' => 'Remove section: '.($section?->getTitle() ?? '?'), 'diff' => TextDiff::lines($section ? $this->sectionLines($section->getType(), $section->getTitle(), $section->getContent(), $section->isVisible()) : [], [])];
                    break;
                case 'reorder_sections':
                    $before = array_map(static fn (CvSection $s) => $s->getTitle(), $variant->getOrderedSections());
                    $after = [];
                    foreach ($this->orderedIds($variant, $op['section_ids']) as $id) {
                        $after[] = $variant->getSection($id)?->getTitle() ?? '?';
                    }
                    $changes[] = ['label' => 'Section order', 'diff' => TextDiff::lines($before, $after)];
                    break;
                case 'set_template':
                    $template = $this->templates->find($op['template_id']);
                    $changes[] = ['label' => 'Template', 'diff' => TextDiff::lines([$cv->getTemplate()->getName()], [$template?->getName() ?? '?'])];
                    break;
                case 'create_variant':
                    $lines = VersionManager::lines(['template_name' => $cv->getTemplate()->getName(), 'header' => $op['header'], 'sections' => $op['sections']]);
                    $changes[] = ['label' => sprintf('New language version: %s', SectionTypes::LOCALES[$op['locale']] ?? $op['locale']), 'diff' => TextDiff::lines([], $lines)];
                    break;
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $op
     *
     * @return array<string, mixed>
     */
    private function normalizeOperation(Cv $cv, CvVariant $variant, array $op): array
    {
        $template = $cv->getTemplate();
        $type = \is_string($op['op'] ?? null) ? $op['op'] : '';

        switch ($type) {
            case 'set_header':
                if (!\is_array($op['header'] ?? null) || $op['header'] === []) {
                    throw new ProposalException('"header" must be an object of header fields.');
                }

                return ['op' => $type, 'header' => $this->normalizeHeader($op['header'])];

            case 'set_section':
                $section = $this->section($variant, $op);
                $content = SectionTypes::normalize($section->getType(), \is_array($op['content'] ?? null) ? $op['content'] : $section->getContent());
                $this->checkRules($template, $section->getType(), $content);
                $out = ['op' => $type, 'section_id' => $section->getId(), 'content' => $content];
                if (isset($op['title']) && \is_string($op['title']) && trim($op['title']) !== '') {
                    $out['title'] = mb_substr(trim($op['title']), 0, 120);
                }
                if (isset($op['visible'])) {
                    $out['visible'] = (bool) $op['visible'];
                }

                return $out;

            case 'add_section':
                $sectionType = \is_string($op['type'] ?? null) ? $op['type'] : '';
                if (!SectionTypes::exists($sectionType)) {
                    throw new ProposalException(sprintf('Unknown section type "%s".', $sectionType));
                }
                if (!$template->supportsSection($sectionType)) {
                    throw new ProposalException(sprintf('The template "%s" does not support "%s" sections. Supported: %s.', $template->getName(), $sectionType, implode(', ', $template->getSupportedSectionTypes())));
                }
                $existing = $sectionType !== 'custom' ? $variant->findSectionByType($sectionType) : null;
                if ($existing !== null) {
                    throw new ProposalException(sprintf('This CV already has a "%s" section (id %d); change it with set_section instead.', $sectionType, $existing->getId()));
                }
                $content = SectionTypes::normalize($sectionType, \is_array($op['content'] ?? null) ? $op['content'] : []);
                $this->checkRules($template, $sectionType, $content);
                $region = \is_string($op['region'] ?? null) && \in_array($op['region'], $template->getRegions(), true) ? $op['region'] : null;

                return [
                    'op' => $type,
                    'type' => $sectionType,
                    'title' => \is_string($op['title'] ?? null) && trim($op['title']) !== '' ? mb_substr(trim($op['title']), 0, 120) : SectionTypes::defaultTitle($sectionType, $variant->getLocale()),
                    'content' => $content,
                    'position' => isset($op['position']) && is_numeric($op['position']) ? (int) $op['position'] : null,
                    'region' => $region,
                    'sourceRefs' => \is_array($op['sourceRefs'] ?? null) ? array_values(array_filter($op['sourceRefs'], 'is_string')) : [],
                ];

            case 'remove_section':
                return ['op' => $type, 'section_id' => $this->section($variant, $op)->getId()];

            case 'reorder_sections':
                $ids = \is_array($op['section_ids'] ?? null) ? array_map('intval', $op['section_ids']) : [];
                foreach ($ids as $id) {
                    if ($variant->getSection($id) === null) {
                        throw new ProposalException(sprintf('Section %d does not exist in this CV.', $id));
                    }
                }
                if ($ids === []) {
                    throw new ProposalException('"section_ids" must list section ids in the new order.');
                }

                return ['op' => $type, 'section_ids' => array_values(array_unique($ids))];

            case 'set_template':
                $target = isset($op['template_id']) ? $this->templates->find((int) $op['template_id']) : null;
                if (!$target instanceof Template || !$this->templates->isAvailableFor($target, $cv->getOwner())) {
                    throw new ProposalException('Unknown template. Call list_templates to see the available ones.');
                }

                return ['op' => $type, 'template_id' => $target->getId()];

            case 'create_variant':
                $locale = \is_string($op['locale'] ?? null) ? $op['locale'] : '';
                if (!isset(SectionTypes::LOCALES[$locale])) {
                    throw new ProposalException(sprintf('Unsupported locale "%s". Supported: %s.', $locale, implode(', ', array_keys(SectionTypes::LOCALES))));
                }
                if ($cv->getVariant($locale) !== null) {
                    throw new ProposalException(sprintf('This CV already has a %s version.', $locale));
                }
                $sections = [];
                foreach (\is_array($op['sections'] ?? null) ? $op['sections'] : [] as $s) {
                    if (!\is_array($s) || !SectionTypes::exists((string) ($s['type'] ?? ''))) {
                        continue;
                    }
                    $sections[] = [
                        'type' => $s['type'],
                        'title' => (string) ($s['title'] ?? SectionTypes::defaultTitle($s['type'], $locale)),
                        'visible' => (bool) ($s['visible'] ?? true),
                        'region' => \is_string($s['region'] ?? null) ? $s['region'] : null,
                        'content' => SectionTypes::normalize($s['type'], \is_array($s['content'] ?? null) ? $s['content'] : []),
                    ];
                }
                if ($sections === []) {
                    throw new ProposalException('"sections" must contain the translated sections.');
                }

                return ['op' => $type, 'locale' => $locale, 'header' => $this->normalizeHeader(\is_array($op['header'] ?? null) ? $op['header'] : $variant->getHeader()), 'sections' => $sections];
        }

        throw new ProposalException(sprintf('Unknown operation "%s".', $type));
    }

    /**
     * @param array<string, mixed> $op
     */
    private function applyOperation(Cv $cv, CvVariant $variant, array $op): ?CvVariant
    {
        switch ($op['op']) {
            case 'set_header':
                $variant->setHeader(array_merge($variant->getHeader(), $op['header']));
                break;
            case 'set_section':
                $section = $variant->getSection($op['section_id']);
                $section?->setContent($op['content']);
                if (isset($op['title'])) {
                    $section?->setTitle($op['title']);
                }
                if (isset($op['visible'])) {
                    $section?->setVisible($op['visible']);
                }
                break;
            case 'add_section':
                $section = new CvSection($op['type'], $op['title'], $op['content']);
                $section->setRegion($op['region'] ?? $cv->getTemplate()->getDefaultRegion($op['type']));
                $section->setSourceRefs($op['sourceRefs'] ?? []);
                $variant->addSection($section, $op['position']);
                break;
            case 'remove_section':
                $section = $variant->getSection($op['section_id']);
                if ($section !== null) {
                    $variant->removeSection($section);
                }
                break;
            case 'reorder_sections':
                foreach ($this->orderedIds($variant, $op['section_ids']) as $position => $id) {
                    $variant->getSection($id)?->setPosition($position);
                }
                break;
            case 'set_template':
                $template = $this->templates->find($op['template_id']);
                if ($template !== null) {
                    $cv->setTemplate($template);
                }
                break;
            case 'create_variant':
                $new = new CvVariant($cv, $op['locale']);
                $this->em->persist($new);
                $this->versions->load($new, ['header' => $op['header'], 'sections' => $op['sections']]);

                return $new;
        }

        return null;
    }

    /**
     * The proposed order first, then any section the proposal did not mention.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function orderedIds(CvVariant $variant, array $ids): array
    {
        $rest = array_values(array_filter(
            array_map(static fn (CvSection $s) => (int) $s->getId(), $variant->getOrderedSections()),
            static fn (int $id) => !\in_array($id, $ids, true),
        ));

        return [...$ids, ...$rest];
    }

    /**
     * @param array<string, mixed> $op
     */
    private function section(CvVariant $variant, array $op): CvSection
    {
        $section = isset($op['section_id']) && is_numeric($op['section_id']) ? $variant->getSection((int) $op['section_id']) : null;
        if ($section === null) {
            throw new ProposalException(sprintf('Section %s does not exist in this CV. Call get_cv to see section ids.', json_encode($op['section_id'] ?? null)));
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function checkRules(Template $template, string $type, array $content): void
    {
        $errors = SectionTypes::validate($type, $content, $template->getSectionRules()[$type] ?? []);
        if ($errors !== []) {
            throw new ProposalException(implode(' ', $errors), $errors);
        }
    }

    /**
     * @param array<mixed> $header
     *
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $header): array
    {
        $out = [];
        foreach (array_keys(SectionTypes::HEADER_FIELDS) as $field) {
            if (\array_key_exists($field, $header) && (\is_scalar($header[$field]) || $header[$field] === null)) {
                $out[$field] = trim((string) $header[$field]);
            }
        }
        if (\array_key_exists('showPhoto', $header)) {
            $out['showPhoto'] = (bool) $header['showPhoto'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $header
     *
     * @return list<string>
     */
    private function headerLines(array $header): array
    {
        $lines = [];
        foreach (SectionTypes::HEADER_FIELDS as $field => $label) {
            if (($header[$field] ?? '') !== '') {
                $lines[] = $label.': '.$header[$field];
            }
        }
        $lines[] = 'Photo: '.(($header['showPhoto'] ?? false) ? 'shown' : 'hidden');

        return $lines;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    private function sectionLines(string $type, string $title, array $content, bool $visible): array
    {
        return ['## '.$title.($visible ? '' : ' [hidden]'), ...SectionTypes::toLines($type, SectionTypes::normalize($type, $content))];
    }
}
