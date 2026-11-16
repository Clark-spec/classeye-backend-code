<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use RuntimeException;

/**
 * Config-driven provider selection.
 *
 * The manager never lets a misconfigured or unreachable external provider take
 * the feature down: resolve() reports whether it had to fall back so callers
 * can surface a "degraded" flag without exposing configuration details.
 */
class AiManager
{
    private const DEFAULT_BASE_URLS = [
        'openai' => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com/v1',
        'ollama' => 'http://localhost:11434/v1',
        'groq' => 'https://api.groq.com/openai/v1',
    ];

    public function __construct(private readonly HeuristicProvider $heuristic) {}

    /**
     * @return array{provider: AiProvider, degraded: bool}
     */
    public function resolve(): array
    {
        $name = strtolower(trim((string) config('ai.provider', 'heuristic')));

        if ($name === 'heuristic' || ! (bool) config('ai.external_enabled', false)) {
            return ['provider' => $this->heuristic, 'degraded' => false];
        }

        try {
            return ['provider' => $this->build($name), 'degraded' => false];
        } catch (RuntimeException) {
            return ['provider' => $this->heuristic, 'degraded' => true];
        }
    }

    public function fallback(): AiProvider
    {
        return $this->heuristic;
    }

    private function build(string $name): AiProvider
    {
        $baseUrl = rtrim((string) config('ai.base_url', ''), '/');
        $apiKey = trim((string) config('ai.api_key', ''));
        $model = trim((string) config('ai.model', ''));
        $timeout = max(1, (int) config('ai.timeout_seconds', 10));
        $maxTokens = max(64, (int) config('ai.max_tokens', 400));

        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URLS[$name] ?? '';
        }

        if ($baseUrl === '') {
            throw new RuntimeException('Unknown AI provider.');
        }

        if ($model === '') {
            if ($name === 'ollama') {
                $model = 'llama3.2:1b';
            } elseif ($name === 'groq') {
                $model = 'qwen/qwen3.8-27b';
            } else {
                throw new RuntimeException('AI model is not configured.');
            }
        }

        if ($name === 'anthropic') {
            if ($apiKey === '') {
                throw new RuntimeException('AI API key is not configured.');
            }

            return new AnthropicProvider($baseUrl, $apiKey, $model, $timeout, $maxTokens);
        }

        if ($name !== 'openai' && $name !== 'ollama' && $name !== 'groq') {
            throw new RuntimeException('Unknown AI provider.');
        }

        if (($name === 'openai' || $name === 'groq') && $apiKey === '' && ! $this->isLocalUrl($baseUrl)) {
            throw new RuntimeException('AI API key is not configured.');
        }

        return new OpenAiCompatibleProvider(
            $name,
            $baseUrl,
            $apiKey,
            $model,
            $timeout,
            $maxTokens
        );
    }

    /**
     * Local and private endpoints (Ollama, LM Studio, a school gateway) do not
     * need a cloud key.
     */
    private function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'ollama'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.)/',
            $host
        );
    }
}
