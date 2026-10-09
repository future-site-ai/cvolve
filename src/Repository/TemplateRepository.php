<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Template;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Template>
 */
class TemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Template::class);
    }

    /**
     * Published presets plus the user's own private templates.
     *
     * @return list<Template>
     */
    public function findAvailableFor(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('(t.owner IS NULL AND t.published = true) OR t.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('t.owner', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()->getResult();
    }

    public function isAvailableFor(Template $template, User $user): bool
    {
        return $template->isPreset() ? $template->isPublished() : $template->getOwner() === $user;
    }

    public function findPreset(string $slug): ?Template
    {
        return $this->findOneBy(['slug' => $slug, 'owner' => null]);
    }

    /** @return list<Template> */
    public function findPresets(): array
    {
        return $this->findBy(['owner' => null], ['name' => 'ASC']);
    }
}
