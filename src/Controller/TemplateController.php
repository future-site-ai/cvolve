<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Cv;
use App\Entity\Template;
use App\Entity\User;
use App\Form\TemplateImportFormType;
use App\Repository\TemplateRepository;
use App\Security\TemplateVoter;
use App\Service\Cv\CvRenderer;
use Cvolve\TemplateKit\Import\ConversionException;
use App\Service\Template\TemplateInstaller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/templates')]
class TemplateController extends AbstractController
{
    public const FORMATS = [
        'cvolve' => '.cvolve package',
        'mustache-html' => 'HTML with Mustache placeholders',
        'twig-html' => 'Twig HTML',
        'json-resume' => 'JSON Resume theme',
        'raw-html' => 'static HTML converted by AI',
    ];

    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly TemplateInstaller $installer,
    ) {
    }

    #[Route('', name: 'template_gallery')]
    public function gallery(): Response
    {
        return $this->render('template/gallery.html.twig', ['templates' => $this->templates->findAvailableFor($this->user())]);
    }

    #[Route('/import', name: 'template_import')]
    public function import(Request $request): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $form = $this->createForm(TemplateImportFormType::class, null, ['allow_preset' => $isAdmin]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('package')->getData();
            $asPreset = $isAdmin && $form->has('scope') && $form->get('scope')->getData() === 'preset';
            try {
                $result = $this->installer->importFile($file->getPathname(), (string) $file->getClientOriginalName(), $asPreset ? null : $this->user());
                $template = $result['template'];
                $this->addFlash('success', sprintf('Template "%s" %s installed (%s).', $template->getName(), $asPreset ? 'is now a preset for everyone' : 'was', self::FORMATS[$result['format']] ?? $result['format']));
                foreach ($result['warnings'] as $warning) {
                    $this->addFlash('warning', $warning);
                }

                return $this->redirectToRoute($asPreset ? 'admin_templates' : 'template_gallery');
            } catch (ConversionException $e) {
                return $this->render('template/import.html.twig', ['form' => $form, 'errors' => $e->errors, 'warnings' => $e->warnings], new Response(status: 422));
            } catch (\Throwable $e) {
                return $this->render('template/import.html.twig', ['form' => $form, 'errors' => [$e->getMessage()], 'warnings' => []], new Response(status: 422));
            }
        }

        return $this->render('template/import.html.twig', ['form' => $form, 'errors' => [], 'warnings' => []], new Response(status: $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/preview', name: 'template_preview', requirements: ['id' => '\d+'])]
    public function preview(Template $template, CvRenderer $renderer): Response
    {
        $this->denyAccessUnlessGranted(TemplateVoter::USE, $template);

        return new Response($renderer->renderSample($template), 200, ['Content-Security-Policy' => CvController::PREVIEW_CSP, 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    #[Route('/{id}/thumbnail', name: 'template_thumbnail', requirements: ['id' => '\d+'])]
    public function thumbnail(Template $template): Response
    {
        $this->denyAccessUnlessGranted(TemplateVoter::USE, $template);
        $path = $this->installer->thumbnailPath($template);
        if ($path === null) {
            throw $this->createNotFoundException();
        }
        $response = new BinaryFileResponse($path);
        $response->setPublic()->setMaxAge(3600);

        return $response;
    }

    #[Route('/{id}/export', name: 'template_export', requirements: ['id' => '\d+'])]
    public function export(Template $template): Response
    {
        $this->denyAccessUnlessGranted(TemplateVoter::USE, $template);
        $response = new BinaryFileResponse($this->installer->export($template));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('%s-%s.cvolve', $template->getSlug(), $template->getVersion()));
        $response->headers->set('Content-Type', 'application/zip');
        $response->deleteFileAfterSend();

        return $response;
    }

    #[Route('/{id}/delete', name: 'template_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Template $template, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted(TemplateVoter::MANAGE, $template);
        if (!$this->isCsrfTokenValid('template-delete-'.$template->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $inUse = $em->getRepository(Cv::class)->count(['template' => $template]);
        if ($inUse > 0) {
            $this->addFlash('error', sprintf('"%s" is used by %d CV(s). Switch them to another template first.', $template->getName(), $inUse));
        } else {
            $em->remove($template);
            $em->flush();
            $this->addFlash('success', 'Template deleted.');
        }

        return $this->redirectToRoute($template->isPreset() ? 'admin_templates' : 'template_gallery');
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
