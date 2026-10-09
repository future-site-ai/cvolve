<?php

declare(strict_types=1);

namespace App\Service\Ai;

final class AiUnavailableException extends \RuntimeException
{
    public function __construct(string $message = 'No AI provider is configured. Set AI_PROVIDER and an API key in .env.local (see README).')
    {
        parent::__construct($message);
    }
}
