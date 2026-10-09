<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ExportJob;
use App\Security\OwnerVoter;
use App\Service\Export\ExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/exports')]
class ExportController extends AbstractController
{
    #[Route('/{id}', name: 'export_show', requirements: ['id' => '\d+'])]
    public function show(ExportJob $job, Request $request): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $job);
        if ($request->query->getBoolean('status')) {
            return new JsonResponse(['status' => $job->getStatus(), 'finished' => $job->isFinished(), 'error' => $job->getError()]);
        }

        return $this->render('cv/export.html.twig', ['job' => $job]);
    }

    #[Route('/{id}/download', name: 'export_download', requirements: ['id' => '\d+'])]
    public function download(ExportJob $job, ExportService $exports): Response
    {
        $this->denyAccessUnlessGranted(OwnerVoter::VIEW, $job);
        $path = $exports->filePath($job);
        if ($path === null) {
            throw $this->createNotFoundException('The file is not ready (or has expired). Export again.');
        }

        return $this->file($path, $job->getDownloadName(), ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }
}
