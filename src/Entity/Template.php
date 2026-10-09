<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TemplateRepository;
use Cvolve\TemplateKit\SectionTypes;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An installed CVolve template package (.cvolve). Presets are global (owner = null) and
 * managed by admins; private templates belong to the user who imported them.
 */
#[ORM\Entity(repositoryClass: TemplateRepository::class)]
#[ORM\Index(fields: ['slug'])]
class Template
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 20)]
    private string $version = '1.0.0';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $licence = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $manifest = [];

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $schema = [];

    #[ORM\Column(type: Types::TEXT)]
    private string $twigSource = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $css = '';

    /** Directory (under var/storage/templates) holding the thumbnail and fonts. */
    #[ORM\Column(length: 120)]
    private string $storageKey;

    #[ORM\ManyToOne, ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column]
    private bool $published = true;

    /** Format the template was imported from (cvolve, mustache-html, json-resume, raw-html…). */
    #[ORM\Column(length: 30, options: ['default' => 'cvolve'])]
    private string $sourceFormat = 'cvolve';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $slug, string $name)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->storageKey = bin2hex(random_bytes(12));
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getVersion(): string { return $this->version; }
    public function setVersion(string $version): static { $this->version = $version; return $this; }
    public function getAuthor(): ?string { return $this->author; }
    public function setAuthor(?string $author): static { $this->author = $author; return $this; }
    public function getLicence(): ?string { return $this->licence; }
    public function setLicence(?string $licence): static { $this->licence = $licence; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    /** @return array<string, mixed> */
    public function getManifest(): array { return $this->manifest; }
    /** @param array<string, mixed> $manifest */
    public function setManifest(array $manifest): static { $this->manifest = $manifest; return $this; }

    /** @return array<string, mixed> */
    public function getSchema(): array { return $this->schema; }
    /** @param array<string, mixed> $schema */
    public function setSchema(array $schema): static { $this->schema = $schema; return $this; }

    public function getTwigSource(): string { return $this->twigSource; }
    public function setTwigSource(string $twigSource): static { $this->twigSource = $twigSource; return $this; }
    public function getCss(): string { return $this->css; }
    public function setCss(string $css): static { $this->css = $css; return $this; }
    public function getStorageKey(): string { return $this->storageKey; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): static { $this->owner = $owner; return $this; }
    public function isPreset(): bool { return $this->owner === null; }
    public function getSourceFormat(): string { return $this->sourceFormat; }
    public function setSourceFormat(string $format): static { $this->sourceFormat = $format; return $this; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): static { $this->published = $published; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** @return array<string, array<string, mixed>> section type => rules from schema.json */
    public function getSectionRules(): array
    {
        $sections = $this->schema['sections'] ?? [];

        return \is_array($sections) ? $sections : [];
    }

    /** @return list<string> */
    public function getSupportedSectionTypes(): array
    {
        // JSONB does not keep key order, so use the canonical section order
        $supported = array_keys($this->getSectionRules());

        return array_values(array_filter(array_keys(SectionTypes::TYPES), static fn (string $type) => \in_array($type, $supported, true)));
    }

    public function supportsSection(string $type): bool
    {
        return \array_key_exists($type, $this->getSectionRules());
    }

    /** @return list<string> */
    public function getRegions(): array
    {
        $regions = $this->schema['regions'] ?? ['main'];

        return \is_array($regions) && $regions !== [] ? array_values($regions) : ['main'];
    }

    public function getDefaultRegion(string $type): string
    {
        $region = $this->getSectionRules()[$type]['region'] ?? null;

        return \is_string($region) && \in_array($region, $this->getRegions(), true) ? $region : $this->getRegions()[0];
    }
}
