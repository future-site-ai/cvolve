<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExportJobRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExportJobRepository::class)]
class ExportJob implements OwnedInterface
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    public const FORMATS = ['pdf', 'docx'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CvVariant $variant;

    #[ORM\Column(length: 10)]
    private string $format;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filePath = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(CvVariant $variant, string $format)
    {
        if (!\in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported export format "%s".', $format));
        }
        $this->variant = $variant;
        $this->format = $format;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVariant(): CvVariant { return $this->variant; }
    public function getCv(): Cv { return $this->variant->getCv(); }
    public function getOwner(): User { return $this->variant->getOwner(); }
    public function getFormat(): string { return $this->format; }
    public function getStatus(): string { return $this->status; }
    public function isFinished(): bool { return \in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true); }
    public function getFilePath(): ?string { return $this->filePath; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }

    public function start(): void { $this->status = self::STATUS_PROCESSING; }

    public function complete(string $filePath): void
    {
        $this->status = self::STATUS_DONE;
        $this->filePath = $filePath;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function fail(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->finishedAt = new \DateTimeImmutable();
    }

    public function getDownloadName(): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->getCv()->getTitle())), '-') ?: 'cv';

        return sprintf('%s-%s.%s', $slug, $this->variant->getLocale(), $this->format);
    }
}
