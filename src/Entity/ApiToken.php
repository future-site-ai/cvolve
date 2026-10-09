<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A personal access token for the REST API and the MCP server.
 * Only a SHA-256 hash is stored; the plain token is shown once when created.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken implements OwnedInterface
{
    public const PREFIX = 'cvk_';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(length: 12)]
    private string $hint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(User $owner, string $name, string $plainToken, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->owner = $owner;
        $this->name = $name;
        $this->tokenHash = self::hash($plainToken);
        $this->hint = substr($plainToken, 0, 10);
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function generatePlainToken(): string
    {
        return self::PREFIX.bin2hex(random_bytes(24));
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getName(): string { return $this->name; }
    public function getHint(): string { return $this->hint; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastUsedAt(): ?\DateTimeImmutable { return $this->lastUsedAt; }
    public function markUsed(): void { $this->lastUsedAt = new \DateTimeImmutable(); }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }

    public function isValid(): bool
    {
        return $this->expiresAt === null || $this->expiresAt > new \DateTimeImmutable();
    }
}
