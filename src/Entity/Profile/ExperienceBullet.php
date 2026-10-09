<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One achievement line, owned either directly by an Experience or by one of its projects.
 */
#[ORM\Entity]
class ExperienceBullet
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'bullets'), ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Experience $experience = null;

    #[ORM\ManyToOne(inversedBy: 'bullets'), ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ExperienceProject $project = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $text = '';

    public function __construct(string $text = '')
    {
        $this->text = $text;
    }

    public function getId(): ?int { return $this->id; }
    public function getExperience(): ?Experience { return $this->experience; }
    public function setExperience(?Experience $experience): static { $this->experience = $experience; return $this; }
    public function getProject(): ?ExperienceProject { return $this->project; }
    public function setProject(?ExperienceProject $project): static { $this->project = $project; return $this; }
    public function getText(): string { return $this->text; }
    public function setText(?string $text): static { $this->text = trim((string) $text); return $this; }
}
