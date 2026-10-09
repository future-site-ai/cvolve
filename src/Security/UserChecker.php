<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserChecker implements UserCheckerInterface
{
    public function __construct(
        #[Autowire('%env(bool:REQUIRE_EMAIL_VERIFICATION)%')]
        private readonly bool $requireVerification,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }
        if (!$user->isEnabled()) {
            throw new CustomUserMessageAccountStatusException('This account has been disabled by an administrator.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof User && $this->requireVerification && !$user->isVerified()) {
            throw new CustomUserMessageAccountStatusException('Please confirm your email address first. Check your inbox for the verification link.');
        }
    }
}
