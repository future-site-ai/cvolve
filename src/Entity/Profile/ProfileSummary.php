<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A reusable professional summary (several can be kept, e.g. "Software", "Biomedical").
 */
#[ORM\Entity]
class ProfileSummary
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'summaries'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank, Assert\Length(max: 80)]
    private string $label = '';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $content = '';

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(?string $v): static { $this->label = trim((string) $v); return $this; }
    public function getContent(): string { return $this->content; }
    public function setContent(?string $v): static { $this->content = trim((string) $v); return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'content' => $this->content,
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        if (\array_key_exists('label', $data)) {
            $this->setLabel(\is_scalar($data['label']) ? (string) $data['label'] : null);
        }
        if (\array_key_exists('content', $data)) {
            $this->setContent(\is_scalar($data['content']) ? (string) $data['content'] : null);
        }

        return $this;
    }
}
