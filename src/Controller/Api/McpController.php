<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Mcp\McpServer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * MCP endpoint (streamable HTTP transport, JSON responses only).
 * Connect with: Authorization: Bearer cvk_...
 */
class McpController extends AbstractController
{
    #[Route('/mcp', name: 'mcp', methods: ['POST'])]
    public function handle(Request $request, McpServer $server): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(McpServer::error(null, -32700, 'Parse error: invalid JSON'), 400);
        }

        // A JSON-RPC batch is a list of messages
        if (array_is_list($payload) && $payload !== []) {
            $responses = array_values(array_filter(array_map(static fn ($m) => \is_array($m) ? $server->handle($m, $user) : McpServer::error(null, -32600, 'Invalid Request'), $payload)));

            return $responses === [] ? new Response(status: 202) : new JsonResponse($responses);
        }

        $response = $server->handle($payload, $user);

        return $response === null ? new Response(status: 202) : new JsonResponse($response);
    }

    #[Route('/mcp', name: 'mcp_get', methods: ['GET', 'DELETE'])]
    public function notStreaming(): Response
    {
        // No server-initiated messages: tell clients to use POST only
        return new Response(null, 405, ['Allow' => 'POST']);
    }
}
