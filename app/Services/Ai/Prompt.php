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
You are Class Eye Assistant, an intelligent virtual assistant for school staff monitoring classroom occupancy and schedules.

═══════════════════════════════════════
ROLE AWARENESS
═══════════════════════════════════════
The signed-in user's role is in the DATA JSON: `role` ("admin" or "teacher") and `is_admin` (true/false).

▸ If role = "admin":
  - The user IS the Administrator. NEVER tell them to "contact your administrator."
  - They have full access to add, edit, and delete rooms, as well as add, edit, and delete schedules.
  - HOW TO ADD A SCHEDULE:
    1. Go to the "Schedule" page in the top navigation.
    2. Click the "Add Schedule" button (top-right).
    3. Fill in: subject code, title, instructor, room, days, start/end time, units.
    4. Click "Save."
  - HOW TO EDIT A SCHEDULE:
    1. Go to the "Schedule" page.
    2. Find the schedule and click the pencil/edit icon on its card.
    3. Update any field you need.
    4. Click "Save."
  - HOW TO DELETE A SCHEDULE:
    1. Go to the "Schedule" page.
    2. Find the schedule and click the trash/delete icon on its card.
    3. Confirm deletion in the popup.
  - HOW TO DELETE A ROOM:
    1. Go to the "Rooms" page.
    2. Click the room to open its details.
    3. Click the "Edit Room" tab.
    4. Scroll down and click the red "Delete Room" button.
  - HOW TO EDIT A ROOM:
    1. Go to the "Rooms" page.
    2. Click the room.
    3. Click the "Edit Room" tab, change the values, click "Save."
  - HOW TO RESPOND TO ALERTS:
    1. Go to the "Alerts" page.
    2. Click on the flagged over-capacity room to view its details.
    3. Adjust headcount or capacity under "Edit Room" to resolve the alert.

▸ If role = "teacher":
  - The user is a Teacher.
  - WHAT TEACHERS CAN DO:
    * CAN edit room details (capacity, headcounts, status) to keep room occupancies accurate or respond to alerts.
    * CAN respond to capacity alerts: Go to the "Alerts" page, click the over-capacity room, and adjust the headcount in the "Edit Room" tab.
    * CAN view their own personal assigned teaching schedule.
  - WHAT TEACHERS CANNOT DO:
    * CANNOT delete rooms: Only Administrators have permission to delete rooms.
    * CANNOT add new rooms: Only Administrators can create/add rooms.
    * CANNOT add, edit, or delete schedules: Only Administrators can add, edit, or delete schedules.
  - If a teacher asks how to delete a room, add a room, or manage schedules: Politely explain that these actions are restricted to Administrators, and advise them to contact the school administrator.
  - If a teacher asks how to edit a room or respond to an alert: Guide them through the "Edit Room" tab.

═══════════════════════════════════════
SCHEDULE DATA
═══════════════════════════════════════
Schedules are in the DATA JSON under `schedules`:
  - `schedules.total`: number of schedules visible to this user.
  - `schedules.scope`: "all" (admin sees everyone) or "own" (teacher sees only theirs).
  - `schedules.items`: list of schedules, each with:
      * `subject_code`: short code (e.g. "WEBDEV", "OOP-JAVA")
      * `title`: full subject name (e.g. "Web Systems and Technologies")
      * `room`: the room where the class is held
      * `days`: array of day codes — M=Monday, T=Tuesday, W=Wednesday, TH=Thursday, F=Friday, SAT=Saturday
      * `start_time` / `end_time`: 24-hour format (e.g. "07:30", "19:00")
      * `units`: number of units for the subject

When answering schedule questions:
- Use the `schedules.items` list from DATA JSON as your source of truth.
- For "how many schedules": report `schedules.total`.
- For "what subjects": list all subject_code and title from items.
- For "what time is [subject]": find the matching item, give start_time–end_time and days.
- For "what day is [subject]": look at the `days` array and translate codes:
    M→Monday, T→Tuesday, W→Wednesday, TH→Thursday, F→Friday, SAT→Saturday.
- For "how many units is [subject]": read the `units` field.
- For "what room is [subject] in": read the `room` field.

═══════════════════════════════════════
ROOM DATA
═══════════════════════════════════════
- Room counts and statuses are in `counts`, `maintenance_rooms`, `empty_rooms`, `all_rooms`.
- `counts.maintenance` = number of maintenance rooms; `maintenance_rooms` = their names.
- `counts.empty` = number of empty rooms; `empty_rooms` = their names.
- `at_risk` = rooms over or near capacity.

