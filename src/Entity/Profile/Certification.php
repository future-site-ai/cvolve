<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Certification
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'certifications'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank, Assert\Length(max: 200)]
    private string $name = '';

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $issuer = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $date = null;

    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $credentialId = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $url = null;

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(?string $v): static { $this->name = trim((string) $v); return $this; }
    public function getIssuer(): ?string { return $this->issuer; }
    public function setIssuer(?string $v): static { $v = $v === null ? null : trim($v); $this->issuer = $v === '' ? null : $v; return $this; }
    public function getDate(): ?string { return $this->date; }
    public function setDate(?string $v): static { $v = $v === null ? null : trim($v); $this->date = $v === '' ? null : $v; return $this; }
    public function getCredentialId(): ?string { return $this->credentialId; }
    public function setCredentialId(?string $v): static { $v = $v === null ? null : trim($v); $this->credentialId = $v === '' ? null : $v; return $this; }
    public function getUrl(): ?string { return $this->url; }
    public function setUrl(?string $v): static { $v = $v === null ? null : trim($v); $this->url = $v === '' ? null : $v; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'date' => $this->date,
            'credentialId' => $this->credentialId,
            'url' => $this->url,
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        if (\array_key_exists('name', $data)) {
            $this->setName(\is_scalar($data['name']) ? (string) $data['name'] : null);
        }
        if (\array_key_exists('issuer', $data)) {
            $this->setIssuer(\is_scalar($data['issuer']) ? (string) $data['issuer'] : null);
        }
        if (\array_key_exists('date', $data)) {
            $this->setDate(\is_scalar($data['date']) ? (string) $data['date'] : null);
        }
        if (\array_key_exists('credentialId', $data)) {
            $this->setCredentialId(\is_scalar($data['credentialId']) ? (string) $data['credentialId'] : null);
        }
        if (\array_key_exists('url', $data)) {
            $this->setUrl(\is_scalar($data['url']) ? (string) $data['url'] : null);
        }

        return $this;
    }
}
