<?php

declare(strict_types=1);

namespace App\Service\Ai;

use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Providers\XAI\Grok;

/**
 * Builds the Neuron AI provider selected in .env.local (same variables as Plate Plan).
 *
 *   AI_PROVIDER  gemini (default) | openai | anthropic | ollama | mistral | deepseek | grok | openai_like
 *   AI_MODEL     model name (optional for providers with a default below)
 *   AI_API_KEY   API key (falls back to the provider-specific variable, e.g. OPENAI_API_KEY)
 *   AI_BASE_URL  endpoint for ollama / openai_like
 */
class ProviderFactory
{
    /** Provider => [api key variable, default model] */
    protected const PROVIDERS = [
        'gemini' => ['GEMINI_API_KEY', 'gemini-2.5-flash'],
        'openai' => ['OPENAI_API_KEY', 'gpt-4o-mini'],
        'anthropic' => ['ANTHROPIC_API_KEY', 'claude-sonnet-5-5'],
        'ollama' => [null, 'llama3.1'],
        'mistral' => ['MISTRAL_API_KEY', 'mistral-large-latest'],
        'deepseek' => ['DEEPSEEK_API_KEY', 'deepseek-chat'],
        'grok' => ['XAI_API_KEY', null],
        'openai_like' => [null, null],
    ];

    protected const DEFAULT_OLLAMA_URL = 'http://host.docker.internal:11434/api';

    private ?AIProviderInterface $override = null;

    public function providerName(): string
    {
        $name = strtolower($this->env('AI_PROVIDER') ?: 'gemini');
        if (!\array_key_exists($name, self::PROVIDERS)) {
            throw new \InvalidArgumentException("Unknown AI_PROVIDER '{$name}'. Use one of: ".implode(', ', array_keys(self::PROVIDERS)));
        }

        return $name;
    }

    public function model(): string
    {
        $provider = $this->providerName();
        $model = $this->env('AI_MODEL') ?: (string) self::PROVIDERS[$provider][1];
        if ($model === '') {
            throw new \InvalidArgumentException("Set AI_MODEL in .env.local for AI_PROVIDER={$provider}.");
        }

        return $model;
    }

    public function apiKey(): string
    {
        $key = $this->env('AI_API_KEY');
        $providerVar = self::PROVIDERS[$this->providerName()][0];
        if ($key === '' && $providerVar !== null) {
            $key = $this->env($providerVar);
        }

        // Ignore the untouched placeholder from .env.example
        return str_starts_with($key, 'your_') ? '' : $key;
    }

    /**
     * Whether the selected provider has what it needs to be called.
     */
    public function isConfigured(): bool
    {
        if ($this->override !== null) {
            return true;
        }
        if ($this->env('AI_PROVIDER') === 'none') {
            return false;
        }
        try {
            $provider = $this->providerName();
            $this->model();
        } catch (\InvalidArgumentException) {
            return false;
        }

        return match ($provider) {
            'ollama' => true,
            'openai_like' => $this->env('AI_BASE_URL') !== '',
            default => $this->apiKey() !== '',
        };
    }

    public function label(): string
    {
        if ($this->override !== null) {
            return 'test provider';
        }
        try {
            return $this->providerName().' / '.$this->model();
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    public function make(): AIProviderInterface
    {
        if ($this->override !== null) {
            return $this->override;
        }
        $provider = $this->providerName();
        $model = $this->model();
        $key = $this->apiKey();

        return match ($provider) {
            'gemini' => new Gemini(key: $key, model: $model),
            'openai' => new OpenAI(key: $key, model: $model),
            'anthropic' => new Anthropic(key: $key, model: $model),
            'ollama' => new Ollama(url: $this->env('AI_BASE_URL') ?: self::DEFAULT_OLLAMA_URL, model: $model),
            'mistral' => new Mistral(key: $key, model: $model),
            'deepseek' => new Deepseek(key: $key, model: $model),
            'grok' => new Grok(key: $key, model: $model),
            'openai_like' => new OpenAILike(baseUri: $this->env('AI_BASE_URL'), key: $key, model: $model),
            default => throw new \InvalidArgumentException("Unknown AI_PROVIDER '{$provider}'."),
        };
    }

    /**
     * Use a fixed provider (tests use Neuron's FakeAIProvider).
     */
    public function override(?AIProviderInterface $provider): void
    {
        $this->override = $provider;
    }

    protected function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return trim((string) ($value ?: ''));
    }
}
