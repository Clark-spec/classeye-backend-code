<?php

namespace App\Services\Ai;

use App\Services\Insights\OccupancyInsightsService;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Builds the read-only context, asks the configured provider, and degrades to
 * the local provider when an external call fails.
 *
 * The context contains room aggregates only. Staff names, staff IDs and free
 * text logs are never included, so a third-party model cannot receive personal
 * data by accident.
 */
class AssistantService
{
    public function __construct(
        private readonly OccupancyInsightsService $insights,
        private readonly AiManager $manager,
        private readonly ConversationGuard $guard,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    public function answer(string $question, array $history = []): array
    {
        $context = $this->context();
        $history = $this->normalizeHistory($history);
        $question = $this->cleanText(
            $question,
            max(20, (int) config('ai.question_max_length', 500))
        );

        if ($question === '') {
            $question = 'help';
        }

        // Greetings, thanks, and off-topic questions are handled locally so a
        // cloud provider is never called for them.
        $intercepted = $this->guard->intercept($question, $context, $history);

        if ($intercepted !== null) {
            return [
                'answer' => $this->sanitize($intercepted),
                'provider' => 'heuristic',
                'model' => null,
                'degraded' => false,
                'forecast_minutes' => (int) $context['forecast_minutes'],
                'generated_at' => now()->toIso8601String(),
            ];
        }

        ['provider' => $provider, 'degraded' => $degraded] = $this->manager->resolve();

        try {
            $answer = $provider->answer($question, $context, $history);
        } catch (Throwable $exception) {
            // Log the failure for operators, but never return its details:
            // provider errors can contain request fragments or keys.
            report($exception);
            $provider = $this->manager->fallback();
            $answer = $provider->answer($question, $context, $history);
            $degraded = true;
        }

        return [
            'answer' => $this->sanitize($answer),
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'degraded' => $degraded,
            'forecast_minutes' => (int) $context['forecast_minutes'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $summary = $this->insights->summary();
        $limit = max(1, (int) config('ai.context_room_limit', 30));

        $rooms = collect($summary['rooms'])
            ->take($limit)
            ->map(fn (array $room): array => $this->roomContext($room))
            ->values();

        $atRisk = $rooms
            ->filter(fn (array $room): bool => in_array($room['risk_level'], ['critical', 'high'], true))
            ->values();

        $anomalies = $rooms
            ->filter(fn (array $room): bool => is_array($room['anomaly']))
            ->values();

        $byFloor = $rooms
            ->groupBy('floor')
            ->map(fn (Collection $group, int|string $floor): array => [
                'floor' => (int) $floor,
                'utilization_percent' => (int) round((float) $group->avg('utilization_percent')),
                'at_risk' => $group
                    ->filter(fn (array $room): bool => in_array($room['risk_level'], ['critical', 'high'], true))
                    ->count(),
            ])
            ->sortBy('floor')
            ->values();

        $context = [
            'generated_at' => $summary['generated_at'],
            'window_hours' => (int) $summary['window_hours'],
            'forecast_minutes' => (int) $summary['forecast_minutes'],
            'headline' => $this->cleanText((string) $summary['headline']),
            'counts' => [
                'rooms' => (int) $summary['rooms_analysed'],
                'at_risk' => $atRisk->count(),
                'anomalies' => $anomalies->count(),
                'average_utilization_percent' => (int) round(((float) $summary['average_utilization']) * 100),
            ],
            'at_risk' => $atRisk->all(),
            'anomalies' => $anomalies->all(),
            'empty_rooms' => $rooms
                ->filter(fn (array $room): bool => $room['status'] === 'empty')
                ->pluck('name')
                ->values()
                ->all(),
            'by_floor' => $byFloor->all(),
            'rooms' => $rooms->all(),
        ];

        return $this->redact($context);
    }

    /**
     * Keep only the last few well-formed turns. History is user-supplied, so it
     * is sanitised and length-capped before it reaches a provider.
     *
     * @param  array<int, mixed>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeHistory(array $history): array
    {
        $turns = [];

        foreach (array_slice($history, -6) as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content)) {
                continue;
            }

            $content = $this->cleanText($content, 500);

            if ($content === '') {
                continue;
            }

            $turns[] = ['role' => $role, 'content' => $content];
        }

        return $turns;
    }

    /**
     * @param  array<string, mixed>  $room
     * @return array<string, mixed>
     */
    private function roomContext(array $room): array
    {
        $anomaly = $room['anomaly'] ?? null;
        $detected = is_array($anomaly) && (bool) ($anomaly['detected'] ?? false);

        return [
            'name' => $this->cleanText((string) $room['name'], 50),
            'floor' => (int) $room['floor'],
            'building' => $this->cleanText((string) ($room['building'] ?? ''), 50),
            'status' => (string) $room['status'],
            'capacity' => (int) $room['capacity'],
            'people_count' => (int) $room['people_count'],
            'forecast_people_count' => (int) $room['forecast_people_count'],
            'utilization_percent' => (int) round(((float) $room['utilization']) * 100),
            'risk_level' => (string) $room['risk_level'],
            'minutes_to_capacity' => $room['minutes_to_capacity'] !== null
                ? (int) $room['minutes_to_capacity']
                : null,
            'confidence' => (string) $room['confidence'],
            'reason' => $this->cleanText((string) $room['reason'], 240),
            'anomaly' => $detected
                ? [
                    'detected' => true,
                    'direction' => (string) ($anomaly['direction'] ?? 'change'),
                    'change' => (int) ($anomaly['change'] ?? 0),
                ]
                : null,
        ];
    }

    /**
     * Defence in depth: even if a future field is added upstream, keys that
     * look personal are removed before an external prompt is assembled.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        if (! (bool) config('ai.redact_personal_data', true)) {
            return $context;
        }

        return $this->stripSensitiveKeys($context);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function stripSensitiveKeys(array $value): array
    {
        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/staff|user|instructor|email|phone|password/i', $key) === 1) {
                continue;
            }

            $clean[$key] = is_array($item) ? $this->stripSensitiveKeys($item) : $item;
        }

        return $clean;
    }

    /**
     * Treat every value as untrusted: strip markup and control characters,
     * collapse whitespace, and cap the length before it reaches a model.
     */
    private function cleanText(string $value, int $max = 300): string
    {
        $text = strip_tags($value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = trim($text);

        return mb_substr($text, 0, $max);
    }

    private function sanitize(string $answer): string
    {
        $text = $this->cleanText($answer, 2000);
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $text === '' ? 'The assistant could not produce an answer.' : $text;
    }
}
