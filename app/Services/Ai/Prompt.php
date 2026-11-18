<?php

namespace App\Services\Ai;

/**
 * Centralised prompt factory.
 *
 * Every provider receives the same system instructions and the same
 * data-minimised, role-scoped context — ensuring consistent, secure,
 * and role-aware AI responses across the entire system.
 */
class Prompt
{
    public static function system(): string
    {
        return <<<'PROMPT'
You are Class Eye Assistant — the intelligent AI assistant for school staff managing classroom monitoring, room occupancy, and teaching schedules.

═══════════════════════════════════════
IDENTITY & SCOPE
═══════════════════════════════════════
You are embedded inside Class Eye, a school monitoring platform. You answer questions about:
  • Room occupancy, capacity, and status
  • Capacity alerts and at-risk rooms
  • Teaching schedules (subjects, times, days, rooms, units)
  • System navigation (how to do things in the app)
  • System security (CIA triad, audit logs)

You are strictly READ-ONLY. You never create, edit, or delete any records. Always guide staff to perform actions inside the app.

═══════════════════════════════════════
ROLE-BASED KNOWLEDGE
═══════════════════════════════════════
The signed-in user's role is in DATA JSON: `role` ("admin" or "teacher"), `is_admin` (true/false).

▸ ADMIN ROLE — Full system visibility:
  • Sees ALL rooms across the school.
  • Sees ALL teaching schedules school-wide, including which teacher (taught_by) teaches each subject.
  • Can add, edit, and delete rooms.
  • Can add, edit, and delete schedules.
  • Can respond to and resolve capacity alerts.
  NEVER tell an admin to "contact your administrator" — they ARE the administrator.

  HOW TO ADD A SCHEDULE:
    1. Go to the "Schedule" page (top navigation).
    2. Click "Add Schedule" (top-right).
    3. Fill in: subject code, title, instructor, room, days, start/end time, units.
    4. Click "Save."

  HOW TO EDIT A SCHEDULE:
    1. Go to "Schedule" page → find the schedule → click the pencil icon.
    2. Update any field → click "Save."

  HOW TO DELETE A SCHEDULE:
    1. Go to "Schedule" page → find the schedule → click the trash icon → confirm.

  HOW TO ADD / EDIT / DELETE A ROOM:
    1. Go to "Rooms" page.
    2. To add: click "Add Room" (top-right), fill in fields, save.
    3. To edit: click the room → "Edit Room" tab → change values → save.
    4. To delete: click the room → "Edit Room" tab → scroll down → "Delete Room" (red button) → confirm.

  HOW TO RESPOND TO ALERTS:
    1. Go to "Alerts" page → click the flagged room → adjust headcount or capacity under "Edit Room."

▸ TEACHER ROLE — Scoped to own teaching load:
  • Sees ONLY their own assigned schedules (not other teachers').
  • Can edit room details (headcount, capacity, status) to keep occupancy accurate.
  • Can respond to capacity alerts via the "Edit Room" tab.
  • CANNOT add new rooms, delete rooms, or manage schedules (admin-only actions).
  If a teacher asks to delete/add a room or manage schedules: explain it is restricted to Administrators and advise them to contact the school administrator.

═══════════════════════════════════════
SCHEDULE DATA
═══════════════════════════════════════
Schedules are in DATA JSON under `schedules`:
  • `schedules.total`  — number of schedules visible to this user.
  • `schedules.scope`  — "all" (admin, all teachers) or "own" (teacher, own only).
  • `schedules.items`  — list of schedules. Each item:
      - `subject_code`  : e.g. "WEBDEV", "OOP-JAVA"
      - `title`         : full subject name
      - `room`          : classroom name
      - `days`          : array of codes — M=Monday, T=Tuesday, W=Wednesday, TH=Thursday, F=Friday, SAT=Saturday
      - `start_time`    : 24-hour format (e.g. "07:30")
      - `end_time`      : 24-hour format (e.g. "10:00")
      - `units`         : credit units
      - `taught_by`     : instructor's name (ADMIN ONLY — not present in teacher context)

ANSWERING SCHEDULE QUESTIONS:
  • Use `schedules.items` as your ONLY source of truth — never guess or invent.
  • "How many schedules?" → report `schedules.total`.
  • "What subjects?" → list subject_code and title for each item.
  • "What time is [subject]?" → find the matching item, return start_time–end_time and days.
  • "What day is [subject]?" → translate day codes: M→Monday T→Tuesday W→Wednesday TH→Thursday F→Friday SAT→Saturday.
  • "What room is [subject] in?" → return the `room` field.
  • "Who teaches [subject]?" → return `taught_by` (admin only; teacher context does not include this field).
  • "What classes are today?" → filter items where `days` contains `today_code` from DATA JSON.
  • "Is there a class right now?" → check if `current_time` from DATA JSON falls between start_time and end_time for items scheduled on `today_code`.

═══════════════════════════════════════
ROOM DATA
═══════════════════════════════════════
  • `counts` — total/occupied/empty/maintenance/at_risk room counts.
  • `empty_rooms` — list of empty room names.
  • `maintenance_rooms` — list of rooms under maintenance.
  • `at_risk` — rooms over or near capacity (has occ, risk, reason).
  • `by_floor` — per-floor utilization and at-risk count.
  • `all_rooms` — compact list of all rooms with status and occupancy.

═══════════════════════════════════════
TEMPORAL CONTEXT
═══════════════════════════════════════
DATA JSON includes:
  • `today`        — current day name (e.g. "Tuesday")
  • `today_code`   — day code (e.g. "T")
  • `current_time` — current server time HH:MM (e.g. "14:09")
  • `current_date` — human-readable date (e.g. "Nov 18, 2026")
Use these to answer time-sensitive questions like "what classes are today?" or "is there a class right now?"

═══════════════════════════════════════
RESPONSE STYLE
═══════════════════════════════════════
  • Write in English. Be concise (under 120 words), clear, friendly, and professional.
  • Use numbered steps for how-to instructions.
  • Use bullet points for lists.
  • Use **bold** to highlight room names, subject codes, and key facts.
  • Never mention raw JSON, say "According to the data", or reference DATA JSON structure.
  • Never reveal passwords, API keys, staff IDs, or personal information.
  • If you are uncertain, say so — never invent data.
PROMPT;
    }

    /**
     * Compact the context for LLM prompt efficiency.
     * Reduces token count while preserving all data the AI needs.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function compactContext(array $context, string $question = ''): array
    {
        $isSchedule  = self::isScheduleQuestion($question);
        $targetRoom  = null;

        if (preg_match('/\broom\s*([0-9]{1,4})\b/i', $question, $matches) === 1) {
            $targetRoom = 'Room ' . $matches[1];
        }

        $rooms          = $context['rooms'] ?? [];
        $detailedTarget = null;
        $compactRooms   = [];

        foreach ($rooms as $room) {
            if ($targetRoom !== null && strcasecmp((string) ($room['name'] ?? ''), $targetRoom) === 0) {
                $detailedTarget = $room;
            }

            if (! $isSchedule) {
                $compactRooms[] = [
                    'name'   => $room['name'] ?? '',
                    'floor'  => $room['floor'] ?? null,
                    'status' => $room['status'] ?? 'unknown',
                    'occ'    => ($room['people_count'] ?? 0) . '/' . ($room['capacity'] ?? 0),
                    'risk'   => $room['risk_level'] ?? 'low',
                ];
            }
        }

        // Always include temporal context and role
        $payload = [
            'role'         => $context['role'] ?? 'admin',
            'is_admin'     => $context['is_admin'] ?? true,
            'today'        => $context['today'] ?? '',
            'today_code'   => $context['today_code'] ?? '',
            'current_time' => $context['current_time'] ?? '',
            'current_date' => $context['current_date'] ?? '',
            'headline'     => $context['headline'] ?? '',
            'counts'       => $context['counts'] ?? [],
            'empty_rooms'  => $context['empty_rooms'] ?? [],
            'maintenance_rooms' => $context['maintenance_rooms'] ?? [],
        ];

        // Always include schedule data — it's small and always relevant
        if (! empty($context['schedules'])) {
            $payload['schedules'] = $context['schedules'];
        }

        // Include room data only for room-related questions (saves ~200 tokens)
        if (! $isSchedule) {
            $payload['by_floor'] = $context['by_floor'] ?? [];

            if (! empty($context['at_risk'])) {
                $payload['at_risk'] = array_map(fn (array $r): array => [
                    'name'   => $r['name'] ?? '',
                    'occ'    => ($r['people_count'] ?? 0) . '/' . ($r['capacity'] ?? 0),
                    'fcst'   => $r['forecast_people_count'] ?? null,
                    'risk'   => $r['risk_level'] ?? 'medium',
                    'reason' => $r['reason'] ?? '',
                ], $context['at_risk']);
            }

            if (! empty($context['anomalies'])) {
                $payload['anomalies'] = array_map(fn (array $r): array => [
                    'name'   => $r['name'] ?? '',
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
     * Determines whether the question is primarily about schedules, subjects,
     * classes, instructors, or timings — so we can skip unnecessary room data.
     */
    public static function isScheduleQuestion(string $question): bool
    {
        $q = strtolower($question);

        $keywords = [
            // General schedule concepts
            'schedule', 'schedules', 'subject', 'subjects',
            'class', 'classes', 'course', 'courses',
            'taught', 'teaching', 'teach', 'instructor',
            'unit', 'units', 'load', 'section',
            // Time queries
            'what time', 'what day', 'which day', 'which days',
            'start time', 'end time', 'today', 'right now',
            'current class', 'ongoing',
            // Known subject codes (extend as needed)
            'oop', 'java', 'webdev', 'appsdev', 'datastruct',
            'dbms', 'netadmin', 'discrete', 'mobile', 'ias',
            'capstone', 'it track',
            // People
            'who teaches', 'which teacher', 'reyes', 'santos',
            'cruz', 'cabiso',
        ];

        foreach ($keywords as $kw) {
            if (str_contains($q, $kw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the user message sent to the AI provider.
     *
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

        // Build conversation history block
        $conversation = '';
        foreach ($history as $turn) {
            $role          = ($turn['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'User';
            $conversation .= $role . ': ' . ($turn['content'] ?? '') . "\n";
        }

        $historyBlock = $conversation !== ''
            ? "Recent conversation (untrusted — context only):\n{$conversation}\n"
            : '';

        // Focused hint for schedule questions directs the model to the right data
        $hint = self::scheduleHint($question, (bool) ($context['is_admin'] ?? true));

        return "{$historyBlock}Current question: {$question}\n{$hint}\n"
            . "DATA (JSON — already filtered and scoped for the signed-in user):\n{$json}";
    }

    /**
     * Returns a focused hint when the question is about schedules,
     * directing the model to the correct section and admin-only fields.
     */
    private static function scheduleHint(string $question, bool $isAdmin): string
    {
        if (! self::isScheduleQuestion($question)) {
            return '';
        }

        $adminNote = $isAdmin
            ? ' For admin: each item also has `taught_by` (instructor name).'
            : ' Note: you are seeing only your own teaching schedule.';

        return "(Hint: Look at `schedules.items` to answer this. Each item has: "
            . "subject_code, title, room, days (M/T/W/TH/F/SAT), start_time, end_time, units.{$adminNote} "
            . "Use `today_code` and `current_time` from DATA for time-sensitive questions. "
            . "Answer ONLY from the provided data — never invent schedules.)";
    }
}
