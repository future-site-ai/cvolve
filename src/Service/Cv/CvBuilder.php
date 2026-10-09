<?php

declare(strict_types=1);

namespace App\Service\Cv;

use Cvolve\TemplateKit\SectionTypes;
use App\Entity\Cv;
use App\Entity\CvSection;
use App\Entity\CvVariant;
use App\Entity\Profile\CustomSection;
use App\Entity\Profile\Experience;
use App\Entity\Profile\MasterProfile;
use App\Entity\Profile\ProfileSummary;
use App\Entity\Template;
use App\Entity\User;

/**
 * Builds CVs and CV sections from the master profile. Content is copied, so later
 * edits to the CV never write back to the master profile.
 */
final class CvBuilder
{
    /** Section order used for a new CV. */
    public const DEFAULT_ORDER = ['summary', 'experience', 'projects', 'education', 'skills', 'languages', 'certifications', 'publications', 'custom'];

    /**
     * @param array{summary_id?: int|null, experience_ids?: list<int>|null, section_types?: list<string>|null} $options
     */
    public function create(User $user, string $title, Template $template, string $locale = 'en', array $options = []): Cv
    {
        $cv = new Cv($user, $title, $template, $locale);
        $variant = new CvVariant($cv, $locale);
        $profile = $user->getMasterProfile();
        $variant->setHeader($profile ? $this->header($profile) : ['showPhoto' => false]);

        if ($profile === null) {
            return $cv;
        }

        $types = $options['section_types'] ?? self::DEFAULT_ORDER;
        foreach (self::DEFAULT_ORDER as $type) {
            if (!\in_array($type, $types, true) || !$template->supportsSection($type)) {
                continue;
            }
            $ids = match ($type) {
                'summary' => isset($options['summary_id']) ? [$options['summary_id']] : null,
                'experience' => $options['experience_ids'] ?? null,
                default => null,
            };
            foreach ($this->sectionsFromMaster($type, $profile, $template, $locale, $ids) as $section) {
                $variant->addSection($section);
            }
        }

        return $cv;
    }

    /**
     * @return array<string, mixed>
     */
    public function header(MasterProfile $profile): array
    {
        $p = $profile->getPersonal();

        return [
            'name' => (string) $p->getFullName(),
            'headline' => (string) $p->getHeadline(),
            'email' => (string) $p->getEmail(),
            'phone' => (string) $p->getPhone(),
            'location' => (string) $p->getAddress(),
            'website' => (string) $p->getWebsite(),
            'linkedin' => (string) $p->getLinkedin(),
            'github' => (string) $p->getGithub(),
            'nationality' => (string) $p->getNationality(),
            'dateOfBirth' => '',
            'workAuthorization' => (string) $p->getWorkAuthorization(),
            'drivingLicence' => (string) $p->getDrivingLicence(),
            'extra' => '',
            'showPhoto' => $p->getPhoto() !== null,
        ];
    }

