<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Turning a Markdown file or an existing CV into master-profile data: the text is extracted,
 * the AI parses it into CVolve profile JSON, and the user reviews it before anything is saved.
 */
#[ORM\Entity(repositoryClass: ImportJobRepository::class)]
class ImportJob implements OwnedInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY = 'ready';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 20)]
    private string $kind;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $sourceText;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $result = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $kind, string $sourceText, ?string $originalFilename = null)
    {
        $this->owner = $owner;
        $this->kind = $kind;
        $this->sourceText = $sourceText;
        $this->originalFilename = $originalFilename;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getKind(): string { return $this->kind; }
    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function getSourceText(): string { return $this->sourceText; }
    public function getStatus(): string { return $this->status; }
    public function isBusy(): bool { return \in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true); }
    /** @return array<string, mixed>|null */
    public function getResult(): ?array { return $this->result; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function start(): void { $this->status = self::STATUS_PROCESSING; }

    /** @param array<string, mixed> $result */
    public function ready(array $result): void
    {
        $this->status = self::STATUS_READY;
        $this->result = $result;
    }

    public function fail(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
    }

    public function markApplied(): void { $this->status = self::STATUS_APPLIED; }
}
