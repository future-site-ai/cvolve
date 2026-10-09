<?php

declare(strict_types=1);

namespace App\Service\Cv;

final class ProposalException extends \RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
