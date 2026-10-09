<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\CvVariant;
use App\Entity\ExportJob;
use App\Message\ExportCvMessage;
use App\Service\Cv\CvRenderer;
use App\Service\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class ExportService
{
    public function __construct(
        private readonly CvRenderer $renderer,
        private readonly GotenbergClient $gotenberg,
        private readonly DocxExporter $docx,
        private readonly Storage $storage,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    /**
     * Queue an export; the messenger worker produces the file.
     */
    public function request(CvVariant $variant, string $format): ExportJob
    {
        $job = new ExportJob($variant, $format);
        $this->em->persist($job);
        $this->em->flush();
        $this->bus->dispatch(new ExportCvMessage((int) $job->getId()));

        return $job;
    }

    public function run(ExportJob $job): void
    {
        $job->start();
        $this->em->flush();

        try {
            $contents = $job->getFormat() === 'pdf' ? $this->pdf($job->getVariant()) : $this->docx->export($job->getVariant());
            $relative = sprintf('exports/%d-%s.%s', $job->getId(), bin2hex(random_bytes(4)), $job->getFormat());
            $this->storage->write($relative, $contents);
            $job->complete($relative);
        } catch (\Throwable $e) {
            $job->fail($e->getMessage());
        }
        $this->em->flush();
    }

    public function pdf(CvVariant $variant): string
    {
        return $this->gotenberg->htmlToPdf($this->renderer->render($variant, CvRenderer::MODE_PRINT));
    }

    /**
     * Exact page count from a real render, or null when Gotenberg is unreachable.
     */
    public function countPages(CvVariant $variant): ?int
    {
        try {
            return GotenbergClient::countPages($this->pdf($variant));
        } catch (\Throwable) {
            return null;
        }
    }

    public function filePath(ExportJob $job): ?string
    {
        $relative = $job->getFilePath();

        return $relative !== null && $this->storage->exists($relative) ? $this->storage->path($relative) : null;
    }
}
