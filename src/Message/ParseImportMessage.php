<?php

declare(strict_types=1);

namespace App\Message;

final class ParseImportMessage
{
    public function __construct(public readonly int $jobId)
    {
    }
}
