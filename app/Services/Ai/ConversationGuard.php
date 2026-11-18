<?php

namespace App\Services\Ai;

use Illuminate\Support\Str;

/**
 * Keeps the assistant friendly but scoped.
 *
 * Greetings, thanks, goodbyes, and help requests are answered locally so they
 * are instant and never sent to an external provider. Messages that clearly
 * have nothing to do with rooms or occupancy are politely redirected. Anything
 * that looks like an occupancy question is left to the provider.
 */
class ConversationGuard
{
    private const DOMAIN_KEYWORDS = [
        'room', 'rooms', 'floor', 'floors', 'building', 'buildings',
        'capacity', 'occupancy', 'occupied', 'empty', 'free', 'available',
        'risk', 'alert', 'alerts', 'busy', 'crowd', 'crowded',
        'forecast', 'predict', 'trend', 'people', 'student', 'students',
        'schedule', 'schedules', 'maintenance', 'anomaly', 'anomalies',
        'unusual', 'spike', 'jump', 'sudden', 'utilization', 'full',
        'seat', 'seats', 'space', 'spaces', 'wing', 'hall', 'lab',
        'laboratory', 'library', 'gym', 'cafeteria', 'update', 'updates',
        'status', 'change', 'changes', 'happening',
        // schedule-specific
        'subject', 'subjects', 'class', 'classes', 'unit', 'units',
        'time', 'day', 'days', 'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'mwf', 'tth', 'tths', 'ttth',
        'add', 'delete', 'edit', 'remove', 'create', 'manage',
        'teaching', 'load', 'instructor', 'teacher', 'teachers',
        'course', 'courses', 'section', 'lesson',
    ];

    private const GREETING_PATTERN = '/^(hi|hello|hey|greetings|good\s+(morning|afternoon|evening)|how\s+are\s+you(?:\s+doing)?|how\s+is\s+it\s+going|how\'s\s+it\s+going|what\'s\s+up)\b/i';

    private const THANKS_PATTERN = '/^(thanks|thank\s+you|thx|ty|appreciate\s+it)\b/i';

    private const GOODBYE_PATTERN = '/^(bye|goodbye|see\s+you|later|good\s+night)\b/i';

    private const FOLLOW_UP_PATTERN = '/^(and|then|what\s+about|how\s+about|why|really|more|tell\s+me\s+more|what\s+else|which\s+one|can\s+you\s+explain|explain|go\s+on|continue)\b/i';

    private const ABOUT_PATTERN = '/\b(what\s+is|what\'s|tell\s+me\s+about|explain|describe|who\s+is)\b.*\b(class\s*eye|this\s+(system|app|application|website|platform)|the\s+(system|app|application))\b|\bwhat\s+is\s+(your|its)\s+purpose\b/i';

    private const FEATURES_PATTERN = '/\b(features?|functions?|modules?|capabilities)\b.*\b(class\s*eye|this\s+(system|app|application)|the\s+(system|app|application))\b|\b(class\s*eye|this\s+(system|app|application)|the\s+(system|app|application))\b.*\b(features?|functions?|modules?|capabilities)\b/i';

    private const FEATURES_ACTION_PATTERN = '/\bwhat\s+(can|does)\b.*\b(class\s*eye|this\s+(system|app|application)|the\s+(system|app|application))\b.*\b(do|offer|include)\b/i';

    private const HOW_PATTERN = '/\bhow\s+(does|do)\b.*\b(class\s*eye|this\s+(system|app|application)|the\s+(system|app|application)|the\s+insights?|the\s+forecast(ing)?)\b.*\b(work|forecast|detect|calculate)\b|\bhow\s+does\s+(this|it)\s+work\b/i';

    private const SECURITY_PATTERN = '/\b(audit\s+chain|hash\s+chain|tamper[- ]?evident|secur(e|ity)|cia\s+triad)\b/i';

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function intercept(string $question, array $context, array $history = []): ?string
    {
        $text = Str::lower(preg_replace('/\s+/', ' ', trim($question)) ?? '');

        // System questions are answered locally, even when they mention rooms.
        $systemAnswer = $this->systemAnswer($text);

        if ($systemAnswer !== null) {
            return $systemAnswer;
        }

        // If the message mentions the school domain, let the provider answer.
        if ($this->mentionsDomain($text)) {
            return null;
        }

        $headline = trim((string) ($context['headline'] ?? ''));

        if ($this->matchesGreeting($text)) {
            return $this->greeting($headline);
        }

        if (preg_match(self::THANKS_PATTERN, $text) === 1) {
            return "You're welcome! If you need another occupancy update, just ask.";
        }

        if (preg_match(self::GOODBYE_PATTERN, $text) === 1) {
            return "Goodbye! I'll be here whenever you need an occupancy update.";
        }

        if ($this->matchesHelp($text)) {
            return 'I can answer questions about room occupancy, capacity alerts, empty rooms, '
                .'floor summaries, and teaching schedules. '
                .'Try asking "Which rooms need attention?", "What classes are today?", or '
                .'"What time is OOP-JAVA?"';
        }

        // Short follow-ups such as "tell me more" only make sense in a thread.
        if ($history !== [] && preg_match(self::FOLLOW_UP_PATTERN, $text) === 1) {
            return null;
        }

        return 'I can only help with Class Eye room occupancy, schedules, and system navigation, so I '
            .'cannot answer unrelated questions. Try asking "Which rooms need attention?", '
            .'"What classes are today?", or "What time is OOP-JAVA?"';
    }

