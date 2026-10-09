<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class ChatMessage
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ChatConversation $conversation;

    #[ORM\Column(length: 20)]
    private string $role;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** @var list<array<string, mixed>> tools the assistant called while answering */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $toolCalls = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<array<string, mixed>> $toolCalls */
    public function __construct(ChatConversation $conversation, string $role, string $content, array $toolCalls = [])
    {
        $this->conversation = $conversation;
        $this->role = $role;
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->createdAt = new \DateTimeImmutable();
        $conversation->addMessage($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getConversation(): ChatConversation { return $this->conversation; }
    public function getRole(): string { return $this->role; }
    public function getContent(): string { return $this->content; }
    /** @return list<array<string, mixed>> */
    public function getToolCalls(): array { return $this->toolCalls; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
