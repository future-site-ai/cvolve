<?php

declare(strict_types=1);

namespace App\Service\Cv;

use App\Entity\Profile\Certification;
use App\Entity\Profile\CustomSection;
use App\Entity\Profile\Education;
use App\Entity\Profile\Experience;
use App\Entity\Profile\ExperienceBullet;
use App\Entity\Profile\ExperienceProject;
use App\Entity\Profile\Language;
use App\Entity\Profile\MasterProfile;
use App\Entity\Profile\ProfileSummary;
use App\Entity\Profile\Project;
use App\Entity\Profile\Publication;
use App\Entity\Profile\Skill;
use App\Entity\Profile\SkillGroup;

/**
 * Converts a master profile to and from "CVolve profile JSON", the format used by
 * the import review, the AI parser, the REST API and the demo seed data.
 */
final class ProfileSerializer
{
    /** JSON key => MasterProfile collection property */
    public const SECTION_KEYS = [
        'summaries' => 'summaries',
        'experiences' => 'experiences',
        'education' => 'educations',
        'skills' => 'skillGroups',
        'languages' => 'languages',
        'certifications' => 'certifications',
        'projects' => 'projects',
        'publications' => 'publications',
        'custom_sections' => 'customSections',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(MasterProfile $profile, bool $withIds = false): array
    {
        $id = static fn (?int $id): array => $withIds ? ['id' => $id] : [];

        return [
            'personal' => $profile->getPersonal()->toArray() + ['hasPhoto' => $profile->getPersonal()->getPhoto() !== null],
            'summaries' => array_map(static fn (ProfileSummary $s) => $id($s->getId()) + ['label' => $s->getLabel(), 'content' => $s->getContent()], $profile->getSummaries()->toArray()),
            'experiences' => array_map(static fn (Experience $e) => $id($e->getId()) + [
                'title' => $e->getTitle(),
                'company' => $e->getCompany(),
                'location' => $e->getLocation(),
                'start' => $e->getStartDate(),
                'end' => $e->getEndDate(),
                'description' => $e->getDescription(),
                'tech' => $e->getTech(),
                'bullets' => array_map(static fn (ExperienceBullet $b) => $b->getText(), $e->getBullets()->toArray()),
                'projects' => array_map(static fn (ExperienceProject $p) => $id($p->getId()) + [
                    'name' => $p->getName(),
                    'url' => $p->getUrl(),
                    'period' => $p->getPeriod(),
                    'description' => $p->getDescription(),
                    'tech' => $p->getTech(),
                    'bullets' => array_map(static fn (ExperienceBullet $b) => $b->getText(), $p->getBullets()->toArray()),
                ], $e->getProjects()->toArray()),
            ], $profile->getExperiences()->toArray()),
            'education' => array_map(static fn (Education $e) => $id($e->getId()) + [
                'degree' => $e->getDegree(),
                'institution' => $e->getInstitution(),
                'location' => $e->getLocation(),
                'start' => $e->getStartDate(),
                'end' => $e->getEndDate(),
                'grade' => $e->getGrade(),
                'description' => $e->getDescription(),
            ], $profile->getEducations()->toArray()),
            'skills' => array_map(static fn (SkillGroup $g) => $id($g->getId()) + [
                'group' => $g->getName(),
                'skills' => array_map(static fn (Skill $s) => ['name' => $s->getName(), 'level' => $s->getLevel()], $g->getSkills()->toArray()),
            ], $profile->getSkillGroups()->toArray()),
            'languages' => array_map(static fn (Language $l) => $l->toArray(), $profile->getLanguages()->toArray()),
            'certifications' => array_map(static fn (Certification $c) => $c->toArray(), $profile->getCertifications()->toArray()),
            'projects' => array_map(static fn (Project $p) => $id($p->getId()) + [
                'name' => $p->getName(),
                'role' => $p->getRole(),
                'start' => $p->getStartDate(),
                'end' => $p->getEndDate(),
                'url' => $p->getUrl(),
                'tech' => $p->getTech(),
                'description' => $p->getDescription(),
                'highlights' => $p->getHighlights(),
            ], $profile->getProjects()->toArray()),
            'publications' => array_map(static fn (Publication $p) => $p->toArray(), $profile->getPublications()->toArray()),
            'custom_sections' => array_map(static fn (CustomSection $c) => $c->toArray(), $profile->getCustomSections()->toArray()),
        ];
    }

    /**
     * Write profile JSON into the master profile.
     *
     * @param array<string, mixed> $data
     * @param list<string>|null    $sections JSON section keys to import (null = all), "personal" included
     * @param string               $mode     "replace" clears the imported sections first, "merge" appends
     */
    public function apply(MasterProfile $profile, array $data, ?array $sections = null, string $mode = 'replace'): void
    {
        $wanted = static fn (string $key): bool => $sections === null || \in_array($key, $sections, true);

        if ($wanted('personal') && \is_array($data['personal'] ?? null)) {
            $personal = $data['personal'];
            if ($mode === 'merge') {
                // Keep existing values; only fill what is empty
                $current = $profile->getPersonal()->toArray();
                $personal = array_filter($personal, static fn ($v, $k) => ($current[$k] ?? null) === null && $v !== null && $v !== '', \ARRAY_FILTER_USE_BOTH);
            }
            $profile->getPersonal()->fill($personal);
        }

        foreach (self::SECTION_KEYS as $key => $property) {
            if (!$wanted($key)) {
                continue;
            }
            $items = $data[$key] ?? null;
            if (!\is_array($items)) {
                continue;
            }
            if ($mode === 'replace') {
                $getter = 'get'.ucfirst($property);
                foreach ($profile->{$getter}()->toArray() as $existing) {
                    $profile->{$getter}()->removeElement($existing);
                }
            }
            foreach ($items as $item) {
                if (\is_array($item)) {
                    $this->addItem($profile, $key, $item);
                }
            }
        }

        $profile->renumber();
        $profile->touch();
    }

    /**
     * @param array<mixed> $item
     */
    private function addItem(MasterProfile $profile, string $key, array $item): void
    {
        $s = static fn (string $k): ?string => isset($item[$k]) && \is_scalar($item[$k]) && trim((string) $item[$k]) !== '' ? trim((string) $item[$k]) : null;

        switch ($key) {
            case 'summaries':
                if ($s('content') !== null) {
                    $profile->addSummary((new ProfileSummary())->setLabel($s('label') ?? 'Summary')->setContent($s('content')));
                }
                break;
            case 'experiences':
                if ($s('title') === null && $s('company') === null) {
                    return;
                }
                $experience = (new Experience())
                    ->setTitle($s('title') ?? (string) $s('company'))
                    ->setCompany($s('company'))
                    ->setLocation($s('location'))
                    ->setStartDate($s('start'))
                    ->setEndDate($s('end'))
                    ->setDescription($s('description'))
                    ->setTech($s('tech'));
                foreach ($this->strings($item['bullets'] ?? []) as $text) {
                    $experience->addBullet(new ExperienceBullet($text));
                }
                foreach (\is_array($item['projects'] ?? null) ? $item['projects'] : [] as $p) {
                    if (!\is_array($p) || !isset($p['name']) || !\is_scalar($p['name'])) {
                        continue;
                    }
                    $project = (new ExperienceProject())
                        ->setName((string) $p['name'])
                        ->setUrl(isset($p['url']) && \is_scalar($p['url']) ? (string) $p['url'] : null)
                        ->setPeriod(isset($p['period']) && \is_scalar($p['period']) ? (string) $p['period'] : null)
                        ->setDescription(isset($p['description']) && \is_scalar($p['description']) ? (string) $p['description'] : null)
                        ->setTech(isset($p['tech']) && \is_scalar($p['tech']) ? (string) $p['tech'] : null);
                    foreach ($this->strings($p['bullets'] ?? []) as $text) {
                        $project->addBullet(new ExperienceBullet($text));
                    }
                    $experience->addProject($project);
                }
                $profile->addExperience($experience);
                break;
            case 'education':
                if ($s('degree') !== null) {
                    $profile->addEducation((new Education())->setDegree($s('degree'))->setInstitution($s('institution'))->setLocation($s('location'))
                        ->setStartDate($s('start'))->setEndDate($s('end'))->setGrade($s('grade'))->setDescription($s('description')));
                }
                break;
            case 'skills':
                if ($s('group') === null) {
                    return;
                }
                $group = (new SkillGroup())->setName($s('group'));
                foreach (\is_array($item['skills'] ?? null) ? $item['skills'] : [] as $skill) {
                    $name = \is_array($skill) ? ($skill['name'] ?? null) : $skill;
                    if (!\is_scalar($name) || trim((string) $name) === '') {
                        continue;
                    }
                    $level = \is_array($skill) && isset($skill['level']) && \is_scalar($skill['level']) ? (string) $skill['level'] : null;
                    $group->addSkill((new Skill())->setName((string) $name)->setLevel($level));
                }
                $profile->addSkillGroup($group);
                break;
            case 'languages':
                if ($s('name') !== null) {
                    $profile->addLanguage((new Language())->fill($item));
                }
                break;
            case 'certifications':
                if ($s('name') !== null) {
                    $profile->addCertification((new Certification())->fill($item));
                }
                break;
            case 'projects':
                if ($s('name') !== null) {
                    $profile->addProject((new Project())->setName($s('name'))->setRole($s('role'))->setStartDate($s('start'))->setEndDate($s('end'))
                        ->setUrl($s('url'))->setTech($s('tech'))->setDescription($s('description'))->setHighlights($this->strings($item['highlights'] ?? [])));
                }
                break;
            case 'publications':
                if ($s('title') !== null) {
                    $profile->addPublication((new Publication())->fill($item));
                }
                break;
            case 'custom_sections':
                if ($s('title') !== null) {
                    $profile->addCustomSection((new CustomSection())->fill($item + ['kind' => 'custom']));
                }
                break;
        }
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            if (\is_array($value)) {
                $value = $value['text'] ?? null;
            }
            if (\is_scalar($value) && trim((string) $value) !== '') {
                $out[] = trim((string) $value);
            }
        }

        return $out;
    }

    /**
     * Counts per JSON section key, for the import review screen.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    public static function counts(array $data): array
    {
        $counts = [];
        foreach (array_keys(self::SECTION_KEYS) as $key) {
            $counts[$key] = \is_array($data[$key] ?? null) ? \count($data[$key]) : 0;
        }

        return $counts;
    }
}
