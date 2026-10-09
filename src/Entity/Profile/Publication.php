<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Publication
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'publications'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authors = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $venue = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $date = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $url = null;

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $v): static { $this->title = trim((string) $v); return $this; }
    public function getAuthors(): ?string { return $this->authors; }
    public function setAuthors(?string $v): static { $v = $v === null ? null : trim($v); $this->authors = $v === '' ? null : $v; return $this; }
    public function getVenue(): ?string { return $this->venue; }
    public function setVenue(?string $v): static { $v = $v === null ? null : trim($v); $this->venue = $v === '' ? null : $v; return $this; }
    public function getDate(): ?string { return $this->date; }
    public function setDate(?string $v): static { $v = $v === null ? null : trim($v); $this->date = $v === '' ? null : $v; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $v = $v === null ? null : trim($v); $this->url = $v === '' ? null : $v; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'authors' => $this->authors,
            'venue' => $this->venue,
            'date' => $this->date,
            'url' => $this->url,
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        if (\array_key_exists('title', $data)) {
            $this->setTitle(\is_scalar($data['title']) ? (string) $data['title'] : null);
        }
        if (\array_key_exists('authors', $data)) {
            $this->setAuthors(\is_scalar($data['authors']) ? (string) $data['authors'] : null);
        }
        if (\array_key_exists('venue', $data)) {
            $this->setVenue(\is_scalar($data['venue']) ? (string) $data['venue'] : null);
        }
        if (\array_key_exists('date', $data)) {
            $this->setDate(\is_scalar($data['date']) ? (string) $data['date'] : null);
        }
        if (\array_key_exists('url', $data)) {
            $this->setUrl(\is_scalar($data['url']) ? (string) $data['url'] : null);
        }

        return $this;
    }
}
