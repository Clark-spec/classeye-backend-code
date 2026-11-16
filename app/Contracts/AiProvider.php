<?php

namespace App\Contracts;

/**
 * Strategy contract for the assistant layer. Implementations receive a
 * server-built, read-only context array and must return plain text.
 */
interface AiProvider
{
    public function name(): string;

    public function model(): ?string;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function answer(string $question, array $context, array $history = []): string;
}
