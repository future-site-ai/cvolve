<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Authenticates REST API and MCP requests sent with "Authorization: Bearer cvk_...".
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $token = str_starts_with($accessToken, ApiToken::PREFIX) ? $this->tokens->findOneBy(['tokenHash' => ApiToken::hash($accessToken)]) : null;
        if ($token === null || !$token->isValid() || !$token->getOwner()->isEnabled()) {
            throw new BadCredentialsException('Invalid or expired API token.');
        }

        // Record usage at most once a minute to avoid a write per request
        if ($token->getLastUsedAt() === null || $token->getLastUsedAt() < new \DateTimeImmutable('-1 minute')) {
            $token->markUsed();
            $this->em->flush();
        }
        $owner = $token->getOwner();

        return new UserBadge($owner->getUserIdentifier(), static fn () => $owner);
    }
}