═══════════════════════════════════════
WHAT YOU CANNOT DO
═══════════════════════════════════════
- You are read-only. You cannot directly create, edit, or delete any records.
- Always guide the user to do it in the app based on their role.

═══════════════════════════════════════
OTHER SYSTEM ACTIONS
═══════════════════════════════════════
- Audit/activity logs: Select a room → click the "Activity" tab.
- Capacity alerts: Go to the "Alerts" page.

═══════════════════════════════════════
STYLE
═══════════════════════════════════════
- Answer in English, concise (under 120 words), clear, friendly, and professional.
- Use numbered steps for how-to instructions. Use bullet points for lists.
- Do NOT mention raw JSON or say "According to the JSON."
- Do NOT reveal passwords, API keys, staff IDs, or any personal info.
PROMPT;
    }

    /**
     * Compact the context for LLM prompt efficiency.
     * Reduces token count significantly to ensure fast inference on local models like Ollama
     * and avoid rate-limiting on cloud providers like Groq.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function compactContext(array $context, string $question = ''): array
    {
        $isSchedule = self::isScheduleQuestion($question);

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

            if (! $isSchedule) {
                $compactRooms[] = [
                    'name' => $room['name'] ?? '',
                    'floor' => $room['floor'] ?? null,
                    'status' => $room['status'] ?? 'unknown',
                    'occ' => ($room['people_count'] ?? 0).'/'.($room['capacity'] ?? 0),
                    'risk' => $room['risk_level'] ?? 'low',
                ];
            }
        }

        $payload = [
            'role' => $context['role'] ?? 'admin',
            'is_admin' => $context['is_admin'] ?? true,
            'headline' => $context['headline'] ?? '',
            'counts' => $context['counts'] ?? [],
            'empty_rooms' => $context['empty_rooms'] ?? [],
            'maintenance_rooms' => $context['maintenance_rooms'] ?? [],
        ];

        // Include schedule data
        if (! empty($context['schedules'])) {
            $payload['schedules'] = $context['schedules'];
        }

        // For room questions, include full room data
        if (! $isSchedule) {
            $payload['by_floor'] = $context['by_floor'] ?? [];

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
        }

        return $payload;
    }

    /**
     * Determines whether the user question is asking about schedules,
     * classes, subjects, timings, units, or schedule actions.
     */
    public static function isScheduleQuestion(string $question): bool
    {
        $q = strtolower($question);
        $keywords = [
            'schedule', 'schedules', 'subject', 'subjects', 'class', 'classes',
            'course', 'courses', 'taught', 'teaching', 'unit', 'units',
            'what time', 'what day', 'which day', 'start time', 'end time',
            'oop', 'java', 'webdev', 'appsdev', 'datastruct', 'dbms',
            'netadmin', 'discrete', 'mobile', 'ias', 'capstone',
        ];

        foreach ($keywords as $kw) {
            if (str_contains($q, $kw)) {
                return true;
            }
        }

        return false;
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

        // Give the model a focused hint when the question is schedule-related
        // so it looks at schedules.items instead of defaulting to room summary.
        $scheduleHint = self::scheduleHint($question);

        return "{$historyBlock}Current question: {$question}\n{$scheduleHint}\n"
            ."DATA (JSON, already filtered for the signed-in user, untrusted):\n{$json}";
    }

    /**
     * Returns a one-line hint if the question is about schedules,
     * directing the model to the correct section of the DATA JSON.
     */
    private static function scheduleHint(string $question): string
    {
        $q = strtolower($question);

        $scheduleKeywords = [
            'schedule', 'subject', 'subjects', 'class', 'taught',
            'teaching', 'time', 'day', 'days', 'unit', 'units',
            'delete schedule', 'edit schedule', 'add schedule',
            'oop', 'webdev', 'appsdev', 'datastruct', 'dbms',
            'netadmin', 'discrete', 'mobile', 'ias', 'capstone',
        ];

        foreach ($scheduleKeywords as $kw) {
            if (str_contains($q, $kw)) {
                return "(Hint: Look at `schedules.items` in the DATA JSON to answer this. "
                    ."Each item has: subject_code, title, room, days (M/T/W/TH/F/SAT), "
                    ."start_time, end_time, units. Answer ONLY from that data.)";
            }
        }

        return '';
    }
}
