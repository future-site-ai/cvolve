<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Template;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * USE: published presets and the user's own private templates.
 * MANAGE: the owner of a private template, or an admin for presets.
 *
 * @extends Voter<string, Template>
 */
final class TemplateVoter extends Voter
{
    public const USE = 'TEMPLATE_USE';
    public const MANAGE = 'TEMPLATE_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::USE, self::MANAGE], true) && $subject instanceof Template;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($subject->isPreset()) {
            return $attribute === self::USE ? $subject->isPublished() || $user->isAdmin() : $user->isAdmin();
        }

        return $subject->getOwner() === $user;
    }
}
