<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Skill
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'skills'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SkillGroup $group = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank, Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $level = null;

    public function getId(): ?int { return $this->id; }
    public function getGroup(): ?SkillGroup { return $this->group; }
    public function setGroup(?SkillGroup $group): static { $this->group = $group; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $v): static { $this->name = trim((string) $v); return $this; }
    public function getLevel(): ?string { return $this->level; }
    public function setLevel(?string $v): static { $v = $v === null ? null : trim($v); $this->level = $v === '' ? null : $v; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        if (\array_key_exists('name', $data)) {
            $this->setName(\is_scalar($data['name']) ? (string) $data['name'] : null);
        }
        if (\array_key_exists('level', $data)) {
            $this->setLevel(\is_scalar($data['level']) ? (string) $data['level'] : null);
        }

        return $this;
    }
}
