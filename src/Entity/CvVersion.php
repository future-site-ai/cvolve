<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CvVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A snapshot of one language variant of a CV (header, sections and template).
 */
#[ORM\Entity(repositoryClass: CvVersionRepository::class)]
class CvVersion implements OwnedInterface
{
    public const REASON_MANUAL = 'manual';
    public const REASON_AI = 'ai';
    public const REASON_RESTORE = 'restore';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cv $cv;

    #[ORM\Column(length: 10)]
    private string $locale;

    #[ORM\Column(length: 200)]
    private string $label;

    #[ORM\Column(length: 20)]
    private string $reason;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $snapshot;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $snapshot */
    public function __construct(Cv $cv, string $locale, string $label, string $reason, array $snapshot)
    {
        $this->cv = $cv;
        $this->locale = $locale;
        $this->label = mb_substr($label, 0, 200);
        $this->reason = $reason;
        $this->snapshot = $snapshot;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCv(): Cv { return $this->cv; }
    public function getOwner(): User { return $this->cv->getOwner(); }
    public function getLocale(): string { return $this->locale; }
    public function getLabel(): string { return $this->label; }
    public function getReason(): string { return $this->reason; }
    /** @return array<string, mixed> */
    public function getSnapshot(): array { return $this->snapshot; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
