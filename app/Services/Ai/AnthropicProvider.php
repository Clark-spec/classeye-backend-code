<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter for Anthropic's Messages API. The request shape differs from the
 * OpenAI one, so it gets its own implementation behind the same contract.
 */
class AnthropicProvider implements AiProvider
{
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        private readonly int $maxTokens,
    ) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): ?string
    {
        return $this->model !== '' ? $this->model : null;
    }

    public function answer(string $question, array $context, array $history = []): string
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
            ->acceptJson()
            ->asJson()
            ->post(rtrim($this->baseUrl, '/').'/messages', [
                'model' => $this->model,
                'max_tokens' => $this->maxTokens,
                'temperature' => 0.2,
                'system' => Prompt::system(),
                'messages' => [
                    ['role' => 'user', 'content' => Prompt::user($question, $context, $history)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'AI provider request failed with status %d.',
                $response->status()
            ));
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? null;

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('AI provider returned an empty answer.');
        }

        return trim($text);
    }
}
