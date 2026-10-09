<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One section of a CV. Content is a JSON document whose shape depends on the type
 * (see Cvolve\TemplateKit\SectionTypes). It is copied from the master profile and then
 * edited per CV, so editing a CV never changes the master profile.
 */
#[ORM\Entity]
class CvSection
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sections'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CvVariant $variant = null;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $visible = true;

    /** Template region override (e.g. "sidebar"); null = the template's default for this type. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $region = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $content = [];

    /** @var list<string> master profile items this section was built from, e.g. "experience:12" */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $sourceRefs = [];

    /** @param array<string, mixed> $content */
    public function __construct(string $type, string $title, array $content = [])
    {
        $this->type = $type;
        $this->title = $title;
        $this->content = $content;
    }

    public function getId(): ?int { return $this->id; }
    public function getVariant(): ?CvVariant { return $this->variant; }
    public function setVariant(?CvVariant $variant): static { $this->variant = $variant; return $this; }
    public function getType(): string { return $this->type; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = trim($title); return $this; }
    public function getPosition(): int { return $this->position; }
    public function setPosition(int $position): static { $this->position = $position; return $this; }
    public function isVisible(): bool { return $this->visible; }
    public function setVisible(bool $visible): static { $this->visible = $visible; return $this; }
    public function getRegion(): ?string { return $this->region; }
    public function setRegion(?string $region): static { $this->region = $region ?: null; return $this; }

    /** @return array<string, mixed> */
    public function getContent(): array { return $this->content; }
    /** @param array<string, mixed> $content */
    public function setContent(array $content): static { $this->content = $content; return $this; }

    /** @return list<string> */
    public function getSourceRefs(): array { return $this->sourceRefs; }
    /** @param list<string> $refs */
    public function setSourceRefs(array $refs): static { $this->sourceRefs = array_values(array_unique($refs)); return $this; }
}
