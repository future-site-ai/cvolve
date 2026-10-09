<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * An entity that belongs to one user. OwnerVoter grants access only to that user (and admins where allowed).
 */
interface OwnedInterface
{
    public function getOwner(): ?User;
}
