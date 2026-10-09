<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One language version of a CV: its header block and its ordered sections.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['cv', 'locale'])]
class CvVariant implements OwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'variants'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cv $cv;

    #[ORM\Column(length: 10)]
    private string $locale;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $header = [];

    /** @var Collection<int, CvSection> */
    #[ORM\OneToMany(targetEntity: CvSection::class, mappedBy: 'variant', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $sections;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Cv $cv, string $locale)
    {
        $this->cv = $cv;
        $this->locale = $locale;
        $this->sections = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
        $cv->addVariant($this);
    }

    public function getId(): ?int { return $this->id; }
    public function getCv(): Cv { return $this->cv; }
    public function getOwner(): User { return $this->cv->getOwner(); }
    public function getLocale(): string { return $this->locale; }

    /** @return array<string, mixed> */
    public function getHeader(): array { return $this->header; }
    /** @param array<string, mixed> $header */
    public function setHeader(array $header): static { $this->header = $header; return $this; }

    /** @return Collection<int, CvSection> */
    public function getSections(): Collection { return $this->sections; }

    public function addSection(CvSection $section, ?int $position = null): static
    {
        $sections = array_values(array_filter($this->sections->toArray(), static fn (CvSection $s) => $s !== $section));
        $position = $position === null ? \count($sections) : max(0, min($position, \count($sections)));
        array_splice($sections, $position, 0, [$section]);
        if (!$this->sections->contains($section)) {
            $this->sections->add($section);
        }
        $section->setVariant($this);
        foreach ($sections as $i => $s) {
            $s->setPosition($i);
        }

        return $this;
    }

    public function removeSection(CvSection $section): static
    {
        $this->sections->removeElement($section);
        $this->renumber();

        return $this;
    }

    /** @return list<CvSection> sections sorted by position */
    public function getOrderedSections(): array
    {
        $sections = $this->sections->toArray();
        usort($sections, static fn (CvSection $a, CvSection $b) => [$a->getPosition(), $a->getId() ?? PHP_INT_MAX] <=> [$b->getPosition(), $b->getId() ?? PHP_INT_MAX]);

        return $sections;
    }

    public function getSection(int $id): ?CvSection
    {
        foreach ($this->sections as $section) {
            if ($section->getId() === $id) {
                return $section;
            }
        }

        return null;
    }

    public function findSectionByType(string $type): ?CvSection
    {
        foreach ($this->getOrderedSections() as $section) {
            if ($section->getType() === $type) {
                return $section;
            }
        }

        return null;
    }

    public function renumber(): void
    {
        foreach ($this->getOrderedSections() as $i => $section) {
            $section->setPosition($i);
        }
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->cv->touch();

        return $this;
    }
}
