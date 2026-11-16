<?php

namespace App\Services\Ai;

use App\Contracts\AiProvider;
use Illuminate\Support\Str;

/**
 * Default provider: answers from the insight context using deterministic
 * templates. It needs no API key, sends no data anywhere, and is the fallback
 * whenever an external provider fails.
 */
class HeuristicProvider implements AiProvider
{
    public function name(): string
    {
        return 'heuristic';
    }

    public function model(): ?string
    {
        return null;
    }

    public function answer(string $question, array $context, array $history = []): string
    {
        $normalized = Str::lower($question);
        $rooms = $context['rooms'] ?? [];
        $forecastMinutes = (int) ($context['forecast_minutes'] ?? 30);

        if (preg_match('/\broom\s*([0-9]{1,4})\b/i', $question, $matches) === 1) {
            $name = 'Room '.$matches[1];
            $room = collect($rooms)->first(
                fn (array $item): bool => Str::lower((string) $item['name']) === Str::lower($name)
            );

            if (is_array($room)) {
                return $this->roomAnswer($room, $forecastMinutes);
            }
        }

        if (Str::contains($normalized, ['anomal', 'unusual', 'spike', 'jump', 'sudden'])) {
            $anomalies = $context['anomalies'] ?? [];

            if ($anomalies === []) {
                return 'No unusual occupancy changes were detected in the recent window.';
            }

            return "Unusual occupancy changes:\n".implode("\n", array_map(
                fn (array $room): string => '- '.$room['name'].': '.$room['reason'],
                $anomalies
            ))."\n\nThese flags are advisory; verify with a physical check.";
        }

        if (Str::contains($normalized, ['empty', 'available', 'free', 'vacant'])) {
            $empty = $context['empty_rooms'] ?? [];

            return $empty === []
                ? 'No rooms are currently empty.'
                : 'Empty rooms right now: '.implode(', ', $empty).'.';
        }

        if (Str::contains($normalized, ['floor', 'wing', 'building'])) {
            $byFloor = $context['by_floor'] ?? [];

            if ($byFloor === []) {
                return 'Floor-level occupancy data is not available yet.';
            }

            return "Occupancy by floor:\n".implode("\n", array_map(
                fn (array $floor): string => sprintf(
                    '- Floor %d: %d%% used, %d room%s at risk.',
                    $floor['floor'],
                    $floor['utilization_percent'],
                    $floor['at_risk'],
                    $floor['at_risk'] === 1 ? '' : 's'
                ),
                $byFloor
            ));
        }

        if (Str::contains($normalized, ['risk', 'capacity', 'full', 'alert', 'busy', 'crowd', 'over'])) {
            $atRisk = $context['at_risk'] ?? [];

            if ($atRisk === []) {
                return sprintf(
                    'No rooms are currently over capacity or projected to reach capacity in the next %d minutes.',
                    $forecastMinutes
                );
            }

            return "Rooms needing attention:\n".implode("\n", array_map(
                fn (array $room): string => sprintf(
                    '- %s (%d%% used, forecast %d in %d min): %s',
                    $room['name'],
                    $room['utilization_percent'],
                    $room['forecast_people_count'],
                    $forecastMinutes,
                    $room['reason']
                ),
                $atRisk
            ))."\n\nForecasts are advisory.";
        }

        if (Str::contains($normalized, ['forecast', 'predict', 'trend', 'next'])) {
            $ranked = collect($rooms)
                ->sortByDesc(fn (array $room): float => (float) $room['utilization_percent'])
                ->take(5)
                ->values();

            return "Forecast for the highest-utilization rooms:\n".implode("\n", $ranked->map(
                fn (array $room): string => sprintf(
                    '- %s: %d now, %d expected in %d min (%s confidence).',
                    $room['name'],
                    $room['people_count'],
                    $room['forecast_people_count'],
                    $forecastMinutes,
                    $room['confidence']
                )
            )->all());
        }

        return $this->summaryAnswer($context);
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function roomAnswer(array $room, int $forecastMinutes): string
    {
        $anomaly = $room['anomaly'] ?? null;
        $anomalyText = is_array($anomaly)
            ? sprintf(' An unusual change of %+d people was detected.', (int) ($anomaly['change'] ?? 0))
            : '';

        return sprintf(
            "%s is %s at %d/%d (%d%% used). The %d-minute forecast is %d people with %s confidence. %s%s\n\nAdvisory only.",
            $room['name'],
            $room['status'],
            $room['people_count'],
            $room['capacity'],
            $room['utilization_percent'],
            $forecastMinutes,
            $room['forecast_people_count'],
            $room['confidence'],
            $room['reason'],
            $anomalyText
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function summaryAnswer(array $context): string
    {
        $counts = $context['counts'] ?? [];
        $top = collect($context['rooms'] ?? [])
            ->sortByDesc(fn (array $room): float => (float) $room['utilization_percent'])
            ->take(3)
            ->values();

        $lines = $top->map(fn (array $room): string => sprintf(
            '- %s: %d/%d (%d%%), %s.',
            $room['name'],
            $room['people_count'],
            $room['capacity'],
            $room['utilization_percent'],
            $room['risk_level']
        ))->all();

        return sprintf(
            "%s\n\n%d rooms monitored, %d at risk, %d unusual change(s). Average utilization is %d%%.\n\nHighest utilization:\n%s\n\nAsk about a room, a floor, empty rooms, anomalies, or the forecast.",
            $context['headline'] ?? 'Occupancy summary',
            (int) ($counts['rooms'] ?? 0),
            (int) ($counts['at_risk'] ?? 0),
            (int) ($counts['anomalies'] ?? 0),
            (int) ($counts['average_utilization_percent'] ?? 0),
            $lines === [] ? '- No room data available.' : implode("\n", $lines)
        );
    }
}
