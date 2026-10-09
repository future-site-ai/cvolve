<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ExportCvMessage;
use App\Repository\ExportJobRepository;
use App\Service\Export\ExportService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ExportCvHandler
{
    public function __construct(
        private readonly ExportJobRepository $jobs,
        private readonly ExportService $exports,
    ) {
    }

    public function __invoke(ExportCvMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);
        if ($job !== null && !$job->isFinished()) {
            $this->exports->run($job);
        }
    }
}
