<?php

namespace App\Services\Ai;

use App\Models\Room;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Builds the read-only context, asks the configured provider, and degrades
 * gracefully to the heuristic provider when an external call fails.
 *
 * CIA Triad:
 *   Confidentiality – staff PII (IDs, emails, passwords) is never sent to any
 *                     external model. Instructor names are included for admin
 *                     only (need-to-know). Teacher context is scoped to their
 *                     own schedules exclusively.
 *   Integrity       – context is assembled from live DB reads and sanitised
 *                     before being serialised. The AI is strictly read-only.
 *   Availability    – every external failure degrades to the heuristic provider
 *                     so the assistant is always available.
 */
class AssistantService
{
    public function __construct(
        private readonly AiManager $manager,
        private readonly ConversationGuard $guard,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array<string, mixed>
     */
    public function answer(string $question, array $history = [], ?User $user = null): array
    {
        $context  = $this->context($user);
        $history  = $this->normalizeHistory($history);
        $question = $this->cleanText(
            $question,
            max(20, (int) config('ai.question_max_length', 500))
        );

        if ($question === '') {
            $question = 'help';
        }

        // Greetings, thanks, off-topic — handled locally; never reaches a provider.
        $intercepted = $this->guard->intercept($question, $context, $history);

        if ($intercepted !== null) {
            return [
                'answer'           => $this->sanitize($intercepted),
                'provider'         => 'heuristic',
                'model'            => null,
                'degraded'         => false,
                'forecast_minutes' => (int) $context['forecast_minutes'],
                'generated_at'     => now()->toIso8601String(),
            ];
        }

        ['provider' => $provider, 'degraded' => $degraded] = $this->manager->resolve();

        try {
            $answer = $provider->answer($question, $context, $history);
        } catch (Throwable $exception) {
            // Log for operators — never surface provider details to the client.
            report($exception);
            $provider = $this->manager->fallback();
            $answer   = $provider->answer($question, $context, $history);
            $degraded = true;
        }

        return [
            'answer'           => $this->sanitize($answer),
            'provider'         => $provider->name(),
            'model'            => $provider->model(),
            'degraded'         => $degraded,
            'forecast_minutes' => (int) $context['forecast_minutes'],
            'generated_at'     => now()->toIso8601String(),
        ];
    }

    /**
     * Assemble the full context payload for the signed-in user.
     * Personal data is redacted before the array leaves this method.
     *
     * @return array<string, mixed>
     */
    public function context(?User $user = null): array
    {
        $limit = max(1, (int) config('ai.context_room_limit', 30));

        $rooms = Room::query()
            ->orderBy('floor')
            ->orderBy('name')
            ->take($limit)
            ->get()
            ->map(fn (Room $room): array => $this->roomContext($room))
            ->values();

        $totalRooms       = $rooms->count();
        $occupiedCount    = $rooms->filter(fn (array $r): bool => $r['people_count'] > 0 && $r['status'] !== 'maintenance')->count();
        $emptyRooms       = $rooms->filter(fn (array $r): bool => $r['status'] === 'empty')->pluck('name')->values()->all();
        $maintenanceRooms = $rooms->filter(fn (array $r): bool => $r['status'] === 'maintenance')->pluck('name')->values()->all();
        $atRisk           = $rooms->filter(fn (array $r): bool => $r['is_over_capacity'] || $r['utilization_percent'] >= 90)->values();
        $avgUtilization   = $totalRooms > 0 ? (int) round((float) $rooms->avg('utilization_percent')) : 0;

        $byFloor = $rooms
            ->groupBy('floor')
            ->map(fn (Collection $group, int|string $floor): array => [
                'floor'               => (int) $floor,
                'utilization_percent' => (int) round((float) $group->avg('utilization_percent')),
                'at_risk'             => $group->filter(fn (array $r): bool => $r['is_over_capacity'])->count(),
            ])
            ->sortBy('floor')
            ->values();

        // Temporal context — lets the AI answer "what classes are today?"
        $now       = now();
        $todayCode = $this->dayCode($now->format('l'));

        $context = [
            'role'     => $user?->role ?? 'admin',
            'is_admin' => $user ? $user->isAdmin() : true,

            // Temporal — the AI uses these to answer time-sensitive questions
            'today'        => $now->format('l'),       // e.g. "Tuesday"
            'today_code'   => $todayCode,              // e.g. "T"
            'current_time' => $now->format('H:i'),     // e.g. "14:09"
            'current_date' => $now->format('M d, Y'),  // e.g. "Nov 18, 2026"

            'generated_at'    => $now->toIso8601String(),
            'window_hours'    => 2,
            'forecast_minutes'=> 30,
            'headline'        => sprintf(
                '%d rooms monitored: %d occupied, %d empty, %d in maintenance, %d need attention.',
                $totalRooms,
                $occupiedCount,
                count($emptyRooms),
                count($maintenanceRooms),
                $atRisk->count()
            ),
            'counts' => [
                'rooms'                    => $totalRooms,
                'occupied'                 => $occupiedCount,
                'empty'                    => count($emptyRooms),
                'maintenance'              => count($maintenanceRooms),
                'at_risk'                  => $atRisk->count(),
                'anomalies'                => 0,
                'average_utilization_percent' => $avgUtilization,
            ],
            'at_risk'          => $atRisk->all(),
            'anomalies'        => [],
            'empty_rooms'      => $emptyRooms,
            'maintenance_rooms'=> $maintenanceRooms,
            'by_floor'         => $byFloor->all(),
            'rooms'            => $rooms->all(),
            'schedules'        => $this->scheduleContext($user),
        ];

        return $this->redact($context);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Map PHP day names to our schedule day codes.
     */
    private function dayCode(string $dayName): string
    {
        return match ($dayName) {
            'Monday'    => 'M',
            'Tuesday'   => 'T',
            'Wednesday' => 'W',
            'Thursday'  => 'TH',
            'Friday'    => 'F',
            'Saturday'  => 'SAT',
            default     => 'SUN',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function roomContext(Room $room): array
    {
        $utilization = $room->capacity > 0
            ? (int) round(($room->people_count / $room->capacity) * 100)
            : 0;

        $isOver    = $room->isOverCapacity();
        $riskLevel = $isOver ? 'critical' : ($utilization >= 90 ? 'high' : ($utilization >= 75 ? 'medium' : 'low'));

        return [
            'name'                  => $this->cleanText((string) $room->name, 50),
            'floor'                 => (int) $room->floor,
            'building'              => $this->cleanText((string) ($room->building ?? ''), 50),
            'status'                => (string) $room->status,
            'capacity'              => (int) $room->capacity,
            'people_count'          => (int) $room->people_count,
            'forecast_people_count' => (int) $room->people_count,
            'utilization_percent'   => $utilization,
            'is_over_capacity'      => $isOver,
            'risk_level'            => $riskLevel,
            'minutes_to_capacity'   => null,
            'confidence'            => 'high',
            'reason'                => $isOver
                ? 'Occupancy exceeds room capacity.'
                : "Occupancy is at {$utilization}% of capacity.",
            'anomaly'               => null,
        ];
    }

    /**
     * Build schedule context scoped by role.
     *
     * CIA Confidentiality enforcement:
     *   Admin  — sees ALL schedules school-wide; each item includes the
     *            instructor's display name (no ID, email, or staff_id).
     *   Teacher — sees ONLY their own assigned schedules; no cross-teacher data.
     *
     * @return array<string, mixed>
     */
    private function scheduleContext(?User $user): array
    {
        $isAdmin = $user ? $user->isAdmin() : true;

        $schedules = Schedule::query()
            ->with(['room', 'instructor'])   // instructor = BelongsTo(User::class, 'user_id')
            ->when(! $isAdmin && $user !== null, fn ($q) => $q->where('user_id', $user->id))
            ->get();

        $items = $schedules->map(function (Schedule $s) use ($isAdmin): array {
            $item = [
                'subject_code' => $this->cleanText((string) $s->subject_code, 30),
                'title'        => $this->cleanText((string) $s->descriptive_title, 80),
                'room'         => $this->cleanText((string) ($s->room?->name ?? ''), 50),
                'days'         => $s->orderedDays(),
                'start_time'   => substr((string) $s->start_time, 0, 5),
                'end_time'     => substr((string) $s->end_time, 0, 5),
                'units'        => (int) $s->units,
            ];

            // Admin sees who teaches each subject — teacher sees only own load.
            if ($isAdmin) {
                $item['taught_by'] = $this->cleanText((string) ($s->instructor?->name ?? 'Unknown'), 60);
            }

            return $item;
        })->values()->all();

        return [
            'total' => count($items),
            'scope' => $isAdmin ? 'all' : 'own',
            'items' => $items,
        ];
    }

    /**
     * Keep only the last few well-formed turns. History is user-supplied, so
     * it is sanitised and length-capped before it reaches a provider.
     *
     * @param  array<int, mixed>  $history
     * @return array<int, array{role: string, content: string}>
     */
    private function normalizeHistory(array $history): array
    {
        $turns = [];

        foreach (array_slice($history, -12) as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role    = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content)) {
                continue;
            }

            $content = $this->cleanText($content, 2000);

            if ($content === '') {
                continue;
            }

            $turns[] = ['role' => $role, 'content' => $content];
        }

        return $turns;
    }

    /**
     * Defence in depth: even if a future field is added upstream, keys that
     * look personal are stripped before an external prompt is built.
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
            // Strip keys that look like personal identifiers.
            // Note: 'taught_by' is intentionally allowed — it carries only the
            // teacher's display name and is scoped to admin context only.
            if (is_string($key) && preg_match('/\bstaff_id\b|\buser_id\b|\bemail\b|\bphone\b|\bpassword\b/i', $key) === 1) {
                continue;
            }

            $clean[$key] = is_array($item) ? $this->stripSensitiveKeys($item) : $item;
        }

        return $clean;
    }

    /**
     * Strip markup, control characters, and collapse whitespace.
     * Every value is treated as untrusted text.
     */
    private function cleanText(string $value, int $max = 300): string
    {
        $text = strip_tags($value);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? '';
        $text = trim($text);

        return mb_substr($text, 0, $max);
    }

    /**
     * Final sanitisation pass on the AI's answer before it is returned to the
     * client. Strips markup, collapses excessive blank lines, and applies a
     * generous length cap.
     *
     * CIA Integrity: prevents prompt-injection fragments from echoing back.
     */
    private function sanitize(string $answer): string
    {
        // Strip any HTML or script tags that may have been echoed back.
        $text = strip_tags($answer);
        // Remove dangerous characters / control codes.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        // Collapse more than two consecutive blank lines.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);
        // Cap at ~4 000 chars (well above the 1200-token reply limit).
        $text = mb_substr($text, 0, 4000);

        return $text === '' ? 'The assistant could not produce an answer.' : $text;
    }
}
