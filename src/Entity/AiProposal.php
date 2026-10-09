<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A change suggested by the AI (chat, MCP or API). Nothing touches the CV until the
 * user applies it; applying takes a version snapshot first.
 */
#[ORM\Entity(repositoryClass: AiProposalRepository::class)]
class AiProposal implements OwnedInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_DISCARDED = 'discarded';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cv $cv;

    /** The language variant the operations target. */
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CvVariant $variant;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ChatConversation $conversation = null;

    #[ORM\Column(length: 200)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $operations;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 20)]
    private string $source;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    /** @param list<array<string, mixed>> $operations */
    public function __construct(Cv $cv, ?CvVariant $variant, string $title, array $operations, string $source)
    {
        $this->cv = $cv;
        $this->variant = $variant;
        $this->title = mb_substr($title, 0, 200);
        $this->operations = $operations;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCv(): Cv { return $this->cv; }
    public function getOwner(): User { return $this->cv->getOwner(); }
    public function getVariant(): ?CvVariant { return $this->variant; }
    public function getConversation(): ?ChatConversation { return $this->conversation; }
    public function setConversation(?ChatConversation $c): static { $this->conversation = $c; return $this; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $d): static { $this->description = $d; return $this; }
    /** @return list<array<string, mixed>> */
    public function getOperations(): array { return $this->operations; }
    public function getStatus(): string { return $this->status; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function getSource(): string { return $this->source; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getResolvedAt(): ?\DateTimeImmutable { return $this->resolvedAt; }

    public function resolve(string $status): void
    {
        $this->status = $status;
        $this->resolvedAt = new \DateTimeImmutable();
    }
}
