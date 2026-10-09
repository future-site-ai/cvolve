<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\CvRepository;
use App\Service\Cv\CvRenderer;
use App\Service\Export\ExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public, read-only CV links (/s/<token>) that the owner can switch on and off.
 */
class PublicShareController extends AbstractController
{
    #[Route('/s/{token}', name: 'share_view', requirements: ['token' => '[a-f0-9]{32}'])]
    public function view(string $token, Request $request, CvRepository $cvs, CvRenderer $renderer): Response
    {
        $cv = $cvs->findOneBy(['shareToken' => $token]);
        if ($cv === null || !$cv->isShareEnabled()) {
            throw $this->createNotFoundException();
        }
        $variant = $cv->getVariant($request->query->getString('lang') ?: null) ?? $cv->getDefaultVariant();

        return $this->render('share/view.html.twig', ['cv' => $cv, 'variant' => $variant, 'token' => $token], new Response(headers: ['X-Robots-Tag' => 'noindex']));
    }

    #[Route('/s/{token}/document', name: 'share_document', requirements: ['token' => '[a-f0-9]{32}'])]
    public function document(string $token, Request $request, CvRepository $cvs, CvRenderer $renderer): Response
    {
        $cv = $cvs->findOneBy(['shareToken' => $token]);
        if ($cv === null || !$cv->isShareEnabled()) {
            throw $this->createNotFoundException();
        }
        $variant = $cv->getVariant($request->query->getString('lang') ?: null) ?? $cv->getDefaultVariant();

        return new Response($renderer->render($variant), 200, ['Content-Security-Policy' => CvController::PREVIEW_CSP, 'X-Frame-Options' => 'SAMEORIGIN', 'X-Robots-Tag' => 'noindex']);
    }

    #[Route('/s/{token}.pdf', name: 'share_pdf', requirements: ['token' => '[a-f0-9]{32}'])]
    public function pdf(string $token, Request $request, CvRepository $cvs, ExportService $exports): Response
    {
        $cv = $cvs->findOneBy(['shareToken' => $token]);
        if ($cv === null || !$cv->isShareEnabled()) {
            throw $this->createNotFoundException();
        }
        $variant = $cv->getVariant($request->query->getString('lang') ?: null) ?? $cv->getDefaultVariant();
        $name = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($cv->getTitle())), '-') ?: 'cv';

        return new Response($exports->pdf($variant), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', $name),
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
