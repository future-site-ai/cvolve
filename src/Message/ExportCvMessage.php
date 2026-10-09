<?php

declare(strict_types=1);

namespace App\Message;

final class ExportCvMessage
{
    public function __construct(public readonly int $jobId)
    {
    }
}
