<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * JSON 401 answers for the REST API and the MCP endpoint.
 */
final class ApiEntryPoint implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized('Authentication required: send "Authorization: Bearer <token>" (create one under Account → API tokens).');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized('Invalid or expired API token.');
    }

    private function unauthorized(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 401, ['WWW-Authenticate' => 'Bearer realm="CVolve"']);
    }
}
