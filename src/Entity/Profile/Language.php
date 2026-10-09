<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'profile_language')]
class Language
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'languages'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank, Assert\Length(max: 80)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $level = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $note = null;

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $v): static { $this->name = trim((string) $v); return $this; }
    public function getLevel(): ?string { return $this->level; }
    public function setLevel(?string $v): static { $v = $v === null ? null : trim($v); $this->level = $v === '' ? null : $v; return $this; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): static { $v = $v === null ? null : trim($v); $this->note = $v === '' ? null : $v; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'note' => $this->note,
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
        if (\array_key_exists('note', $data)) {
            $this->setNote(\is_scalar($data['note']) ? (string) $data['note'] : null);
        }

        return $this;
    }
}
