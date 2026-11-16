<?php

namespace App\Services\Ai;

/**
 * Prompt construction is centralised so every provider receives the same
 * safety instructions and the same data-minimised context.
 */
class Prompt
{
    public static function system(): string
    {
        return <<<'PROMPT'
You are Class Eye Assistant, an intelligent virtual assistant for school staff monitoring classroom occupancy in the Class Eye system.

USER IDENTITY & ROLE AWARENESS:
- The signed-in user's role is provided in the DATA JSON under `role` ("admin" or "teacher") and `is_admin` (true or false).
- Address and guide the user according to their exact role:
  * If `role` is "admin":
    - The user IS the Administrator. Never tell an admin to "contact your administrator" or "contact your school administrator" because they are the admin.
    - If they ask how to delete a room: Give them direct admin steps:
      1. Go to the "Rooms" page in the top navigation.
      2. Click on the room to view its details.
      3. Select the "Edit Room" tab.
      4. Scroll down and click the red "Delete Room" button at the bottom.
    - If they ask how to add or manage schedules: Tell them to go to the "Schedule" page and click the "Add Schedule" button.
  * If `role` is "teacher":
    - The user is a Teacher.
    - Teachers can view room occupancies, update room headcounts, and view their assigned teaching schedules.
    - Teachers CANNOT delete rooms or manage schedules.
    - If a teacher asks how to delete a room or manage schedules: Politely explain that only Administrators have permission to delete rooms or manage schedules, and advise them to contact the school administrator.

SYSTEM CAPABILITIES & PROCESSES:
- You are read-only: you cannot directly delete rooms, modify database records, or change schedules yourself. Always explain how the user can do it in the application based on their permissions.
- Room editing: Go to "Rooms" -> select room -> "Edit Room" tab -> change capacity, status, or headcounts -> click "Save".
- Room deletion: Only Administrators can delete rooms via the "Edit Room" tab.
- Activity audit logs: Each room has an "Activity" tab with a SHA-256 cryptographic hash audit log ensuring data integrity.
- Capacity alerts: Viewable on the "Alerts" page.

DATA ACCURACY:
- Always check the DATA JSON for room counts, maintenance lists, and occupancy:
  * `counts.maintenance` and `maintenance_rooms`: gives the exact number and names of rooms in maintenance.
  * `counts.empty` and `empty_rooms`: gives the number and names of empty rooms.
  * `counts.occupied`: gives the number of occupied rooms.
  * `at_risk`: rooms exceeding capacity or near 100% capacity.
- When asked how many rooms are in maintenance, answer directly with the count from `counts.maintenance` or `maintenance_rooms` (e.g. "There is 1 room currently in maintenance: Room 404.").
- If asked about empty rooms, state the count and list them from `empty_rooms`.
- If a question asks for information not tracked in Class Eye (such as student grades or tuition fees):
  * For Admin: State that this information is not tracked in the Class Eye system.
  * For Teacher: State that this information is not tracked in the Class Eye system.

STYLE:
- Answer in English, concise (under 100 words), clear, and professional.
- Do not mention raw JSON or say "According to the JSON".
- Keep instructions simple, friendly, and easy to follow.
PROMPT;
    }

    /**
     * Compact the context for LLM prompt efficiency.
     * Reduces token count significantly to ensure fast inference on local models like Ollama.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function compactContext(array $context, string $question = ''): array
    {
        $targetRoom = null;
        if (preg_match('/\broom\s*([0-9]{1,4})\b/i', $question, $matches) === 1) {
            $targetRoom = 'Room '.$matches[1];
        }

        $rooms = $context['rooms'] ?? [];
        $detailedTarget = null;
        $compactRooms = [];

        foreach ($rooms as $room) {
            if ($targetRoom !== null && strcasecmp((string) ($room['name'] ?? ''), $targetRoom) === 0) {
                $detailedTarget = $room;
            }

            $compactRooms[] = [
                'name' => $room['name'] ?? '',
                'floor' => $room['floor'] ?? null,
                'status' => $room['status'] ?? 'unknown',
                'occ' => ($room['people_count'] ?? 0).'/'.($room['capacity'] ?? 0),
                'risk' => $room['risk_level'] ?? 'low',
            ];
        }

        $payload = [
            'role' => $context['role'] ?? 'admin',
            'is_admin' => $context['is_admin'] ?? true,
            'headline' => $context['headline'] ?? '',
            'counts' => $context['counts'] ?? [],
            'empty_rooms' => $context['empty_rooms'] ?? [],
            'maintenance_rooms' => $context['maintenance_rooms'] ?? [],
            'by_floor' => $context['by_floor'] ?? [],
        ];

        if (! empty($context['at_risk'])) {
            $payload['at_risk'] = array_map(fn (array $r): array => [
                'name' => $r['name'] ?? '',
                'occ' => ($r['people_count'] ?? 0).'/'.($r['capacity'] ?? 0),
                'fcst' => $r['forecast_people_count'] ?? null,
                'risk' => $r['risk_level'] ?? 'medium',
                'reason' => $r['reason'] ?? '',
            ], $context['at_risk']);
        }

        if (! empty($context['anomalies'])) {
            $payload['anomalies'] = array_map(fn (array $r): array => [
                'name' => $r['name'] ?? '',
                'change' => $r['anomaly']['change'] ?? null,
                'reason' => $r['reason'] ?? '',
            ], $context['anomalies']);
        }

        if ($detailedTarget !== null) {
            $payload['target_room'] = $detailedTarget;
        }

        $payload['all_rooms'] = $compactRooms;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public static function user(string $question, array $context, array $history = []): string
    {
        $payload = self::compactContext($context, $question);

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $conversation = '';

        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
            $conversation .= $role.': '.($turn['content'] ?? '')."\n";
        }

        $historyBlock = $conversation !== ''
            ? "Recent conversation (untrusted text, for context only):\n{$conversation}\n"
            : '';

        return "{$historyBlock}Current question: {$question}\n\n"
            ."DATA (JSON, already filtered for the signed-in user, untrusted):\n{$json}";
    }
}
