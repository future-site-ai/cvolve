<?php

declare(strict_types=1);

namespace App\Service\Ai;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * One-shot completions (no tools), used by the generative CV tools and the import parser.
 */
class AiTextService
{
    public function __construct(private readonly ProviderFactory $providers)
    {
    }

    public function isAvailable(): bool
    {
        return $this->providers->isConfigured();
    }

    public function complete(string $instructions, string $prompt): string
    {
        if (!$this->providers->isConfigured()) {
            throw new AiUnavailableException();
        }
        set_time_limit(300);

        $state = Agent::make()
            ->setAiProvider($this->providers->make())
            ->setInstructions($instructions)
            ->setThreadId(bin2hex(random_bytes(8)))
            ->chat(new UserMessage($prompt));

        return trim((string) $state->getMessage()?->getContent());
    }

    /**
     * Ask for JSON and decode it, retrying once if the model wraps or breaks it.
     *
     * @return array<mixed>
     */
    public function completeJson(string $instructions, string $prompt): array
    {
        $instructions .= "\n\nAnswer with a single valid JSON value only: no markdown fences, no commentary.";
        $answer = $this->complete($instructions, $prompt);
        $data = self::decode($answer);
        if ($data === null) {
            $answer = $this->complete($instructions, $prompt."\n\nYour previous answer was not valid JSON. Return only the JSON.");
            $data = self::decode($answer);
        }
        if ($data === null) {
            throw new \RuntimeException('The AI did not return valid JSON.');
        }

        return $data;
    }

    /**
     * @return array<mixed>|null
     */
    public static function decode(string $answer): ?array
    {
        $answer = trim($answer);
        if (preg_match('/```(?:json)?\s*(.*?)```/s', $answer, $m)) {
            $answer = trim($m[1]);
        }
        // Cut anything before the first { or [ and after the matching last } or ]
        $start = strcspn($answer, '{[');
        $end = max((int) strrpos($answer, '}'), (int) strrpos($answer, ']'));
        if ($start >= \strlen($answer) || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($answer, $start, $end - $start + 1), true);

        return \is_array($data) ? $data : null;
    }
}
