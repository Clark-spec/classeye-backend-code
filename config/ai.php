<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External AI feature flag
    |--------------------------------------------------------------------------
    |
    | The deterministic insights engine and the local assistant are always
    | available. This flag only controls whether the assistant may call an
    | external language model. Keeping it false means `docker compose up`
    | works without a key and no occupancy data leaves the server.
    |
    */

    'external_enabled' => (bool) env('AI_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Language model provider
    |--------------------------------------------------------------------------
    |
    | Supported values: heuristic, openai, anthropic, ollama.
    | "heuristic" keeps everything on-server. The manager falls back to it if
    | an external provider is misconfigured, times out, or returns an error.
    |
    */

    'provider' => env('AI_PROVIDER', 'heuristic'),
    'api_key' => env('AI_API_KEY', ''),
    'base_url' => env('AI_BASE_URL', ''),
    'model' => env('AI_MODEL', ''),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 10),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 400),
    'monthly_budget_usd' => (float) env('AI_MONTHLY_BUDGET_USD', 5),
    'question_max_length' => (int) env('AI_QUESTION_MAX_LENGTH', 500),
    'context_room_limit' => (int) env('AI_CONTEXT_ROOM_LIMIT', 30),

    /*
    | Personal data must not be sent to a third party unless it is necessary.
    | Staff IDs and names are redacted before any prompt is built.
    */
    'redact_personal_data' => (bool) env('AI_REDACT_PERSONAL_DATA', true),

    /*
    |--------------------------------------------------------------------------
    | Occupancy insights
    |--------------------------------------------------------------------------
    |
    | These values control the deterministic forecasting engine. They are
    | deliberately simple and explainable: a least-squares trend, a forward
    | projection, and a z-score over recent changes.
    |
    */

    'insights' => [
        'window_hours' => (int) env('INSIGHTS_WINDOW_HOURS', 24),
        'forecast_minutes' => (int) env('INSIGHTS_FORECAST_MINUTES', 30),
        'min_snapshots' => (int) env('INSIGHTS_MIN_SNAPSHOTS', 3),
        'anomaly_z_threshold' => (float) env('INSIGHTS_ANOMALY_Z_THRESHOLD', 2.5),
        'min_anomaly_change' => (int) env('INSIGHTS_MIN_ANOMALY_CHANGE', 3),
    ],

];
