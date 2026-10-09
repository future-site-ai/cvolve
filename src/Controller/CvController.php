<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AiProposal;
use App\Entity\Cv;
use App\Entity\CvSection;
use App\Entity\CvVariant;
use App\Entity\CvVersion;
use App\Entity\Profile\Experience;
use App\Entity\Profile\ProfileSummary;
use App\Entity\Template;
use App\Entity\User;
use App\Form\Cv\CvNewType;
use App\Form\Cv\CvSettingsType;
use App\Form\Cv\HeaderType;
use App\Form\Cv\SectionContentType;
use App\Repository\AiProposalRepository;
use App\Repository\ChatConversationRepository;
use App\Repository\CvVersionRepository;
use App\Repository\TemplateRepository;
use App\Security\OwnerVoter;
use App\Service\Cv\CvBuilder;
use App\Service\Cv\CvChecker;
use App\Service\Cv\CvRenderer;
use App\Service\Cv\ProposalManager;
use Cvolve\TemplateKit\SectionTypes;
use Cvolve\TemplateKit\Util\TextDiff;
use App\Service\Cv\VersionManager;
use App\Service\Export\ExportService;
use App\Service\Tool\CvToolbox;
use App\Service\Tool\ToolContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/cvs')]
class CvController extends AbstractController
{
    /** Sandboxed preview: inline styles and data: images/fonts only, no scripts. */
    public const PREVIEW_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:; frame-ancestors 'self'; base-uri 'none'; form-action 'none'";

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TemplateRepository $templates,
    ) {
    }

    #[Route('/new', name: 'cv_new')]
    public function new(Request $request, CvBuilder $builder): Response
    {
        $user = $this->user();
        $available = $this->templates->findAvailableFor($user);
        if ($available === []) {
            $this->addFlash('error', 'No templates are installed yet. An administrator needs to load the presets (bin/console app:templates:install).');

            return $this->redirectToRoute('app_dashboard');
        }
        $preselected = $request->query->getInt('template');
        $form = $this->createForm(CvNewType::class, [
            'template' => array_values(array_filter($available, static fn (Template $t) => $t->getId() === $preselected))[0] ?? $available[0],
        ], ['templates' => $available, 'profile' => $user->getMasterProfile()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            /** @var ProfileSummary|null $summary */
            $summary = $data['summary'] ?? null;
            $experiences = $data['experiences'] ?? null;
            $cv = $builder->create($user, $data['title'], $data['template'], $data['locale'], [
                'summary_id' => $summary?->getId(),
                'experience_ids' => $experiences !== null ? array_map(static fn (Experience $e) => (int) $e->getId(), \is_array($experiences) ? $experiences : iterator_to_array($experiences)) : null,
                'section_types' => $data['sections'],
            ]);
            $this->em->persist($cv);
            $this->em->flush();

            return $this->redirectToRoute('cv_editor', ['id' => $cv->getId()]);
        }

        return $this->render('cv/new.html.twig', ['form' => $form, 'profile' => $user->getMasterProfile()], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}', name: 'cv_editor', requirements: ['id' => '\d+'])]
    public function editor(Cv $cv, Request $request, ChatConversationRepository $conversations, AiProposalRepository $proposals, ProposalManager $proposalManager, \App\Service\Ai\ChatAgent $agent): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $variant = $this->variant($cv, $request);
        $conversation = $conversations->findOneBy(['cv' => $cv], ['id' => 'DESC']);
        $pending = $proposals->findBy(['cv' => $cv, 'status' => AiProposal::STATUS_PENDING], ['id' => 'ASC']);

        return $this->render('cv/editor.html.twig', [
            'cv' => $cv,
            'variant' => $variant,
            'conversation' => $conversation,
            'pending' => $pending,
            'previews' => array_combine(array_map(static fn (AiProposal $p) => (int) $p->getId(), $pending), array_map(static fn (AiProposal $p) => $proposalManager->preview($p), $pending)),
            'ai_available' => $agent->isAvailable(),
            'settings_form' => $this->createForm(CvSettingsType::class, $cv, ['templates' => $this->templates->findAvailableFor($this->user()), 'action' => $this->generateUrl('cv_settings', ['id' => $cv->getId()])]),
            'locales' => SectionTypes::LOCALES,
            'section_types' => SectionTypes::TYPES,
        ]);
    }

    #[Route('/{id}/sections', name: 'cv_sections', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function sections(Cv $cv, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);

        return $this->render('cv/_sections_frame.html.twig', [
            'cv' => $cv,
            'variant' => $this->variant($cv, $request),
            'section_types' => SectionTypes::TYPES,
        ]);
    }

    #[Route('/{id}/settings', name: 'cv_settings', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function settings(Cv $cv, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $form = $this->createForm(CvSettingsType::class, $cv, ['templates' => $this->templates->findAvailableFor($this->user())]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $cv->touch();
            $this->em->flush();
            $this->addFlash('success', 'CV settings saved.');
        } else {
            $this->addFlash('error', 'Could not save the settings: '.implode(' ', array_map(static fn ($e) => $e->getMessage(), iterator_to_array($form->getErrors(true)))));
        }

        return $this->redirectToRoute('cv_editor', ['id' => $cv->getId()]);
    }

    #[Route('/{id}/delete', name: 'cv_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Cv $cv, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-delete-'.$cv->getId(), $request);
        $this->em->remove($cv);
        $this->em->flush();
        $this->addFlash('success', sprintf('"%s" was deleted.', $cv->getTitle()));

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/{id}/duplicate', name: 'cv_duplicate', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function duplicate(Cv $cv, Request $request, VersionManager $versions): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $this->checkCsrf('cv-duplicate-'.$cv->getId(), $request);
        $copy = new Cv($this->user(), mb_substr($cv->getTitle().' (copy)', 0, 150), $cv->getTemplate(), $cv->getDefaultLocale());
        foreach ($cv->getVariants() as $variant) {
            $versions->load(new CvVariant($copy, $variant->getLocale()), $versions->snapshot($variant));
        }
        $this->em->persist($copy);
        $this->em->flush();

        return $this->redirectToRoute('cv_editor', ['id' => $copy->getId()]);
    }

    #[Route('/{id}/preview', name: 'cv_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(Cv $cv, Request $request, CvRenderer $renderer): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);

        try {
            $html = $renderer->render($this->variant($cv, $request));
        } catch (\Throwable $e) {
            $html = '<!DOCTYPE html><meta charset="utf-8"><body style="font:14px system-ui;padding:24px;color:#b91c1c">The template could not render this CV: '.htmlspecialchars($e->getMessage()).'</body>';
        }

        return new Response($html, 200, ['Content-Security-Policy' => self::PREVIEW_CSP, 'X-Frame-Options' => 'SAMEORIGIN', 'Cache-Control' => 'no-store']);
    }

    #[Route('/{id}/preview.pdf', name: 'cv_preview_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function previewPdf(Cv $cv, Request $request, ExportService $exports): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);

        try {
            $pdf = $exports->pdf($this->variant($cv, $request));
        } catch (\Throwable $e) {
            return new Response('PDF rendering is unavailable: '.$e->getMessage(), 503, ['Content-Type' => 'text/plain']);
        }

        return new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"', 'Cache-Control' => 'no-store']);
    }

    // ------------------------------------------------------------------ header & sections

    #[Route('/{id}/header', name: 'cv_header_edit', requirements: ['id' => '\d+'])]
    public function editHeader(Cv $cv, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $variant = $this->variant($cv, $request);
        $data = $variant->getHeader();
        $data['showPhoto'] = (bool) ($data['showPhoto'] ?? false);
        foreach (array_keys(SectionTypes::HEADER_FIELDS) as $field) {
            $data[$field] = (string) ($data[$field] ?? '');
        }
        $form = $this->createForm(HeaderType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variant->setHeader(array_map(static fn ($v) => \is_string($v) ? trim($v) : $v, $form->getData()));
            $variant->touch();
            $this->em->flush();

            return $this->redirectToRoute('cv_sections', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
        }

        return $this->render('cv/_header_form.html.twig', ['cv' => $cv, 'variant' => $variant, 'form' => $form, 'wrap_panel' => $form->isSubmitted()], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/sections/{sectionId}/edit', name: 'cv_section_edit', requirements: ['id' => '\d+', 'sectionId' => '\d+'])]
    public function editSection(Cv $cv, int $sectionId, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $variant = $this->variant($cv, $request);
        $section = $variant->getSection($sectionId) ?? throw $this->createNotFoundException();
        $type = $section->getType();
        $template = $cv->getTemplate();
        $rules = $template->getSectionRules()[$type] ?? [];

        $data = ['title' => $section->getTitle(), 'region' => $section->getRegion() ?? $template->getDefaultRegion($type)] + SectionTypes::normalize($type, $section->getContent());
        $form = $this->createForm(SectionContentType::class, $data, [
            'section_type' => $type,
            'regions' => $template->getRegions(),
            'max_length' => $rules['maxLength'] ?? null,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $form->getData();
            $section->setTitle((string) $submitted['title']);
            if (isset($submitted['region'])) {
                $section->setRegion($submitted['region']);
            }
            $content = SectionTypes::normalize($type, self::sortByPosition($submitted));
            $section->setContent($content);
            $variant->touch();
            $this->em->flush();
            foreach (SectionTypes::validate($type, $content, $rules) as $warning) {
                $this->addFlash('warning', $warning);
            }

            return $this->redirectToRoute('cv_sections', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
        }

        return $this->render('cv/_section_form.html.twig', [
            'cv' => $cv, 'variant' => $variant, 'section' => $section, 'form' => $form, 'rules' => $rules, 'wrap_panel' => $form->isSubmitted(),
        ], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/sections/add', name: 'cv_section_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addSection(Cv $cv, Request $request, CvBuilder $builder): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-sections-'.$cv->getId(), $request);
        $variant = $this->variant($cv, $request);
        $type = (string) $request->request->get('type');
        if (!SectionTypes::exists($type) || !$cv->getTemplate()->supportsSection($type)) {
            $this->addFlash('error', 'This template does not support that section type.');

            return $this->redirectToRoute('cv_sections', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
        }

        $sections = [];
        if ($request->request->get('source') === 'master' && ($profile = $this->user()->getMasterProfile()) !== null) {
            $sections = $builder->sectionsFromMaster($type, $profile, $cv->getTemplate(), $variant->getLocale());
        }
        if ($sections === []) {
            $section = new CvSection($type, SectionTypes::defaultTitle($type, $variant->getLocale()), SectionTypes::emptyContent($type));
            $section->setRegion($cv->getTemplate()->getDefaultRegion($type));
            $sections = [$section];
        }
        foreach ($sections as $section) {
            $variant->addSection($section);
        }
        $variant->touch();
        $this->em->flush();

        return $this->redirectToRoute('cv_sections', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
    }

    #[Route('/{id}/sections/{sectionId}/{action}', name: 'cv_section_action', requirements: ['id' => '\d+', 'sectionId' => '\d+', 'action' => 'up|down|toggle|delete'], methods: ['POST'])]
    public function sectionAction(Cv $cv, int $sectionId, string $action, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-sections-'.$cv->getId(), $request);
        $variant = $this->variant($cv, $request);
        $section = $variant->getSection($sectionId) ?? throw $this->createNotFoundException();

        switch ($action) {
            case 'toggle':
                $section->setVisible(!$section->isVisible());
                break;
            case 'delete':
                $variant->removeSection($section);
                break;
            default:
                $ordered = $variant->getOrderedSections();
                $index = array_search($section, $ordered, true);
                $swap = $action === 'up' ? $index - 1 : $index + 1;
                if (isset($ordered[$swap])) {
                    [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
                    foreach ($ordered as $i => $s) {
                        $s->setPosition($i);
                    }
                }
        }
        $variant->touch();
        $this->em->flush();

        return $this->redirectToRoute('cv_sections', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
    }

    // ------------------------------------------------------------------ language versions

    #[Route('/{id}/variants', name: 'cv_variant_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function addVariant(Cv $cv, Request $request, VersionManager $versions, CvToolbox $toolbox, CvBuilder $builder): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-variants-'.$cv->getId(), $request);
        $locale = (string) $request->request->get('new_locale');
        $mode = (string) $request->request->get('mode', 'copy');
        $source = $this->variant($cv, $request);

        if (!isset(SectionTypes::LOCALES[$locale]) || $cv->getVariant($locale) !== null) {
            $this->addFlash('error', 'Choose a language this CV does not have yet.');

            return $this->redirectToRoute('cv_editor', ['id' => $cv->getId()]);
        }

        if ($mode === 'translate') {
            $result = $toolbox->call('translate_cv', ['cv_id' => $cv->getId(), 'locale' => $source->getLocale(), 'target_locale' => $locale], new ToolContext($this->user(), 'web', $cv));
            if (isset($result['error'])) {
                $this->addFlash('error', 'Translation failed: '.$result['error']);
            } else {
                $this->addFlash('success', sprintf('The %s translation is ready for review in the AI panel. Apply it to create the new version.', SectionTypes::LOCALES[$locale]));
            }

            return $this->redirectToRoute('cv_editor', ['id' => $cv->getId(), 'locale' => $source->getLocale()]);
        }

        $variant = new CvVariant($cv, $locale);
        $this->em->persist($variant);
        if ($mode === 'master') {
            $fresh = $builder->create($this->user(), $cv->getTitle(), $cv->getTemplate(), $locale);
            $versions->load($variant, $versions->snapshot($fresh->getDefaultVariant()));
        } else {
            $snapshot = $versions->snapshot($source);
            foreach ($snapshot['sections'] as &$section) {
                // Keep custom headings, but use the new language's standard headings for standard sections
                if ($section['title'] === SectionTypes::defaultTitle($section['type'], $source->getLocale())) {
                    $section['title'] = SectionTypes::defaultTitle($section['type'], $locale);
                }
            }
            unset($section);
            $versions->load($variant, $snapshot);
        }
        $this->em->flush();
        $this->addFlash('success', sprintf('%s version created.', SectionTypes::LOCALES[$locale]));

        return $this->redirectToRoute('cv_editor', ['id' => $cv->getId(), 'locale' => $locale]);
    }

    #[Route('/{id}/variants/{locale}/delete', name: 'cv_variant_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteVariant(Cv $cv, string $locale, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-variants-'.$cv->getId(), $request);
        $variant = $cv->getVariant($locale);
        if ($variant === null || \count($cv->getVariants()) < 2) {
            $this->addFlash('error', 'A CV needs at least one language version.');
        } else {
            $cv->removeVariant($variant);
            if ($cv->getDefaultLocale() === $locale) {
                $cv->setDefaultLocale($cv->getLocales()[0]);
            }
            $this->em->flush();
            $this->addFlash('success', 'Language version deleted.');
        }

        return $this->redirectToRoute('cv_editor', ['id' => $cv->getId()]);
    }

    // ------------------------------------------------------------------ export, share, check

    #[Route('/{id}/export/{format}', name: 'cv_export', requirements: ['id' => '\d+', 'format' => 'pdf|docx'], methods: ['POST'])]
    public function export(Cv $cv, string $format, Request $request, ExportService $exports): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $this->checkCsrf('cv-export-'.$cv->getId(), $request);
        $job = $exports->request($this->variant($cv, $request), $format);

        return $this->redirectToRoute('export_show', ['id' => $job->getId()]);
    }

    #[Route('/{id}/share', name: 'cv_share', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function share(Cv $cv, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-share-'.$cv->getId(), $request);
        match ((string) $request->request->get('action')) {
            'enable' => $cv->enableSharing(),
            'disable' => $cv->disableSharing(),
            'regenerate' => $cv->regenerateShareToken(),
            default => null,
        };
        $this->em->flush();
        $this->addFlash('success', $cv->isShareEnabled() ? 'Public link is on. Anyone with the link can view this CV.' : 'Public link is off.');

        return $this->redirectToRoute('cv_editor', ['id' => $cv->getId()]);
    }

    #[Route('/{id}/check', name: 'cv_check', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function check(Cv $cv, Request $request, CvChecker $checker, ExportService $exports): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $variant = $this->variant($cv, $request);
        $jobDescription = trim((string) $request->request->get('job_description', ''));
        $pages = $request->isMethod('POST') && $request->request->getBoolean('measure') ? $exports->countPages($variant) : null;

        return $this->render('cv/_check.html.twig', [
            'cv' => $cv,
            'variant' => $variant,
            'report' => $checker->check($variant, $jobDescription !== '' ? $jobDescription : null, $pages),
            'job_description' => $jobDescription,
        ]);
    }

    // ------------------------------------------------------------------ versions

    #[Route('/{id}/versions', name: 'cv_versions', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function versions(Cv $cv, Request $request, CvVersionRepository $versions): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $variant = $this->variant($cv, $request);

        return $this->render('cv/versions.html.twig', [
            'cv' => $cv,
            'variant' => $variant,
            'versions' => $versions->findBy(['cv' => $cv, 'locale' => $variant->getLocale()], ['id' => 'DESC']),
        ]);
    }

    #[Route('/{id}/versions', name: 'cv_version_save', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saveVersion(Cv $cv, Request $request, VersionManager $versions): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-versions-'.$cv->getId(), $request);
        $variant = $this->variant($cv, $request);
        $label = trim((string) $request->request->get('label')) ?: 'Saved on '.(new \DateTimeImmutable())->format('d M Y H:i');
        $versions->record($variant, $label, CvVersion::REASON_MANUAL);
        $this->em->flush();
        $this->addFlash('success', 'Version saved.');

        return $this->redirectToRoute('cv_versions', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
    }

    #[Route('/{id}/versions/{versionId}', name: 'cv_version_show', requirements: ['id' => '\d+', 'versionId' => '\d+'], methods: ['GET'])]
    public function showVersion(Cv $cv, int $versionId, Request $request, CvVersionRepository $versions, VersionManager $manager): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $cv);
        $version = $versions->findOneBy(['id' => $versionId, 'cv' => $cv]) ?? throw $this->createNotFoundException();
        $compareId = $request->query->getInt('compare');
        $other = $compareId > 0 ? $versions->findOneBy(['id' => $compareId, 'cv' => $cv]) : null;
        $current = $cv->getVariant($version->getLocale());

        $before = VersionManager::lines($version->getSnapshot());
        $after = $other !== null ? VersionManager::lines($other->getSnapshot()) : ($current !== null ? VersionManager::lines($manager->snapshot($current)) : []);

        return $this->render('cv/version_show.html.twig', [
            'cv' => $cv,
            'version' => $version,
            'other' => $other,
            'diff' => TextDiff::lines($before, $after),
            'all_versions' => $versions->findBy(['cv' => $cv, 'locale' => $version->getLocale()], ['id' => 'DESC']),
        ]);
    }

    #[Route('/{id}/versions/{versionId}/restore', name: 'cv_version_restore', requirements: ['id' => '\d+', 'versionId' => '\d+'], methods: ['POST'])]
    public function restoreVersion(Cv $cv, int $versionId, Request $request, CvVersionRepository $versions, VersionManager $manager): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::EDIT, $cv);
        $this->checkCsrf('cv-versions-'.$cv->getId(), $request);
        $version = $versions->findOneBy(['id' => $versionId, 'cv' => $cv]) ?? throw $this->createNotFoundException();
        $variant = $manager->restore($version);
        $this->em->flush();
        $this->addFlash('success', sprintf('Restored "%s". The previous state was saved as a version.', $version->getLabel()));

        return $this->redirectToRoute('cv_editor', ['id' => $cv->getId(), 'locale' => $variant->getLocale()]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Apply the hidden position fields (set by the sortable collections) and drop them.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function sortByPosition(array $data): array
    {
        $sort = static function (array $rows): array {
            $rows = array_values($rows);
            usort($rows, static fn (array $a, array $b) => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

            return array_map(static function (array $row) {
                unset($row['position']);

                return $row;
            }, $rows);
        };
        if (isset($data['items']) && \is_array($data['items'])) {
            $data['items'] = $sort($data['items']);
            foreach ($data['items'] as &$item) {
                if (isset($item['bullets']) && \is_array($item['bullets'])) {
                    $item['bullets'] = $sort($item['bullets']);
                }
            }
            unset($item);
        }
        unset($data['title'], $data['region']);

        return $data;
    }

    private function variant(Cv $cv, Request $request): CvVariant
    {
        $locale = $request->query->getString('locale') ?: $request->request->getString('locale');

        return ($locale !== '' ? $cv->getVariant($locale) : null) ?? $cv->getDefaultVariant();
    }

    private function checkCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token. Reload the page and try again.');
        }
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
