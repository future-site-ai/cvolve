<?php

declare(strict_types=1);

namespace App\Service\Tool;

/**
 * One CV tool, described with a JSON Schema so the same definition drives the
 * in-app chat (Neuron AI), the MCP server and the REST API.
 */
final class ToolDefinition
{
    /**
     * @param array<string, mixed>                                    $properties JSON Schema properties
     * @param list<string>                                            $required
     * @param \Closure(ToolContext, array<string, mixed>): array<mixed> $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $properties,
        public readonly array $required,
        public readonly \Closure $handler,
        public readonly bool $usesAi = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        $schema = ['type' => 'object', 'properties' => $this->properties === [] ? new \stdClass() : $this->properties];
        if ($this->required !== []) {
            $schema['required'] = $this->required;
        }

        return $schema;
    }
}
