<?php

declare(strict_types=1);

namespace App\Service\Tool;

use App\Entity\ChatConversation;
use App\Entity\Cv;
use App\Entity\User;

/**
 * Who is calling a tool, from where, and (in the chat) which CV is open.
 */
final class ToolContext
{
    /** @var list<int> proposals created during this call or chat turn */
    public array $proposalIds = [];

    /** @var list<array{tool: string, ok: bool}> */
    public array $calls = [];

    public function __construct(
        public readonly User $user,
        public readonly string $source,
        public readonly ?Cv $cv = null,
        public readonly ?ChatConversation $conversation = null,
        public readonly ?string $locale = null,
    ) {
    }
}
