<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Profile\MasterProfile;
use App\Entity\User;
use App\Service\DemoSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Fresh SQLite schema per test, plus helpers to create users and the preset templates.
 */
trait DatabaseTestTrait
{
    protected function resetDatabase(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        return $em;
    }

    protected function seeder(): DemoSeeder
    {
        return static::getContainer()->get(DemoSeeder::class);
    }

    protected function createUser(string $email = 'jane@example.com', bool $admin = false): User
    {
        return $this->seeder()->createUser($email, 'password123', 'Jane Doe', $admin);
    }

    protected function profileOf(User $user): MasterProfile
    {
        $profile = $user->getMasterProfile();
        \assert($profile !== null);

        return $profile;
    }
}
