<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\ChatConversation;
use App\Entity\ChatMessage;
use App\Entity\CvVariant;
use App\Service\Tool\CvToolbox;
use App\Service\Tool\ToolContext;
use App\Service\Tool\ToolDefinition;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;

/**
 * The CV editor chat: a Neuron AI agent whose tools are the shared CvToolbox.
 * Without a configured provider it still understands a few plain commands.
 */
final class ChatAgent
{
    private const HISTORY_LIMIT = 20;

    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly CvToolbox $toolbox,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->providers->isConfigured();
    }

    /**
     * Answer one user message. Persists both messages and returns the assistant's reply.
     */
    public function respond(ChatConversation $conversation, CvVariant $variant, string $text): ChatMessage
    {
        $history = \array_slice($conversation->getMessages()->toArray(), -self::HISTORY_LIMIT);
        $userMessage = new ChatMessage($conversation, ChatMessage::ROLE_USER, $text);
        $this->em->persist($userMessage);
        $this->em->flush();

        $context = new ToolContext($conversation->getOwner(), 'chat', $conversation->getCv(), $conversation, $variant->getLocale());
        try {
            $reply = $this->isAvailable() ? $this->runAgent($context, $variant, $history, $text) : $this->offline($context, $text);
        } catch (\Throwable $e) {
            $reply = sprintf("⚠️ The AI provider (%s) returned an error: %s\n\n%s", $this->providers->label(), $e->getMessage(),
                preg_match('/\b(401|403)\b|api.?key|unauthori[sz]ed/i', $e->getMessage()) ? 'Check the API key in .env.local.' : 'Please try again in a moment.');
        }

        $assistant = new ChatMessage($conversation, ChatMessage::ROLE_ASSISTANT, $reply !== '' ? $reply : 'Done.', [
            ...array_map(static fn (array $call) => $call, $context->calls),
            ...array_map(static fn (int $id) => ['proposal' => $id], $context->proposalIds),
        ]);
        $this->em->persist($assistant);
        $this->em->flush();

        return $assistant;
    }

    /**
     * @param list<ChatMessage> $history
     */
    private function runAgent(ToolContext $context, CvVariant $variant, array $history, string $text): string
    {
        set_time_limit(300);
        $cv = $context->cv ?? throw new \LogicException('The chat needs an open CV.');

        $messages = [];
        foreach ($history as $message) {
            $messages[] = $message->getRole() === ChatMessage::ROLE_ASSISTANT
                ? new AssistantMessage($message->getContent())
                : new UserMessage($message->getContent());
        }
        $messages[] = new UserMessage($text);

        $agent = Agent::make()
            ->setAiProvider($this->providers->make())
            ->setInstructions(Prompts::chatSystem($cv, $variant, true))
            ->setTools($this->neuronTools($context))
            ->setThreadId(bin2hex(random_bytes(8)));

        return trim((string) $agent->chat($messages)->getMessage()?->getContent());
    }

    /**
     * @return list<CallbackTool>
     */
    public function neuronTools(ToolContext $context): array
    {
        $tools = [];
        foreach ($this->toolbox->definitions() as $definition) {
            $tools[] = new CallbackTool(
                $definition->name,
                $definition->description,
                $this->properties($definition),
                function (array $inputs) use ($definition, $context): string {
                    $args = array_filter($inputs, static fn ($v) => $v !== null);

                    return json_encode($this->toolbox->call($definition->name, $args, $context), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) ?: '{}';
                },
            );
        }

        return $tools;
    }

    /**
     * Translate a tool's JSON Schema properties into Neuron tool properties.
     *
     * @return list<ToolPropertyInterface>
     */
    private function properties(ToolDefinition $definition): array
    {
        $properties = [];
        foreach ($definition->properties as $name => $schema) {
            $required = \in_array($name, $definition->required, true);
            $description = $schema['description'] ?? null;
            if (($schema['type'] ?? '') === 'array') {
                $itemType = self::type($schema['items']['type'] ?? 'string');
                $properties[] = new ArrayProperty($name, $description, $required, new ToolProperty('item', $itemType));
                continue;
            }
            $properties[] = new ToolProperty($name, self::type($schema['type'] ?? 'string'), $description, $required, $schema['enum'] ?? []);
        }

        return $properties;
    }

    private static function type(string $type): PropertyType
    {
        return match ($type) {
            'integer' => PropertyType::INTEGER,
            'number' => PropertyType::NUMBER,
            'boolean' => PropertyType::BOOLEAN,
            default => PropertyType::STRING,
        };
    }

    /**
     * Plain-command fallback when no AI provider is configured.
     */
    private function offline(ToolContext $context, string $text): string
    {
        $t = mb_strtolower($text);
        $profile = $context->user->getMasterProfile();

        if (preg_match('/summary|profil|riassunto/u', $t) && preg_match('/master|profile|my info|add|aggiungi/u', $t)) {
            $summary = $profile?->getSummaries()->first();
            if (!$summary) {
                return 'Your master profile has no summary yet. Add one under Master profile → Summaries.';
            }
            $result = $this->toolbox->call('set_professional_summary', ['text' => $summary->getContent()], $context);

            return isset($result['error']) ? '⚠️ '.$result['error'] : sprintf('I proposed your master summary "%s". Review it below and click Apply.', $summary->getLabel());
        }
        if (preg_match('/\b(check|review|controlla)\b/u', $t)) {
            $report = $this->toolbox->call('check_cv', [], $context);

            return "CV check:\n".implode("\n", array_map(static fn (array $f) => sprintf('- [%s] %s', $f['level'], $f['message']), $report['findings'] ?? []));
        }
        if (preg_match('/\b(pdf|docx|word)\b/u', $t) && preg_match('/export|download|scarica/u', $t)) {
            $format = str_contains($t, 'pdf') ? 'pdf' : 'docx';
            $result = $this->toolbox->call('export_cv', ['format' => $format], $context);

            return isset($result['error']) ? '⚠️ '.$result['error'] : sprintf('Export started. [Download the %s](%s) when it is ready.', strtoupper($format), $result['status_url']);
        }
        if (preg_match('/\badd\b.*\b(experience|education|skills|languages|certifications|projects|publications)\b/u', $t, $m)) {
            $type = $m[1];
            $result = $this->toolbox->call('add_section', ['type' => $type, 'from_master' => true], $context);

            return isset($result['error']) ? '⚠️ '.$result['error'] : sprintf('I proposed a %s section from your master profile. Review it below and click Apply.', $type);
        }

        return "No AI provider is configured, so I only understand a few commands:\n"
            ."- \"add professional summary from my master info\"\n"
            ."- \"add experience\" / \"add education\" / \"add skills\" (from the master profile)\n"
            ."- \"check my CV\"\n"
            ."- \"export pdf\" / \"export docx\"\n\n"
            .'Set AI_PROVIDER and an API key in .env.local to unlock the full assistant (see README).';
    }
}
