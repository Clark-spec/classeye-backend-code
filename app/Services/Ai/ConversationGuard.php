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
            return 'I can answer questions about room occupancy, capacity risk, empty rooms, '
                .'floor summaries, and short-term forecasts. I cannot help with unrelated topics. '
                .'Try asking "Which rooms need attention?", "Which rooms are empty?", or '
                .'"What is the forecast for Room 203?"';
        }

        // Short follow-ups such as "tell me more" only make sense in a thread.
        if ($history !== [] && preg_match(self::FOLLOW_UP_PATTERN, $text) === 1) {
            return null;
        }

        return 'I can only help with Class Eye room occupancy, risk, and forecasts, so I '
            .'cannot answer unrelated questions. Try asking "Which rooms need attention?", '
            .'"Which rooms are empty?", or "What is the forecast for Room 203?"';
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
        return 'Class Eye is a classroom monitoring system for school staff. It tracks room '
            .'occupancy and capacity, flags over-capacity rooms, records a tamper-evident '
            .'activity log for each room, and shows instructor schedules. This AI '
            .'assistant helps answer questions about occupancy, empty rooms, crowded rooms, '
            .'and floor summaries. Answers are advisory only; staff still decide what to do.';
    }

    private function features(): string
    {
        return 'Class Eye can show room occupancy and capacity, flag over-capacity rooms, '
            .'show instructor schedules (subject, days, times, units, room), '
            .'and keep a verifiable activity log. This chat answers both room occupancy '
            .'and schedule questions, and can explain the system, its features, and its security design.';
    }

    private function howItWorks(): string
    {
        return 'Class Eye continuously monitors room headcounts and capacity. When staff update '
            .'a room, the change is logged with a tamper-evident audit hash. The AI assistant '
            .'analyzes current occupancy data to answer staff questions instantly.';
    }

    private function security(): string
    {
        return 'Class Eye applies the CIA triad. Confidentiality: Sanctum bearer tokens expire '
            .'and are revoked on password reset, CORS uses an explicit allow-list, passwords are '
            .'hashed, and the assistant receives only the signed-in user\'s filtered room data, '
            .'never staff details. Integrity: room changes, logs, and snapshots are written in one '
            .'transaction, database constraints reject invalid values, and activity logs use a '
            .'per-room HMAC hash chain that audit:verify recomputes to expose tampering. '
            .'Availability: named rate limits, graceful fallback to the local insights and the local '
            .'assistant when a model fails, and idempotent startup migrations. The assistant is '
            .'read-only and never changes rooms, logs, or alerts.';
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
