<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A job or engagement: company -> projects -> bullet points, plus direct bullets.
 */
#[ORM\Entity]
class Experience
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'experiences'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank, Assert\Length(max: 200)]
    private string $title = '';

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $startDate = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $endDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $tech = null;

    /** @var Collection<int, ExperienceBullet> */
    #[ORM\OneToMany(targetEntity: ExperienceBullet::class, mappedBy: 'experience', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $bullets;

    /** @var Collection<int, ExperienceProject> */
    #[ORM\OneToMany(targetEntity: ExperienceProject::class, mappedBy: 'experience', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $projects;

    public function __construct()
    {
        $this->bullets = new ArrayCollection();
        $this->projects = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = trim((string) $v); return $this; }
    public function getCompany(): ?string { return $this->company; }
    public function setCompany(?string $v): static { $this->company = $v ?: null; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $v): static { $this->location = $v ?: null; return $this; }
    public function getStartDate(): ?string { return $this->startDate; }
    public function setStartDate(?string $v): static { $this->startDate = $v ?: null; return $this; }
    public function getEndDate(): ?string { return $this->endDate; }
    public function setEndDate(?string $v): static { $this->endDate = $v ?: null; return $this; }
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
            $bullet->setExperience($this);
        }

        return $this;
    }

    public function removeBullet(ExperienceBullet $bullet): static
    {
        $this->bullets->removeElement($bullet);

        return $this;
    }

    /** @return Collection<int, ExperienceProject> */
    public function getProjects(): Collection { return $this->projects; }

    public function addProject(ExperienceProject $project): static
    {
        if (!$this->projects->contains($project)) {
            $this->projects->add($project);
            $project->setExperience($this);
        }

        return $this;
    }

    public function removeProject(ExperienceProject $project): static
    {
        $this->projects->removeElement($project);

        return $this;
    }

    public function getPeriod(): string
    {
        return trim(($this->startDate ?? '').($this->endDate ? ' – '.$this->endDate : ''), ' –');
    }
}
