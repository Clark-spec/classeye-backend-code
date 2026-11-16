<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Adapter for the OpenAI chat-completions shape. It also covers Ollama and
 * other self-hosted servers that expose the same API, which keeps the school
 * deployment free of vendor lock-in.
 */
class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(
        private readonly string $provider,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
        private readonly int $maxTokens,
    ) {}

    public function name(): string
    {
        return $this->provider;
    }

    public function model(): ?string
    {
        return $this->model !== '' ? $this->model : null;
    }

    public function answer(string $question, array $context, array $history = []): string
    {
        $body = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => Prompt::system()],
                ['role' => 'user',   'content' => Prompt::user($question, $context, $history)],
            ],
            'max_tokens'  => $this->maxTokens,
            'temperature' => 0.2,
            'stream'      => false,
        ];

        $response = $this->request()->post($this->endpoint(), $body);

        if ($response->failed()) {
            // Status only: response bodies can echo prompts or keys.
            throw new RuntimeException(sprintf(
                'AI provider request failed with status %d.',
                $response->status()
            ));
        }

        $text = $response->json('choices.0.message.content');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('AI provider returned an empty answer.');
        }

        return trim($text);
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson();

        if ($this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        return $request;
    }

    private function endpoint(): string
    {
        $base = rtrim($this->baseUrl, '/');

        // Self-hosted Ollama servers sometimes receive base URLs without /v1
        if (! str_ends_with($base, '/v1')) {
            $base .= '/v1';
        }

        return $base.'/chat/completions';
    }
}
