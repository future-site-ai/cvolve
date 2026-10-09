<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Free-form section such as volunteering, awards, interests or soft skills.
 * The body holds one entry per line; lines may start with "- ".
 */
#[ORM\Entity]
class CustomSection
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'customSections'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank, Assert\Length(max: 120)]
    private string $title = '';

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank, Assert\Length(max: 30)]
    private string $kind = 'custom';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $body = null;

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = trim((string) $v); return $this; }
    public function getKind(): string { return $this->kind; }
    public function setKind(?string $v): static { $this->kind = trim((string) $v); return $this; }
    public function getBody(): ?string { return $this->body; }
    public function setBody(?string $v): static { $v = $v === null ? null : trim($v); $this->body = $v === '' ? null : $v; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'kind' => $this->kind,
            'body' => $this->body,
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        if (\array_key_exists('title', $data)) {
            $this->setTitle(\is_scalar($data['title']) ? (string) $data['title'] : null);
        }
        if (\array_key_exists('kind', $data)) {
            $this->setKind(\is_scalar($data['kind']) ? (string) $data['kind'] : null);
        }
        if (\array_key_exists('body', $data)) {
            $this->setBody(\is_scalar($data['body']) ? (string) $data['body'] : null);
        }

        return $this;
    }
}
