<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class PersonalInfo
{
    public const FIELDS = [
        'fullName', 'headline', 'email', 'phone', 'address', 'nationality', 'dateOfBirth',
        'website', 'linkedin', 'github', 'workAuthorization', 'drivingLicence', 'extra',
    ];

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $headline = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nationality = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $dateOfBirth = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkedin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $github = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $workAuthorization = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $drivingLicence = null;

    /** Free text for anything else (time zone, VAT number, ...). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extra = null;

    /** Stored file name of the profile photo (see PhotoStorage). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $photo = null;

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(?string $v): static { $this->fullName = $v; return $this; }
    public function getHeadline(): ?string { return $this->headline; }
    public function setHeadline(?string $v): static { $this->headline = $v; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = $v; return $this; }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = $v; return $this; }
    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $v): static { $this->address = $v; return $this; }
    public function getNationality(): ?string { return $this->nationality; }
    public function setNationality(?string $v): static { $this->nationality = $v; return $this; }
    public function getDateOfBirth(): ?string { return $this->dateOfBirth; }
    public function setDateOfBirth(?string $v): static { $this->dateOfBirth = $v; return $this; }
    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $v): static { $this->website = $v; return $this; }
    public function getLinkedin(): ?string { return $this->linkedin; }
    public function setLinkedin(?string $v): static { $this->linkedin = $v; return $this; }
    public function getGithub(): ?string { return $this->github; }
    public function setGithub(?string $v): static { $this->github = $v; return $this; }
    public function getWorkAuthorization(): ?string { return $this->workAuthorization; }
    public function setWorkAuthorization(?string $v): static { $this->workAuthorization = $v; return $this; }
    public function getDrivingLicence(): ?string { return $this->drivingLicence; }
    public function setDrivingLicence(?string $v): static { $this->drivingLicence = $v; return $this; }
    public function getExtra(): ?string { return $this->extra; }
    public function setExtra(?string $v): static { $this->extra = $v; return $this; }
    public function getPhoto(): ?string { return $this->photo; }
    public function setPhoto(?string $v): static { $this->photo = $v; return $this; }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = $this->{$field};
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): void
    {
        foreach (self::FIELDS as $field) {
            if (\array_key_exists($field, $data)) {
                $value = \is_scalar($data[$field]) ? trim((string) $data[$field]) : null;
                $this->{$field} = $value === '' ? null : $value;
            }
        }
    }
}
