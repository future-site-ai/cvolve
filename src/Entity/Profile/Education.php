<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
class Education
{
    use PositionTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'educations'), ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?MasterProfile $profile = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank, Assert\Length(max: 200)]
    private string $degree = '';

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200)]
    private ?string $institution = null;

    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $location = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $startDate = null;

    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $endDate = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Assert\Length(max: 60)]
    private ?string $grade = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int { return $this->id; }
    public function getProfile(): ?MasterProfile { return $this->profile; }
    public function setProfile(?MasterProfile $profile): static { $this->profile = $profile; return $this; }
    public function getDegree(): string { return $this->degree; }
    public function setDegree(?string $v): static { $this->degree = trim((string) $v); return $this; }
    public function getInstitution(): ?string { return $this->institution; }
    public function setInstitution(?string $v): static { $v = $v === null ? null : trim($v); $this->institution = $v === '' ? null : $v; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $v): static { $v = $v === null ? null : trim($v); $this->location = $v === '' ? null : $v; return $this; }
    public function getStartDate(): ?string { return $this->startDate; }
    public function setStartDate(?string $v): static { $v = $v === null ? null : trim($v); $this->startDate = $v === '' ? null : $v; return $this; }
    public function getEndDate(): ?string { return $this->endDate; }
    public function setEndDate(?string $v): static { $v = $v === null ? null : trim($v); $this->endDate = $v === '' ? null : $v; return $this; }
    public function getGrade(): ?string { return $this->grade; }
    public function setGrade(?string $v): static { $v = $v === null ? null : trim($v); $this->grade = $v === '' ? null : $v; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $v): static { $v = $v === null ? null : trim($v); $this->description = $v === '' ? null : $v; return $this; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'degree' => $this->degree,
            'institution' => $this->institution,
            'location' => $this->location,
            'startDate' => $this->startDate,
            'endDate' => $this->endDate,
            'grade' => $this->grade,
            'description' => $this->description,
        ];
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): static
    {
        if (\array_key_exists('degree', $data)) {
            $this->setDegree(\is_scalar($data['degree']) ? (string) $data['degree'] : null);
        }
        if (\array_key_exists('institution', $data)) {
            $this->setInstitution(\is_scalar($data['institution']) ? (string) $data['institution'] : null);
        }
        if (\array_key_exists('location', $data)) {
            $this->setLocation(\is_scalar($data['location']) ? (string) $data['location'] : null);
        }
        if (\array_key_exists('startDate', $data)) {
            $this->setStartDate(\is_scalar($data['startDate']) ? (string) $data['startDate'] : null);
        }
        if (\array_key_exists('endDate', $data)) {
            $this->setEndDate(\is_scalar($data['endDate']) ? (string) $data['endDate'] : null);
        }
        if (\array_key_exists('grade', $data)) {
            $this->setGrade(\is_scalar($data['grade']) ? (string) $data['grade'] : null);
        }
        if (\array_key_exists('description', $data)) {
            $this->setDescription(\is_scalar($data['description']) ? (string) $data['description'] : null);
        }

        return $this;
    }
}
