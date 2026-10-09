<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Profile\MasterProfile;
use App\Entity\User;
use App\Repository\TemplateRepository;
use App\Repository\UserRepository;
use App\Service\Cv\CvBuilder;
use App\Service\Cv\PhotoStorage;
use App\Service\Cv\ProfileSerializer;
use Cvolve\TemplateKit\SectionTypes;
use App\Service\Cv\VersionManager;
use App\Service\Template\TemplateInstaller;
use Cvolve\TemplateKit\Presets;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Installs the preset templates and creates the admin and demo accounts.
 * Used by the fixtures (dev) and by "app:seed-demo" (any environment, e.g. the Docker entrypoint).
 */
final class DemoSeeder
{
    public const DEMO_EMAIL = 'demo@cvolve.local';
    public const DEMO_PASSWORD = 'demo1234';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly TemplateRepository $templates,
        private readonly TemplateInstaller $installer,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ProfileSerializer $serializer,
        private readonly CvBuilder $builder,
        private readonly VersionManager $versions,
        private readonly PhotoStorage $photos,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return list<string> installed template names
     */
    public function installPresets(): array
    {
        $installed = [];
        foreach (Presets::all() as $dir) {
            $installed[] = $this->installer->installDirectory($dir)->getName();
        }

        return $installed;
    }

    public function createUser(string $email, string $password, string $name, bool $admin = false): User
    {
        $user = $this->users->findOneByEmail($email) ?? new User();
        $user->setEmail($email)->setFullName($name)->setIsVerified(true)->setEnabled(true)->setAdmin($admin);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        if ($user->getMasterProfile() === null) {
            $profile = new MasterProfile();
            $profile->getPersonal()->setFullName($name)->setEmail($email);
            $user->setMasterProfile($profile);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * The demo user: master profile from fixtures/demo_profile.json and two CVs.
     */
    public function seedDemoUser(): User
    {
        $user = $this->createUser(self::DEMO_EMAIL, self::DEMO_PASSWORD, 'Alex Morgan');
        $profile = $user->getMasterProfile();
        \assert($profile !== null);

        $data = json_decode((string) file_get_contents($this->projectDir.'/fixtures/demo_profile.json'), true, 64, \JSON_THROW_ON_ERROR);
        $this->serializer->apply($profile, $data, null, 'replace');
        $this->em->flush();

        $photo = $this->projectDir.'/assets/photos/demo.jpg';
        if (is_file($photo) && $profile->getPersonal()->getPhoto() === null) {
            $profile->getPersonal()->setPhoto($this->photos->store($user, $photo));
        }
        $this->em->flush();

        $software = $this->templates->findPreset('cv-software');
        $classic = $this->templates->findPreset('classic') ?? $software;
        if ($software === null) {
            return $user;
        }

        $summaries = $profile->getSummaries()->toArray();
        $experiences = $profile->getExperiences()->toArray();

        $cv = $this->builder->create($user, 'Senior Backend Engineer (PHP + React)', $software, 'en', [
            'summary_id' => ($summaries[1] ?? $summaries[0] ?? null)?->getId(),
            'section_types' => ['summary', 'experience', 'projects', 'education', 'skills', 'languages', 'certifications', 'publications'],
        ]);
        $variant = $cv->getDefaultVariant();
        $variant->setHeader(['headline' => 'Senior Full-Stack Backend Engineer (PHP + React)'] + $variant->getHeader());
        $this->em->persist($cv);

        $healthcare = $this->builder->create($user, 'Healthcare Software Engineer', $classic, 'en', [
            'summary_id' => ($summaries[2] ?? $summaries[0] ?? null)?->getId(),
            'experience_ids' => array_values(array_filter(array_map(static fn ($e) => $e->getId(), $experiences))),
        ]);
        $this->em->persist($healthcare);
        $this->em->flush();

        // An Italian copy so the language switcher has something to show
        $italian = new \App\Entity\CvVariant($healthcare, 'it');
        $snapshot = $this->versions->snapshot($healthcare->getDefaultVariant());
        foreach ($snapshot['sections'] as &$section) {
            if ($section['title'] === SectionTypes::defaultTitle($section['type'], 'en')) {
                $section['title'] = SectionTypes::defaultTitle($section['type'], 'it');
            }
        }
        unset($section);
        $this->versions->load($italian, $snapshot);
        $this->em->persist($italian);
        $this->versions->record($healthcare->getDefaultVariant(), 'Initial version');
        $this->em->flush();

        return $user;
    }
}
