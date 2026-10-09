<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\User;
use App\Service\Tool\CvToolbox;
use App\Service\Tool\ToolContext;

/**
 * JSON-RPC 2.0 Model Context Protocol server (streamable HTTP, JSON responses).
 * Exposes the same CvToolbox the in-app chat uses, acting as the token's owner.
 */
final class McpServer
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const INSTRUCTIONS = 'CVolve manages CVs built from a master profile. Read with list_cvs, get_cv and get_master_profile. '
        .'Tools that change a CV create proposals: the user reviews and applies them in the CVolve editor, so tell the user to review there. '
        .'Templates control all styling; only content can be changed.';

    public function __construct(private readonly CvToolbox $toolbox)
    {
    }

    /**
     * Handle one JSON-RPC message. Returns null for notifications (no response body).
     *
     * @param array<mixed> $message
     *
     * @return array<string, mixed>|null
     */
    public function handle(array $message, User $user): ?array
    {
        $id = $message['id'] ?? null;
        $method = \is_string($message['method'] ?? null) ? $message['method'] : '';
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        if (($message['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return self::error($id, -32600, 'Invalid Request');
        }
        // Notifications carry no id and get no response
        if (!\array_key_exists('id', $message)) {
            return null;
        }

        try {
            return match ($method) {
                'initialize' => self::result($id, [
                    'protocolVersion' => \in_array($params['protocolVersion'] ?? null, self::SUPPORTED_VERSIONS, true) ? $params['protocolVersion'] : self::SUPPORTED_VERSIONS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'cvolve', 'title' => 'CVolve', 'version' => '1.0.0'],
                    'instructions' => self::INSTRUCTIONS,
                ]),
                'ping' => self::result($id, new \stdClass()),
                'tools/list' => self::result($id, ['tools' => $this->tools()]),
                'tools/call' => $this->callTool($id, $params, $user),
                default => self::error($id, -32601, sprintf("Method '%s' not found", $method)),
            };
        } catch (\Throwable $e) {
            return self::error($id, -32603, 'Internal error: '.$e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tools(): array
    {
        $tools = [];
        foreach ($this->toolbox->definitions() as $definition) {
            $tools[] = [
                'name' => $definition->name,
                'description' => $definition->description.($definition->usesAi ? ' (Uses the AI provider configured in CVolve.)' : ''),
                'inputSchema' => $definition->inputSchema(),
            ];
        }

        return $tools;
    }

    /**
     * @param array<mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params, User $user): array
    {
        $name = \is_string($params['name'] ?? null) ? $params['name'] : '';
        if ($this->toolbox->get($name) === null) {
            return self::error($id, -32602, sprintf('Unknown tool: %s', $name));
        }
        $arguments = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $result = $this->toolbox->call($name, $arguments, new ToolContext($user, 'mcp'));

        return self::result($id, [
            'content' => [['type' => 'text', 'text' => json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]],
            'structuredContent' => (object) $result,
            'isError' => isset($result['error']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
