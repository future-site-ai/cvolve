<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\AiProposal;
use App\Entity\Cv;
use App\Entity\CvSection;
use App\Entity\CvVariant;
use App\Entity\Template;
use App\Repository\AiProposalRepository;
use App\Repository\CvRepository;
use App\Repository\TemplateRepository;
use App\Service\Ai\AiTextService;
use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\Prompts;
use App\Service\Cv\CvBuilder;
use App\Service\Cv\CvChecker;
use App\Service\Cv\ProfileSerializer;
use App\Service\Cv\ProposalException;
use App\Service\Cv\ProposalManager;
use Cvolve\TemplateKit\SectionTypes;
use App\Service\Export\ExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every tool CVolve exposes, implemented once and shared by the chat, MCP and REST API.
 *
 * Read tools answer directly. Tools that change an existing CV never write to it: they
 * create an AiProposal that the user applies (or discards) in the CV editor.
 */
final class CvToolbox
{
    /** @var array<string, ToolDefinition>|null */
    private ?array $definitions = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CvRepository $cvs,
        private readonly TemplateRepository $templates,
        private readonly AiProposalRepository $proposals,
        private readonly ProposalManager $proposalManager,
        private readonly CvBuilder $builder,
        private readonly ProfileSerializer $serializer,
        private readonly CvChecker $checker,
        private readonly ExportService $exports,
        private readonly AiTextService $ai,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, ToolDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions ??= $this->build();
    }

    public function get(string $name): ?ToolDefinition
    {
        return $this->definitions()[$name] ?? null;
    }

    /**
     * Run a tool. Errors the caller can fix come back as {"error": "..."} rather than exceptions.
     *
     * @param array<string, mixed> $args
     *
     * @return array<mixed>
     */
    public function call(string $name, array $args, ToolContext $context): array
    {
        $tool = $this->get($name);
        if ($tool === null) {
            return ['error' => sprintf('Unknown tool "%s".', $name)];
        }
        foreach ($tool->required as $required) {
            if (!\array_key_exists($required, $args) || $args[$required] === null || $args[$required] === '') {
                $context->calls[] = ['tool' => $name, 'ok' => false];

                return ['error' => sprintf('Missing required argument "%s".', $required)];
            }
        }

        try {
            $result = ($tool->handler)($context, $args);
            $context->calls[] = ['tool' => $name, 'ok' => true];

            return $result;
        } catch (ToolException|ProposalException|AiUnavailableException|\InvalidArgumentException $e) {
            $context->calls[] = ['tool' => $name, 'ok' => false];

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, ToolDefinition>
     */
    private function build(): array
    {
        $cvId = ['type' => 'integer', 'description' => 'CV id. Optional in the CVolve chat (defaults to the open CV).'];
        $locale = ['type' => 'string', 'description' => 'Language version of the CV, e.g. "en" or "it". Defaults to the CV\'s main language.'];
        $sectionId = ['type' => 'integer', 'description' => 'Section id, from get_cv.'];
        $contentJson = ['type' => 'string', 'description' => "Section content as a JSON object string. Shapes by section type:\n".SectionTypes::describeForAi()];
        $targets = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Entries as "2" (entry #2) or bullets as "2.3" (bullet 3 of entry 2); indexes from get_cv, starting at 0.'];

        $tools = [
            new ToolDefinition('list_cvs', 'List the user\'s CVs with their template and language versions.', [], [], fn (ToolContext $c) => $this->listCvs($c)),

            new ToolDefinition('get_cv', 'Get one CV: header, and every section with its id, type, title, visibility and JSON content. Call this before changing a CV.', ['cv_id' => $cvId, 'locale' => $locale], [], fn (ToolContext $c, array $a) => $this->getCv($c, $a)),

            new ToolDefinition('get_master_profile', 'Get the user\'s master profile: the full career record (with ids) that CVs are built from.', [], [], fn (ToolContext $c) => $this->serializer->toArray($this->profile($c), true)),

            new ToolDefinition('list_templates', 'List the CV templates available to the user and the section types each supports.', [], [], fn (ToolContext $c) => ['templates' => array_map(fn (Template $t) => $this->templateSummary($t), $this->templates->findAvailableFor($c->user))]),

            new ToolDefinition('get_template_schema', 'Get a template\'s schema: regions, supported sections and their limits (maxLength, maxItems, maxBullets).', [
                'template_id' => ['type' => 'integer', 'description' => 'Template id. Defaults to the template of the CV.'],
                'cv_id' => $cvId,
            ], [], fn (ToolContext $c, array $a) => $this->templateSchema($c, $a)),

            new ToolDefinition('create_cv', 'Create a new CV from the master profile (this creates a new CV directly; it does not change existing ones).', [
                'title' => ['type' => 'string', 'description' => 'CV title, e.g. "Backend Engineer – Acme".'],
                'template_id' => ['type' => 'integer', 'description' => 'Template id from list_templates. Defaults to the first preset.'],
                'locale' => $locale,
                'summary_id' => ['type' => 'integer', 'description' => 'Master profile summary to use.'],
                'experience_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Master experience ids to include (default: all).'],
            ], ['title'], fn (ToolContext $c, array $a) => $this->createCv($c, $a)),

            new ToolDefinition('add_section', 'Propose adding a section. Give content_json, or set from_master=true to build it from the master profile.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'type' => ['type' => 'string', 'enum' => array_keys(SectionTypes::TYPES), 'description' => 'Section type.'],
                'title' => ['type' => 'string', 'description' => 'Section heading (defaults to the standard title in the CV language).'],
                'content_json' => $contentJson,
                'from_master' => ['type' => 'boolean', 'description' => 'Build the content from the master profile.'],
                'master_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'With from_master: only these master item ids (summary, experience, project or custom section ids).'],
                'position' => ['type' => 'integer', 'description' => '0-based position among the sections (default: end).'],
            ], ['type'], fn (ToolContext $c, array $a) => $this->addSection($c, $a)),

            new ToolDefinition('update_section', 'Propose new content, title or visibility for an existing section.', [
                'cv_id' => $cvId, 'locale' => $locale, 'section_id' => $sectionId,
                'content_json' => $contentJson,
                'title' => ['type' => 'string', 'description' => 'New heading.'],
                'visible' => ['type' => 'boolean', 'description' => 'Show or hide the whole section.'],
                'summary' => ['type' => 'string', 'description' => 'One line describing the change for the user.'],
            ], ['section_id'], fn (ToolContext $c, array $a) => $this->updateSection($c, $a)),

            new ToolDefinition('remove_section', 'Propose removing a section from the CV (the master profile is not affected).', [
                'cv_id' => $cvId, 'locale' => $locale, 'section_id' => $sectionId,
            ], ['section_id'], fn (ToolContext $c, array $a) => $this->removeSection($c, $a)),

            new ToolDefinition('reorder_sections', 'Propose a new section order.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'section_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Section ids in the new order; unlisted sections keep their relative order after these.'],
            ], ['section_ids'], fn (ToolContext $c, array $a) => $this->reorderSections($c, $a)),

            new ToolDefinition('update_header', 'Propose changes to the CV header (name, headline, contact details, photo on/off).', [
                'cv_id' => $cvId, 'locale' => $locale,
                'fields_json' => ['type' => 'string', 'description' => 'JSON object with any of: '.implode(', ', array_keys(SectionTypes::HEADER_FIELDS)).', showPhoto (boolean).'],
            ], ['fields_json'], fn (ToolContext $c, array $a) => $this->updateHeader($c, $a)),

            new ToolDefinition('set_professional_summary', 'Propose the professional summary text exactly as given (adds the section if missing).', [
                'cv_id' => $cvId, 'locale' => $locale,
                'text' => ['type' => 'string', 'description' => 'The summary. **bold** and *italic* are allowed.'],
            ], ['text'], fn (ToolContext $c, array $a) => $this->setSummary($c, $a, (string) $a['text'], 'Set professional summary')),

            new ToolDefinition('generate_summary_from_master', 'Write a professional summary from the master profile with CVolve\'s AI and propose it.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'instructions' => ['type' => 'string', 'description' => 'Focus, tone or target role, e.g. "backend role in fintech, 4 lines".'],
            ], [], fn (ToolContext $c, array $a) => $this->generateSummary($c, $a), true),

            new ToolDefinition('suggest_summaries', 'Write three alternative summaries with CVolve\'s AI; each becomes its own proposal so the user can apply the one they like.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'instructions' => ['type' => 'string', 'description' => 'Focus, tone or target role.'],
            ], [], fn (ToolContext $c, array $a) => $this->suggestSummaries($c, $a), true),

            new ToolDefinition('add_experience_from_master', 'Propose adding jobs from the master profile to the CV\'s experience section.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'experience_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Master experience ids (from get_master_profile).'],
            ], ['experience_ids'], fn (ToolContext $c, array $a) => $this->addExperienceFromMaster($c, $a)),

            new ToolDefinition('rewrite_bullets', 'Rewrite the bullets of one entry with CVolve\'s AI and propose the result.', [
                'cv_id' => $cvId, 'locale' => $locale, 'section_id' => $sectionId,
                'item_index' => ['type' => 'integer', 'description' => '0-based entry index in the section.'],
                'style' => ['type' => 'string', 'enum' => ['concise', 'quantified', 'action-oriented', 'formal', 'simpler'], 'description' => 'How to rewrite.'],
                'instructions' => ['type' => 'string', 'description' => 'Extra guidance.'],
            ], ['section_id', 'item_index'], fn (ToolContext $c, array $a) => $this->rewriteBullets($c, $a), true),

            new ToolDefinition('select_items', 'Propose showing entries or bullets that are currently hidden.', [
                'cv_id' => $cvId, 'locale' => $locale, 'section_id' => $sectionId, 'targets' => $targets,
            ], ['section_id', 'targets'], fn (ToolContext $c, array $a) => $this->setVisibility($c, $a, true)),

            new ToolDefinition('deselect_items', 'Propose hiding entries or bullets (they stay in the CV data and can be shown again).', [
                'cv_id' => $cvId, 'locale' => $locale, 'section_id' => $sectionId, 'targets' => $targets,
            ], ['section_id', 'targets'], fn (ToolContext $c, array $a) => $this->setVisibility($c, $a, false)),

            new ToolDefinition('tailor_to_job_description', 'Tailor the CV to a job ad with CVolve\'s AI: summary, headline, which entries and bullets to show, and keyword emphasis. Produces one proposal.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'job_description' => ['type' => 'string', 'description' => 'The full job advertisement text.'],
            ], ['job_description'], fn (ToolContext $c, array $a) => $this->tailor($c, $a), true),

            new ToolDefinition('translate_cv', 'Translate the CV into another language with CVolve\'s AI and propose it as a new language version.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'target_locale' => ['type' => 'string', 'enum' => array_keys(SectionTypes::LOCALES), 'description' => 'Target language.'],
            ], ['target_locale'], fn (ToolContext $c, array $a) => $this->translate($c, $a), true),

            new ToolDefinition('check_cv', 'Review the CV: missing fields, template limits, page count, date consistency, weak bullets and (optionally) keyword coverage for a job description.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'job_description' => ['type' => 'string', 'description' => 'Optional job ad to measure keyword coverage against.'],
                'measure_pages' => ['type' => 'boolean', 'description' => 'Render the PDF to count pages exactly (slower).'],
            ], [], fn (ToolContext $c, array $a) => $this->check($c, $a)),

            new ToolDefinition('switch_template', 'Propose switching the CV to another template (content is kept; styling comes only from templates).', [
                'cv_id' => $cvId,
                'template_id' => ['type' => 'integer', 'description' => 'Template id from list_templates.'],
            ], ['template_id'], fn (ToolContext $c, array $a) => $this->switchTemplate($c, $a)),

            new ToolDefinition('export_cv', 'Export the CV to an A4 PDF or DOCX file. Returns a job and a download link.', [
                'cv_id' => $cvId, 'locale' => $locale,
                'format' => ['type' => 'string', 'enum' => ['pdf', 'docx'], 'description' => 'File format.'],
            ], ['format'], fn (ToolContext $c, array $a) => $this->export($c, $a)),

            new ToolDefinition('list_proposals', 'List proposals for a CV and whether the user applied them.', [
                'cv_id' => $cvId,
                'status' => ['type' => 'string', 'enum' => ['pending', 'applied', 'discarded'], 'description' => 'Filter by status.'],
            ], [], fn (ToolContext $c, array $a) => $this->listProposals($c, $a)),
        ];

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }

        return $byName;
    }

    // ---------------------------------------------------------------- read tools

    /** @return array<string, mixed> */
    private function listCvs(ToolContext $c): array
    {
        return ['cvs' => array_map(fn (Cv $cv) => [
            'id' => $cv->getId(),
            'title' => $cv->getTitle(),
            'template' => ['id' => $cv->getTemplate()->getId(), 'name' => $cv->getTemplate()->getName()],
            'locales' => $cv->getLocales(),
            'default_locale' => $cv->getDefaultLocale(),
            'updated_at' => $cv->getUpdatedAt()->format(\DATE_ATOM),
            'url' => $this->urls->generate('cv_editor', ['id' => $cv->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], $this->cvs->findBy(['owner' => $c->user], ['updatedAt' => 'DESC']))];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function getCv(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);

        return [
            'id' => $cv->getId(),
            'title' => $cv->getTitle(),
            'locale' => $variant->getLocale(),
            'locales' => $cv->getLocales(),
            'template' => $this->templateSummary($cv->getTemplate()),
            'header' => $variant->getHeader(),
            'sections' => array_map(static fn (CvSection $s) => [
                'id' => $s->getId(),
                'type' => $s->getType(),
                'title' => $s->getTitle(),
                'visible' => $s->isVisible(),
                'region' => $s->getRegion(),
                'content' => SectionTypes::normalize($s->getType(), $s->getContent()),
            ], $variant->getOrderedSections()),
            'pending_proposals' => \count($this->proposals->findBy(['cv' => $cv, 'status' => AiProposal::STATUS_PENDING])),
        ];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function templateSchema(ToolContext $c, array $a): array
    {
        if (isset($a['template_id'])) {
            $template = $this->templates->find((int) $a['template_id']);
            if ($template === null || !$this->templates->isAvailableFor($template, $c->user)) {
                throw new ToolException('Unknown template.');
            }
        } else {
            $template = $this->target($c, $a)[0]->getTemplate();
        }

        return $this->templateSummary($template) + ['schema' => $template->getSchema()];
    }

    /** @return array<string, mixed> */
    private function templateSummary(Template $t): array
    {
        return [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'slug' => $t->getSlug(),
            'description' => $t->getDescription(),
            'preset' => $t->isPreset(),
            'regions' => $t->getRegions(),
            'supported_sections' => $t->getSupportedSectionTypes(),
        ];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function listProposals(ToolContext $c, array $a): array
    {
        $criteria = ['cv' => $this->target($c, $a)[0]];
        if (isset($a['status'])) {
            $criteria['status'] = (string) $a['status'];
        }

        return ['proposals' => array_map(static fn (AiProposal $p) => [
            'id' => $p->getId(),
            'title' => $p->getTitle(),
            'status' => $p->getStatus(),
            'source' => $p->getSource(),
            'created_at' => $p->getCreatedAt()->format(\DATE_ATOM),
        ], $this->proposals->findBy($criteria, ['id' => 'DESC'], 50))];
    }

    // ---------------------------------------------------------------- direct actions

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function createCv(ToolContext $c, array $a): array
    {
        $template = isset($a['template_id']) ? $this->templates->find((int) $a['template_id']) : ($this->templates->findAvailableFor($c->user)[0] ?? null);
        if ($template === null || !$this->templates->isAvailableFor($template, $c->user)) {
            throw new ToolException('Unknown template. Call list_templates.');
        }
        $locale = (string) ($a['locale'] ?? 'en');
        if (!isset(SectionTypes::LOCALES[$locale])) {
            throw new ToolException('Unsupported locale. Use one of: '.implode(', ', array_keys(SectionTypes::LOCALES)));
        }
        $cv = $this->builder->create($c->user, mb_substr(trim((string) $a['title']), 0, 150), $template, $locale, [
            'summary_id' => isset($a['summary_id']) ? (int) $a['summary_id'] : null,
            'experience_ids' => isset($a['experience_ids']) && \is_array($a['experience_ids']) ? array_map('intval', $a['experience_ids']) : null,
        ]);
        $this->em->persist($cv);
        $this->em->flush();

        return [
            'cv_id' => $cv->getId(),
            'title' => $cv->getTitle(),
            'url' => $this->urls->generate('cv_editor', ['id' => $cv->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'message' => 'CV created from the master profile.',
        ];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function export(ToolContext $c, array $a): array
    {
        [, $variant] = $this->target($c, $a);
        $job = $this->exports->request($variant, (string) $a['format']);

        return [
            'job_id' => $job->getId(),
            'status' => $job->getStatus(),
            'status_url' => $this->urls->generate('export_show', ['id' => $job->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'download_url' => $this->urls->generate('export_download', ['id' => $job->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            'message' => 'Export queued. The file is ready at download_url once status is "done".',
        ];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function check(ToolContext $c, array $a): array
    {
        [, $variant] = $this->target($c, $a);
        $pages = !empty($a['measure_pages']) ? $this->exports->countPages($variant) : null;

        return $this->checker->check($variant, isset($a['job_description']) ? (string) $a['job_description'] : null, $pages);
    }

    // ---------------------------------------------------------------- proposals

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function addSection(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $type = (string) $a['type'];
        if (!SectionTypes::exists($type)) {
            throw new ToolException(sprintf('Unknown section type "%s".', $type));
        }

        if (!empty($a['from_master'])) {
            $ids = isset($a['master_ids']) && \is_array($a['master_ids']) ? array_map('intval', $a['master_ids']) : null;
            $sections = $this->builder->sectionsFromMaster($type, $this->profile($c), $cv->getTemplate(), $variant->getLocale(), $ids);
            if ($sections === []) {
                throw new ToolException(sprintf('The master profile has nothing for a "%s" section.', $type));
            }
            $ops = [];
            foreach ($sections as $i => $section) {
                $ops[] = [
                    'op' => 'add_section', 'type' => $type,
                    'title' => isset($a['title']) && \count($sections) === 1 ? $a['title'] : $section->getTitle(),
                    'content' => $section->getContent(), 'sourceRefs' => $section->getSourceRefs(),
                    'position' => isset($a['position']) ? (int) $a['position'] + $i : null,
                ];
            }

            return $this->propose($c, $cv, $variant, sprintf('Add %s from master profile', SectionTypes::get($type)['label']), $ops);
        }

        return $this->propose($c, $cv, $variant, sprintf('Add section "%s"', $a['title'] ?? SectionTypes::defaultTitle($type, $variant->getLocale())), [[
            'op' => 'add_section', 'type' => $type, 'title' => $a['title'] ?? null,
            'content' => $this->json($a['content_json'] ?? null, 'content_json') ?? SectionTypes::emptyContent($type),
            'position' => $a['position'] ?? null,
        ]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function updateSection(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $section = $this->section($variant, $a);
        $op = ['op' => 'set_section', 'section_id' => $section->getId()];
        $content = $this->json($a['content_json'] ?? null, 'content_json');
        if ($content !== null) {
            $op['content'] = $content;
        }
        if (isset($a['title'])) {
            $op['title'] = (string) $a['title'];
        }
        if (isset($a['visible'])) {
            $op['visible'] = (bool) $a['visible'];
        }

        return $this->propose($c, $cv, $variant, (string) ($a['summary'] ?? sprintf('Update "%s"', $section->getTitle())), [$op]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function removeSection(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $section = $this->section($variant, $a);

        return $this->propose($c, $cv, $variant, sprintf('Remove "%s"', $section->getTitle()), [['op' => 'remove_section', 'section_id' => $section->getId()]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function reorderSections(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);

        return $this->propose($c, $cv, $variant, 'Reorder sections', [['op' => 'reorder_sections', 'section_ids' => \is_array($a['section_ids']) ? $a['section_ids'] : []]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function updateHeader(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $fields = $this->json($a['fields_json'], 'fields_json');

        return $this->propose($c, $cv, $variant, 'Update header', [['op' => 'set_header', 'header' => $fields ?? []]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function setSummary(ToolContext $c, array $a, string $text, string $title): array
    {
        [$cv, $variant] = $this->target($c, $a);

        return $this->propose($c, $cv, $variant, $title, [$this->summaryOperation($variant, $text)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryOperation(CvVariant $variant, string $text): array
    {
        $existing = $variant->findSectionByType('summary');

        return $existing !== null
            ? ['op' => 'set_section', 'section_id' => $existing->getId(), 'content' => ['text' => trim($text)]]
            : ['op' => 'add_section', 'type' => 'summary', 'content' => ['text' => trim($text)], 'position' => 0];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function generateSummary(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $text = $this->ai->complete(
            Prompts::summaryWriter($variant->getLocale(), $this->maxLength($cv, 'summary')),
            Prompts::summaryContext($this->serializer->toArray($this->profile($c)), $variant, (string) ($a['instructions'] ?? '')),
        );
        $text = $this->trimToLimit($cv, Prompts::stripQuotes($text));

        return $this->propose($c, $cv, $variant, 'AI summary from master profile', [$this->summaryOperation($variant, $text)]) + ['text' => $text];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function suggestSummaries(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $options = $this->ai->completeJson(
            Prompts::summaryWriter($variant->getLocale(), $this->maxLength($cv, 'summary'))."\nWrite exactly three clearly different alternatives (e.g. impact-led, skills-led, story-led). Return a JSON array of three strings.",
            Prompts::summaryContext($this->serializer->toArray($this->profile($c)), $variant, (string) ($a['instructions'] ?? '')),
        );
        $results = [];
        foreach (\array_slice(array_values(array_filter($options, 'is_string')), 0, 3) as $i => $text) {
            $text = $this->trimToLimit($cv, Prompts::stripQuotes($text));
            $results[] = $this->propose($c, $cv, $variant, sprintf('Summary option %d', $i + 1), [$this->summaryOperation($variant, $text)]) + ['text' => $text];
        }
        if ($results === []) {
            throw new ToolException('The AI did not return any summary options.');
        }

        return ['options' => $results, 'message' => 'Three summary proposals were created; the user can apply the one they prefer.'];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function addExperienceFromMaster(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $ids = array_map('intval', \is_array($a['experience_ids']) ? $a['experience_ids'] : []);
        $experiences = array_values(array_filter($this->profile($c)->getExperiences()->toArray(), static fn ($e) => \in_array($e->getId(), $ids, true)));
        if ($experiences === []) {
            throw new ToolException('None of these experience ids exist in the master profile. Call get_master_profile.');
        }

        $existing = $variant->findSectionByType('experience');
        if ($existing === null) {
            return $this->addSection($c, $a + ['type' => 'experience', 'from_master' => true, 'master_ids' => $ids]);
        }

        $content = SectionTypes::normalize('experience', $existing->getContent());
        $refs = $existing->getSourceRefs();
        $added = [];
        foreach ($experiences as $experience) {
            if (\in_array('experience:'.$experience->getId(), $refs, true)) {
                continue;
            }
            $content['items'][] = $this->builder->experienceItem($experience);
            $added[] = $experience->getTitle();
        }
        if ($added === []) {
            throw new ToolException('Those experiences are already in the CV.');
        }

        return $this->propose($c, $cv, $variant, 'Add experience: '.implode(', ', $added), [['op' => 'set_section', 'section_id' => $existing->getId(), 'content' => $content]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function rewriteBullets(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $section = $this->section($variant, $a);
        if (!SectionTypes::hasBullets($section->getType())) {
            throw new ToolException(sprintf('"%s" sections have no bullets.', $section->getType()));
        }
        $content = SectionTypes::normalize($section->getType(), $section->getContent());
        $index = (int) $a['item_index'];
        $item = $content['items'][$index] ?? throw new ToolException(sprintf('Entry %d does not exist in this section.', $index));
        if ($item['bullets'] === []) {
            throw new ToolException('This entry has no bullets to rewrite.');
        }

        $rewritten = $this->ai->completeJson(
            Prompts::bulletRewriter($variant->getLocale(), (string) ($a['style'] ?? 'concise')),
            Prompts::bulletContext($item, (string) ($a['instructions'] ?? '')),
        );
        $rewritten = array_values(array_filter($rewritten, static fn ($b) => \is_string($b) && trim($b) !== ''));
        if ($rewritten === []) {
            throw new ToolException('The AI returned no bullets.');
        }

        // Keep visibility for bullets that still exist; new extra bullets are shown
        $bullets = [];
        foreach ($rewritten as $b => $text) {
            $bullets[] = ['text' => Prompts::stripQuotes($text), 'visible' => $item['bullets'][$b]['visible'] ?? true];
        }
        $content['items'][$index]['bullets'] = $bullets;

        return $this->propose($c, $cv, $variant, sprintf('Rewrite bullets (%s): %s', $a['style'] ?? 'concise', $item['title'] ?? $item['name'] ?? '#'.$index), [['op' => 'set_section', 'section_id' => $section->getId(), 'content' => $content]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function setVisibility(ToolContext $c, array $a, bool $visible): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $section = $this->section($variant, $a);
        $content = SectionTypes::normalize($section->getType(), $section->getContent());
        if (SectionTypes::isText($section->getType())) {
            throw new ToolException('Text sections have no entries; use update_section with visible instead.');
        }
        $changed = [];
        foreach (\is_array($a['targets']) ? $a['targets'] : [] as $target) {
            $parts = explode('.', (string) $target);
            $i = (int) $parts[0];
            if (!isset($content['items'][$i])) {
                throw new ToolException(sprintf('Entry %d does not exist in "%s".', $i, $section->getTitle()));
            }
            if (\count($parts) === 1) {
                $content['items'][$i]['visible'] = $visible;
            } else {
                $b = (int) $parts[1];
                if (!isset($content['items'][$i]['bullets'][$b])) {
                    throw new ToolException(sprintf('Bullet %d.%d does not exist.', $i, $b));
                }
                $content['items'][$i]['bullets'][$b]['visible'] = $visible;
                // Showing a bullet of a hidden entry shows the entry too
                if ($visible) {
                    $content['items'][$i]['visible'] = true;
                }
            }
            $changed[] = (string) $target;
        }

        return $this->propose($c, $cv, $variant, sprintf('%s %s in "%s"', $visible ? 'Show' : 'Hide', implode(', ', $changed), $section->getTitle()), [['op' => 'set_section', 'section_id' => $section->getId(), 'content' => $content]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function tailor(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $cvData = $this->getCv($c, $a);
        $result = $this->ai->completeJson(
            Prompts::tailor($variant->getLocale(), $cv->getTemplate()->getSectionRules()),
            Prompts::tailorContext($cvData, $this->serializer->toArray($this->profile($c)), (string) $a['job_description']),
        );

        $ops = [];
        if (\is_string($result['headline'] ?? null) && trim($result['headline']) !== '') {
            $ops[] = ['op' => 'set_header', 'header' => ['headline' => trim($result['headline'])]];
        }
        if (\is_string($result['summary'] ?? null) && trim($result['summary']) !== '') {
            $ops[] = $this->summaryOperation($variant, $this->trimToLimit($cv, $result['summary']));
        }
        foreach (\is_array($result['sections'] ?? null) ? $result['sections'] : [] as $change) {
            if (!\is_array($change) || !isset($change['section_id'], $change['content']) || !\is_array($change['content'])) {
                continue;
            }
            $section = $variant->getSection((int) $change['section_id']);
            if ($section === null || $section->getType() === 'summary') {
                continue;
            }
            $ops[] = ['op' => 'set_section', 'section_id' => $section->getId(), 'content' => $change['content']];
        }
        if ($ops === []) {
            throw new ToolException('The AI found nothing to change for this job description.');
        }

        $proposal = $this->propose($c, $cv, $variant, 'Tailor to job description', $ops, \is_string($result['notes'] ?? null) ? $result['notes'] : null);
        $report = $this->checker->check($variant, (string) $a['job_description']);

        return $proposal + ['notes' => $result['notes'] ?? null, 'current_keyword_coverage' => $report['stats']['keyword_coverage'] ?? null];
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function translate(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $target = (string) $a['target_locale'];
        if (!isset(SectionTypes::LOCALES[$target])) {
            throw new ToolException('Unsupported language.');
        }
        if ($cv->getVariant($target) !== null) {
            throw new ToolException(sprintf('This CV already has a %s version.', SectionTypes::LOCALES[$target]));
        }

        $source = [
            'header' => $variant->getHeader(),
            'sections' => array_map(static fn (CvSection $s) => [
                'type' => $s->getType(), 'title' => $s->getTitle(), 'visible' => $s->isVisible(), 'region' => $s->getRegion(),
                'content' => SectionTypes::normalize($s->getType(), $s->getContent()),
            ], $variant->getOrderedSections()),
        ];
        $translated = $this->ai->completeJson(Prompts::translator($variant->getLocale(), $target), json_encode($source, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}');

        // Keep the structure from the source and take only translated text from the AI
        $sections = [];
        foreach ($source['sections'] as $i => $s) {
            $t = \is_array($translated['sections'][$i] ?? null) ? $translated['sections'][$i] : [];
            $sections[] = [
                'type' => $s['type'],
                'title' => \is_string($t['title'] ?? null) && $t['title'] !== '' ? $t['title'] : SectionTypes::defaultTitle($s['type'], $target),
                'visible' => $s['visible'],
                'region' => $s['region'],
                'content' => \is_array($t['content'] ?? null) ? $t['content'] : $s['content'],
            ];
        }
        $header = $variant->getHeader();
        foreach (['headline', 'location', 'nationality', 'workAuthorization', 'drivingLicence', 'extra'] as $field) {
            if (\is_string($translated['header'][$field] ?? null)) {
                $header[$field] = $translated['header'][$field];
            }
        }

        return $this->propose($c, $cv, $variant, sprintf('Translate to %s', SectionTypes::LOCALES[$target]), [[
            'op' => 'create_variant', 'locale' => $target, 'header' => $header, 'sections' => $sections,
        ]]);
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private function switchTemplate(ToolContext $c, array $a): array
    {
        [$cv, $variant] = $this->target($c, $a);
        $template = $this->templates->find((int) $a['template_id']);
        if ($template === null || !$this->templates->isAvailableFor($template, $c->user)) {
            throw new ToolException('Unknown template. Call list_templates.');
        }
        $unsupported = array_values(array_unique(array_filter(
            array_map(static fn (CvSection $s) => $s->getType(), $variant->getOrderedSections()),
            static fn (string $type) => !$template->supportsSection($type),
        )));
        $result = $this->propose($c, $cv, $variant, sprintf('Switch template to %s', $template->getName()), [['op' => 'set_template', 'template_id' => $template->getId()]]);
        if ($unsupported !== []) {
            $result['warning'] = sprintf('%s does not display these section types: %s. They are kept but hidden from the output.', $template->getName(), implode(', ', $unsupported));
        }

        return $result;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<array<string, mixed>> $ops
     *
     * @return array<string, mixed>
     */
    private function propose(ToolContext $c, Cv $cv, CvVariant $variant, string $title, array $ops, ?string $description = null): array
    {
        $proposal = $this->proposalManager->propose($cv, $variant, $title, $ops, $c->source, $c->conversation, $description);
        $c->proposalIds[] = (int) $proposal->getId();

        return [
            'proposal_id' => $proposal->getId(),
            'title' => $proposal->getTitle(),
            'status' => AiProposal::STATUS_PENDING,
            'message' => 'Proposed, not applied yet. The user reviews the change and clicks Apply in the CVolve editor.',
            'review_url' => $this->urls->generate('cv_editor', ['id' => $cv->getId(), 'locale' => $variant->getLocale()], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }

    /**
     * Resolve the CV and language variant a call targets, checking ownership.
     *
     * @param array<string, mixed> $a
     *
     * @return array{Cv, CvVariant}
     */
    private function target(ToolContext $c, array $a): array
    {
        $cv = isset($a['cv_id']) ? $this->cvs->find((int) $a['cv_id']) : $c->cv;
        if ($cv === null) {
            throw new ToolException(isset($a['cv_id']) ? 'CV not found.' : 'Pass cv_id (see list_cvs).');
        }
        if ($cv->getOwner() !== $c->user) {
            throw new ToolException('CV not found.');
        }
        $locale = isset($a['locale']) && \is_string($a['locale']) && $a['locale'] !== '' ? $a['locale'] : null;
        if ($locale !== null) {
            $variant = $cv->getVariant($locale) ?? throw new ToolException(sprintf('This CV has no "%s" version. Available: %s.', $locale, implode(', ', $cv->getLocales())));
        } else {
            // In the editor chat, default to the language version the user is looking at
            $variant = ($c->cv === $cv && $c->locale !== null ? $cv->getVariant($c->locale) : null) ?? $cv->getDefaultVariant();
        }

        return [$cv, $variant];
    }

    /**
     * @param array<string, mixed> $a
     */
    private function section(CvVariant $variant, array $a): CvSection
    {
        $section = $variant->getSection((int) ($a['section_id'] ?? 0));
        if ($section === null) {
            throw new ToolException('Section not found. Call get_cv to see section ids.');
        }

        return $section;
    }

    private function profile(ToolContext $c): \App\Entity\Profile\MasterProfile
    {
        return $c->user->getMasterProfile() ?? throw new ToolException('The user has no master profile yet.');
    }

    /**
     * Accept a JSON string (what LLM tool calls send) or an already-decoded object (MCP clients).
     *
     * @return array<mixed>|null
     */
    private function json(mixed $value, string $name): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        if (!\is_array($decoded)) {
            throw new ToolException(sprintf('%s must be a JSON object.', $name));
        }

        return $decoded;
    }

    private function maxLength(Cv $cv, string $type): int
    {
        return (int) ($cv->getTemplate()->getSectionRules()[$type]['maxLength'] ?? 900);
    }

    private function trimToLimit(Cv $cv, string $text): string
    {
        $max = $this->maxLength($cv, 'summary');
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        // Cut at the last sentence end that fits
        $cut = mb_substr($text, 0, $max);
        $end = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '.'));

        return $end > $max / 2 ? mb_substr($cut, 0, $end + 1) : rtrim($cut).'…';
    }
}