    /**
     * Build CV sections of one type from the master profile (custom sections give one CV section each).
     *
     * @param list<int>|null $ids restrict to these master item ids (summary / experience / project ids)
     *
     * @return list<CvSection>
     */
    public function sectionsFromMaster(string $type, MasterProfile $profile, Template $template, string $locale = 'en', ?array $ids = null): array
    {
        $rules = $template->getSectionRules()[$type] ?? [];
        $make = function (string $sectionType, array $content, array $refs, ?string $title = null) use ($template, $locale, $rules): CvSection {
            $content = SectionTypes::normalize($sectionType, $content);
            $content = $this->applyLimits($sectionType, $content, $rules);
            $section = new CvSection($sectionType, $title ?? SectionTypes::defaultTitle($sectionType, $locale), $content);
            $section->setRegion($template->getDefaultRegion($sectionType));

            return $section->setSourceRefs($refs);
        };
        $pick = static fn (iterable $items): array => array_values(array_filter(
            \is_array($items) ? $items : iterator_to_array($items, false),
            static fn (object $o) => $ids === null || \in_array(method_exists($o, 'getId') ? $o->getId() : null, $ids, true),
        ));

        switch ($type) {
            case 'summary':
                $summaries = $pick($profile->getSummaries());
                /** @var ProfileSummary|null $summary */
                $summary = $summaries[0] ?? null;
                if ($summary === null) {
                    return [];
                }

                return [$make('summary', ['text' => $summary->getContent()], ['summary:'.$summary->getId()])];

            case 'experience':
                $items = $refs = [];
                foreach ($pick($profile->getExperiences()) as $experience) {
                    $items[] = $this->experienceItem($experience);
                    $refs[] = 'experience:'.$experience->getId();
                }

                return $items === [] ? [] : [$make('experience', ['items' => $items], $refs)];

            case 'projects':
                $items = $refs = [];
                foreach ($pick($profile->getProjects()) as $project) {
                    $items[] = [
                        'name' => $project->getName(), 'role' => $project->getRole(), 'start' => $project->getStartDate(), 'end' => $project->getEndDate(),
                        'url' => $project->getUrl(), 'tech' => $project->getTech(), 'description' => $project->getDescription(),
                        'bullets' => $project->getHighlights(),
                    ];
                    $refs[] = 'project:'.$project->getId();
                }

                return $items === [] ? [] : [$make('projects', ['items' => $items], $refs)];

            case 'education':
                $items = [];
                foreach ($profile->getEducations() as $e) {
                    $items[] = ['degree' => $e->getDegree(), 'institution' => $e->getInstitution(), 'location' => $e->getLocation(), 'start' => $e->getStartDate(), 'end' => $e->getEndDate(), 'grade' => $e->getGrade(), 'description' => $e->getDescription()];
                }

                return $items === [] ? [] : [$make('education', ['items' => $items], ['education'])];

            case 'skills':
                $items = [];
                foreach ($profile->getSkillGroups() as $group) {
                    $skills = [];
                    foreach ($group->getSkills() as $skill) {
                        $skills[] = $skill->getName().($skill->getLevel() ? ' ('.$skill->getLevel().')' : '');
                    }
                    $items[] = ['group' => $group->getName(), 'skills' => implode(', ', $skills)];
                }

                return $items === [] ? [] : [$make('skills', ['items' => $items], ['skills'])];

            case 'languages':
                $items = array_map(static fn ($l) => $l->toArray(), $profile->getLanguages()->toArray());

                return $items === [] ? [] : [$make('languages', ['items' => $items], ['languages'])];

            case 'certifications':
                $items = array_map(static fn ($c) => $c->toArray(), $profile->getCertifications()->toArray());

                return $items === [] ? [] : [$make('certifications', ['items' => $items], ['certifications'])];

            case 'publications':
                $items = array_map(static fn ($p) => $p->toArray(), $profile->getPublications()->toArray());

                return $items === [] ? [] : [$make('publications', ['items' => $items], ['publications'])];

            case 'custom':
                $sections = [];
                foreach ($pick($profile->getCustomSections()) as $custom) {
                    $sections[] = $make('custom', ['items' => $this->customItems($custom)], ['custom:'.$custom->getId()], $custom->getTitle());
                }

                return $sections;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function experienceItem(Experience $experience): array
    {
        $bullets = [];
        foreach ($experience->getProjects() as $project) {
            $first = true;
            foreach ($project->getBullets() as $bullet) {
                // Lead the project's first bullet with its name so the grouping survives in a flat list
                $bullets[] = $first ? sprintf('**%s:** %s', $project->getName(), $bullet->getText()) : $bullet->getText();
                $first = false;
            }
            if ($first && $project->getDescription()) {
                $bullets[] = sprintf('**%s:** %s', $project->getName(), $project->getDescription());
            }
        }
        foreach ($experience->getBullets() as $bullet) {
            $bullets[] = $bullet->getText();
        }

        return [
            'title' => $experience->getTitle(),
            'organization' => $experience->getCompany(),
            'location' => $experience->getLocation(),
            'start' => $experience->getStartDate(),
            'end' => $experience->getEndDate(),
            'description' => $experience->getDescription(),
            'tech' => $experience->getTech(),
            'bullets' => $bullets,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function customItems(CustomSection $custom): array
    {
        $items = [];
        foreach (preg_split('/\R/', (string) $custom->getBody()) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*[-*•]\s+/u', '', $line));
            if ($line !== '') {
                $items[] = ['title' => '', 'subtitle' => '', 'date' => '', 'description' => $line];
            }
        }

        return $items;
    }

    /**
     * Hide entries and bullets beyond the template's limits instead of dropping them,
     * so the user can swap what is shown without losing anything.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $rules
     *
     * @return array<string, mixed>
     */
    private function applyLimits(string $type, array $content, array $rules): array
    {
        if (SectionTypes::isText($type)) {
            return $content;
        }
        $maxItems = (int) ($rules['maxItems'] ?? 0);
        $maxBullets = (int) ($rules['maxBullets'] ?? 0);
        foreach ($content['items'] as $i => &$item) {
            if ($maxItems > 0 && $i >= $maxItems) {
                $item['visible'] = false;
            }
            if ($maxBullets > 0 && isset($item['bullets'])) {
                foreach ($item['bullets'] as $b => &$bullet) {
                    if ($b >= $maxBullets) {
                        $bullet['visible'] = false;
                    }
                }
                unset($bullet);
            }
        }
        unset($item);

        return $content;
    }
}