    private function systemAnswer(string $text): ?string
    {
        if (preg_match(self::SECURITY_PATTERN, $text) === 1) {
            return $this->security();
        }

        if ($this->matchesFeatures($text)) {
            return $this->features();
        }

        if ($this->matchesHowItWorks($text)) {
            return $this->howItWorks();
        }

        if (preg_match(self::ABOUT_PATTERN, $text) === 1) {
            return $this->about();
        }

        return null;
    }

    private function matchesFeatures(string $text): bool
    {
        return preg_match(self::FEATURES_PATTERN, $text) === 1
            || preg_match(self::FEATURES_ACTION_PATTERN, $text) === 1;
    }

    private function matchesHowItWorks(string $text): bool
    {
        return preg_match(self::HOW_PATTERN, $text) === 1;
    }

    private function about(): string
    {
        return 'Class Eye is a classroom monitoring system for school staff. It tracks real-time '
            .'room occupancy and capacity, flags over-capacity rooms, records a tamper-evident '
            .'activity log per room, and displays teaching schedules. '
            .'This AI assistant can answer questions about room occupancy, capacity alerts, '
            .'floor summaries, and schedules (subjects, times, days, rooms, units). '
            .'All answers are advisory — staff make the final decisions.';
    }

    private function features(): string
    {
        return 'Class Eye provides: real-time room occupancy and capacity tracking, '
            .'over-capacity alerts, floor-level utilization summaries, teaching schedules '
            .'management (subjects, days, times, rooms, units, instructors), and a '
            .'tamper-evident activity log per room. '
            .'This AI assistant answers room and schedule questions and can explain '
            .'system features, navigation, and security design.';
    }

    private function howItWorks(): string
    {
        return 'Class Eye monitors room headcounts and capacity in real time. '
            .'When staff update a room, the change is recorded with a tamper-evident audit hash. '
            .'The AI assistant reads live room and schedule data to answer your questions instantly. '
            .'Admins see all rooms and all teacher schedules; teachers see only their own schedule.';
    }

    private function security(): string
    {
        return 'Class Eye applies the CIA triad. '
            .'Confidentiality: Sanctum bearer tokens expire and are revoked on password reset; '
            .'CORS is restricted to an explicit allow-list; passwords are hashed; the AI receives '
            .'only the signed-in user\'s filtered data — no staff IDs, emails, or passwords. '
            .'Admin context includes instructor names (need-to-know); teacher context is scoped to '
            .'their own schedules only. '
            .'Integrity: room changes are written in one transaction with database constraints; '
            .'activity logs use a per-room HMAC hash chain that audit:verify recomputes to detect tampering; '
            .'the AI is strictly read-only. '
            .'Availability: graceful fallback to the local assistant when the AI provider fails; '
            .'idempotent startup migrations ensure the system recovers cleanly after a restart.';
    }

    private function mentionsDomain(string $text): bool
    {
        if (preg_match('/\b[0-9]{3}\b/', $text) === 1) {
            return true;
        }

        foreach (self::DOMAIN_KEYWORDS as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function matchesGreeting(string $text): bool
    {
        if (mb_strlen($text) > 80) {
            return false;
        }

        return preg_match(self::GREETING_PATTERN, $text) === 1;
    }

    private function matchesHelp(string $text): bool
    {
        $normalized = trim($text, " \t\n\r\0\x0B?.!,;:");

        return in_array($normalized, [
            'help',
            'what can you do',
            'what can you help with',
            'can you help',
            'can you help me',
            'how can you help',
            'how can you help me',
            'what do you do',
            'what are your capabilities',
            'show me what you can do',
            'who are you',
            'commands',
            'options',
        ], true);
    }

    private function greeting(string $headline): string
    {
        $status = $headline !== '' ? ' Right now: '.$headline : '';

        return "Hello! Thanks for asking. I'm ready to help with room occupancy, capacity "
            ."risk, empty rooms, floor summaries, and short-term forecasts.{$status} "
            .'Try asking "Which rooms need attention?" or "Which rooms are empty?"';
    }
}
