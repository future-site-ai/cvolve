<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A personal or entrepreneurial project (not tied to an employer).
 */
#[ORM\Entity]
#[ORM\Table(name: 'profile_project')]
class Project
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'projects'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank, Assert\Length(max: 200)]
    private string $name = '';

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $startDate = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $endDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tech = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $highlights = [];

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $v): static { $this->name = trim((string) $v); return $this; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $v): static { $this->role = $v ?: null; return $this; }
    public function getStartDate(): ?string { return $this->startDate; }
    public function setStartDate(?string $v): static { $this->startDate = $v ?: null; return $this; }
    public function getEndDate(): ?string { return $this->endDate; }
    public function setEndDate(?string $v): static { $this->endDate = $v ?: null; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $this->url = $v ?: null; return $this; }
    public function getTech(): ?string { return $this->tech; }
    public function setTech(?string $v): static { $this->tech = $v ?: null; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v ?: null; return $this; }

    /** @return list<string> */
    public function getHighlights(): array { return $this->highlights; }

    /** @param array<mixed> $highlights */
    public function setHighlights(array $highlights): static
    {
        $this->highlights = array_values(array_filter(array_map(
            static fn ($h) => trim((string) $h),
            $highlights,
        ), static fn (string $h) => $h !== ''));

        return $this;
    }
}
