<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Service\DemoSeeder;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Admin (admin@cvolve.local / admin1234), demo user (demo@cvolve.local / demo1234) with the
 * master profile from fixtures/demo_profile.json, two CVs and the preset templates.
 */
class AppFixtures extends Fixture
{
    public function __construct(private readonly DemoSeeder $seeder)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $this->seeder->installPresets();
        $this->seeder->createUser('admin@cvolve.local', 'admin1234', 'Administrator', true);
        $this->seeder->seedDemoUser();
    }
}
