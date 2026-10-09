<?php

declare(strict_types=1);

namespace App\Entity\Profile;

use App\Entity\OwnedInterface;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The user's whole career in one place: the single source of truth every CV draws from.
 */
#[ORM\Entity]
class MasterProfile implements OwnedInterface
{
    /** Collection property => human label, in editor tab order. */
    public const SECTIONS = [
        'summaries' => 'Summaries',
        'experiences' => 'Experience',
        'educations' => 'Education',
        'skillGroups' => 'Skills',
        'languages' => 'Languages',
        'certifications' => 'Certifications',
        'projects' => 'Projects',
        'publications' => 'Publications',
        'customSections' => 'Other sections',
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'masterProfile'), ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Embedded(class: PersonalInfo::class, columnPrefix: 'personal_')]
    private PersonalInfo $personal;

    /** @var Collection<int, ProfileSummary> */
    #[ORM\OneToMany(targetEntity: ProfileSummary::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $summaries;

    /** @var Collection<int, Experience> */
    #[ORM\OneToMany(targetEntity: Experience::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $experiences;

    /** @var Collection<int, Education> */
    #[ORM\OneToMany(targetEntity: Education::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $educations;

    /** @var Collection<int, Certification> */
    #[ORM\OneToMany(targetEntity: Certification::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $certifications;

    /** @var Collection<int, SkillGroup> */
    #[ORM\OneToMany(targetEntity: SkillGroup::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $skillGroups;

    /** @var Collection<int, Language> */
    #[ORM\OneToMany(targetEntity: Language::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $languages;

    /** @var Collection<int, Publication> */
    #[ORM\OneToMany(targetEntity: Publication::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $publications;

    /** @var Collection<int, Project> */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $projects;

    /** @var Collection<int, CustomSection> */
    #[ORM\OneToMany(targetEntity: CustomSection::class, mappedBy: 'profile', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    #[Assert\Valid]
    private Collection $customSections;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->personal = new PersonalInfo();
        $this->summaries = new ArrayCollection();
        $this->experiences = new ArrayCollection();
        $this->educations = new ArrayCollection();
        $this->certifications = new ArrayCollection();
        $this->skillGroups = new ArrayCollection();
        $this->languages = new ArrayCollection();
        $this->publications = new ArrayCollection();
        $this->projects = new ArrayCollection();
        $this->customSections = new ArrayCollection();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(User $owner): static { $this->owner = $owner; return $this; }
    public function getPersonal(): PersonalInfo { return $this->personal; }
    public function setPersonal(PersonalInfo $personal): static { $this->personal = $personal; return $this; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return Collection<int, ProfileSummary> */
    public function getSummaries(): Collection { return $this->summaries; }

    public function addSummary(ProfileSummary $summary): static
    {
        if (!$this->summaries->contains($summary)) {
            $summary->setPosition($this->summaries->count());
            $this->summaries->add($summary);
            $summary->setProfile($this);
        }

        return $this;
    }

    public function removeSummary(ProfileSummary $summary): static
    {
        $this->summaries->removeElement($summary);

        return $this;
    }

    /** @return Collection<int, Experience> */
    public function getExperiences(): Collection { return $this->experiences; }

    public function addExperience(Experience $experience): static
    {
        if (!$this->experiences->contains($experience)) {
            $experience->setPosition($this->experiences->count());
            $this->experiences->add($experience);
            $experience->setProfile($this);
        }

        return $this;
    }

    public function removeExperience(Experience $experience): static
    {
        $this->experiences->removeElement($experience);

        return $this;
    }

    /** @return Collection<int, Education> */
    public function getEducations(): Collection { return $this->educations; }

    public function addEducation(Education $education): static
    {
        if (!$this->educations->contains($education)) {
            $education->setPosition($this->educations->count());
            $this->educations->add($education);
            $education->setProfile($this);
        }

        return $this;
    }

    public function removeEducation(Education $education): static
    {
        $this->educations->removeElement($education);

        return $this;
    }

    /** @return Collection<int, Certification> */
    public function getCertifications(): Collection { return $this->certifications; }

    public function addCertification(Certification $certification): static
    {
        if (!$this->certifications->contains($certification)) {
            $certification->setPosition($this->certifications->count());
            $this->certifications->add($certification);
            $certification->setProfile($this);
        }

        return $this;
    }

    public function removeCertification(Certification $certification): static
    {
        $this->certifications->removeElement($certification);

        return $this;
    }

    /** @return Collection<int, SkillGroup> */
    public function getSkillGroups(): Collection { return $this->skillGroups; }

    public function addSkillGroup(SkillGroup $skillGroup): static
    {
        if (!$this->skillGroups->contains($skillGroup)) {
            $skillGroup->setPosition($this->skillGroups->count());
            $this->skillGroups->add($skillGroup);
            $skillGroup->setProfile($this);
        }

        return $this;
    }

    public function removeSkillGroup(SkillGroup $skillGroup): static
    {
        $this->skillGroups->removeElement($skillGroup);

        return $this;
    }

    /** @return Collection<int, Language> */
    public function getLanguages(): Collection { return $this->languages; }

    public function addLanguage(Language $language): static
    {
        if (!$this->languages->contains($language)) {
            $language->setPosition($this->languages->count());
            $this->languages->add($language);
            $language->setProfile($this);
        }

        return $this;
    }

    public function removeLanguage(Language $language): static
    {
        $this->languages->removeElement($language);

        return $this;
    }

    /** @return Collection<int, Publication> */
    public function getPublications(): Collection { return $this->publications; }

    public function addPublication(Publication $publication): static
    {
        if (!$this->publications->contains($publication)) {
            $publication->setPosition($this->publications->count());
            $this->publications->add($publication);
            $publication->setProfile($this);
        }

        return $this;
    }

    public function removePublication(Publication $publication): static
    {
        $this->publications->removeElement($publication);

        return $this;
    }

    /** @return Collection<int, Project> */
    public function getProjects(): Collection { return $this->projects; }

    public function addProject(Project $project): static
    {
        if (!$this->projects->contains($project)) {
            $project->setPosition($this->projects->count());
            $this->projects->add($project);
            $project->setProfile($this);
        }

        return $this;
    }

    public function removeProject(Project $project): static
    {
        $this->projects->removeElement($project);

        return $this;
    }

    /** @return Collection<int, CustomSection> */
    public function getCustomSections(): Collection { return $this->customSections; }

    public function addCustomSection(CustomSection $customSection): static
    {
        if (!$this->customSections->contains($customSection)) {
            $customSection->setPosition($this->customSections->count());
            $this->customSections->add($customSection);
            $customSection->setProfile($this);
        }

        return $this;
    }

    public function removeCustomSection(CustomSection $customSection): static
    {
        $this->customSections->removeElement($customSection);

        return $this;
    }

    /**
     * Rewrite every position to match the current collection order (after a form submit).
     */
    public function renumber(): void
    {
        foreach (array_keys(self::SECTIONS) as $property) {
            $i = 0;
            foreach ($this->{$property} as $item) {
                $item->setPosition($i++);
            }
        }
        foreach ($this->experiences as $experience) {
            $this->renumberCollection($experience->getBullets());
            $this->renumberCollection($experience->getProjects());
            foreach ($experience->getProjects() as $project) {
                $this->renumberCollection($project->getBullets());
            }
        }
        foreach ($this->skillGroups as $group) {
            $this->renumberCollection($group->getSkills());
        }
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T> $items
     */
    private function renumberCollection(Collection $items): void
    {
        $i = 0;
        foreach ($items as $item) {
            if (method_exists($item, 'setPosition')) {
                $item->setPosition($i++);
            }
        }
    }

    public function isEmpty(): bool
    {
        foreach (array_keys(self::SECTIONS) as $property) {
            if (!$this->{$property}->isEmpty()) {
                return false;
            }
        }

        return $this->personal->getFullName() === null;
    }
}
