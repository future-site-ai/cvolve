<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ParseImportMessage;
use App\Repository\ImportJobRepository;
use App\Service\Import\ProfileImporter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ParseImportHandler
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly ProfileImporter $importer,
    ) {
    }

    public function __invoke(ParseImportMessage $message): void
    {
        $job = $this->jobs->find($message->jobId);
        if ($job !== null && $job->isBusy()) {
            $this->importer->parse($job);
        }
    }
}
