<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CvRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CvRepository::class)]
class Cv implements OwnedInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank, Assert\Length(max: 150)]
    private string $title = '';

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false)]
    private Template $template;

    #[ORM\Column(length: 10)]
    private string $defaultLocale = 'en';

    /** @var Collection<int, CvVariant> */
    #[ORM\OneToMany(targetEntity: CvVariant::class, mappedBy: 'cv', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $variants;

    #[ORM\Column(length: 40, unique: true, nullable: true)]
    private ?string $shareToken = null;

    #[ORM\Column]
    private bool $shareEnabled = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $title, Template $template, string $defaultLocale = 'en')
    {
        $this->owner = $owner;
        $this->title = $title;
        $this->template = $template;
        $this->defaultLocale = $defaultLocale;
        $this->variants = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): User { return $this->owner; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $title): static { $this->title = trim((string) $title); return $this; }
    public function getTemplate(): Template { return $this->template; }
    public function setTemplate(Template $template): static { $this->template = $template; return $this; }
    public function getDefaultLocale(): string { return $this->defaultLocale; }
    public function setDefaultLocale(string $locale): static { $this->defaultLocale = $locale; return $this; }

    /** @return Collection<int, CvVariant> */
    public function getVariants(): Collection { return $this->variants; }

    public function addVariant(CvVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
        }

        return $this;
    }

    public function removeVariant(CvVariant $variant): static
    {
        $this->variants->removeElement($variant);

        return $this;
    }

    public function getVariant(?string $locale = null): ?CvVariant
    {
        $locale ??= $this->defaultLocale;
        foreach ($this->variants as $variant) {
            if ($variant->getLocale() === $locale) {
                return $variant;
            }
        }

        return null;
    }

    public function getDefaultVariant(): CvVariant
    {
        $variant = $this->getVariant() ?? $this->variants->first();
        if (!$variant instanceof CvVariant) {
            throw new \LogicException(sprintf('CV %d has no language variant.', $this->id));
        }

        return $variant;
    }

    /** @return list<string> */
    public function getLocales(): array
    {
        return array_values($this->variants->map(static fn (CvVariant $v) => $v->getLocale())->toArray());
    }

    public function getShareToken(): ?string { return $this->shareToken; }
    public function isShareEnabled(): bool { return $this->shareEnabled && $this->shareToken !== null; }

    public function enableSharing(): void
    {
        $this->shareToken ??= bin2hex(random_bytes(16));
        $this->shareEnabled = true;
    }

    public function disableSharing(): void { $this->shareEnabled = false; }
    public function regenerateShareToken(): void { $this->shareToken = bin2hex(random_bytes(16)); }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
