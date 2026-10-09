<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A project delivered within one job (e.g. a product built while employed at a company).
 */
#[ORM\Entity]
class ExperienceProject
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'projects'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Experience $experience = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank, Assert\Length(max: 200)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $period = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tech = null;

    /** @var Collection<int, ExperienceBullet> */
    #[ORM\OneToMany(targetEntity: ExperienceBullet::class, mappedBy: 'project', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $bullets;

    public function __construct()
    {
        $this->bullets = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getExperience(): ?Experience { return $this->experience; }
    public function setExperience(?Experience $experience): static { $this->experience = $experience; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $v): static { $this->name = trim((string) $v); return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $this->url = $v ?: null; return $this; }
    public function getPeriod(): ?string { return $this->period; }
    public function setPeriod(?string $v): static { $this->period = $v ?: null; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $this->description = $v ?: null; return $this; }
    public function getTech(): ?string { return $this->tech; }
    public function setTech(?string $v): static { $this->tech = $v ?: null; return $this; }

    /** @return Collection<int, ExperienceBullet> */
    public function getBullets(): Collection { return $this->bullets; }

    public function addBullet(ExperienceBullet $bullet): static
    {
        if (!$this->bullets->contains($bullet)) {
            $this->bullets->add($bullet);
            $bullet->setProject($this);
        }

        return $this;
    }

    public function removeBullet(ExperienceBullet $bullet): static
    {
        $this->bullets->removeElement($bullet);

        return $this;
    }
}
