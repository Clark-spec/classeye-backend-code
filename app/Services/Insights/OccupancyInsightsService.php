<?php

namespace App\Services\Insights;

use App\Models\Room;
use Illuminate\Support\Collection;

/**
 * Deterministic occupancy intelligence.
 *
 * This service is intentionally dependency-free: it uses a least-squares
 * trend, a forward projection, and a z-score over recent changes. That keeps
 * the feature available offline, cheap to run, and explainable in a report.
 *
 * The output is advisory only. Nothing in this class writes to the database.
 */
class OccupancyInsightsService
{
    private int $windowHours;

    private int $forecastMinutes;

    private int $minSnapshots;

    private float $anomalyThreshold;

    private int $minAnomalyChange;

    public function __construct()
    {
        $config = config('ai.insights', []);

        $this->windowHours = (int) ($config['window_hours'] ?? 24);
        $this->forecastMinutes = (int) ($config['forecast_minutes'] ?? 30);
        $this->minSnapshots = (int) ($config['min_snapshots'] ?? 3);
        $this->anomalyThreshold = (float) ($config['anomaly_z_threshold'] ?? 2.5);
        $this->minAnomalyChange = (int) ($config['min_anomaly_change'] ?? 3);
    }

    /**
     * School-wide view, sorted by risk and then by utilisation.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $insights = Room::query()
            ->with(['snapshots' => function ($query): void {
                $query->where('recorded_at', '>=', now()->subHours($this->windowHours))
                    ->reorder('recorded_at', 'asc');
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (Room $room): array => $this->forRoom($room))
            ->values();

        $sorted = $insights
            ->sortBy(fn (array $insight): array => [
                $this->riskRank($insight['risk_level']),
                -$insight['utilization'],
            ])
            ->values();

        $atRisk = $sorted->whereIn('risk_level', ['critical', 'high'])->count();
        $anomalies = $sorted->filter(fn (array $insight): bool => $insight['anomaly']['detected'])->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'window_hours' => $this->windowHours,
            'forecast_minutes' => $this->forecastMinutes,
            'rooms_analysed' => $sorted->count(),
            'at_risk' => $atRisk,
            'anomalies' => $anomalies,
            'average_utilization' => $sorted->isEmpty()
                ? 0.0
                : round((float) $sorted->avg('utilization'), 3),
            'headline' => $this->headline($atRisk, $anomalies),
            'rooms' => $sorted->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forRoom(Room $room): array
    {
        $series = $this->series($room);
        $samples = $series->count();

        $slopePerMinute = $this->slope($series);
        $current = (float) $room->people_count;
        $forecast = max(0.0, $current + ($slopePerMinute * $this->forecastMinutes));
        $utilization = $room->capacity > 0 ? $current / $room->capacity : 0.0;
        $headroom = $room->capacity - $current;

        $minutesToCapacity = null;
        if (! $room->is_maintenance && $slopePerMinute > 0.005 && $headroom > 0) {
            $minutesToCapacity = (int) ceil($headroom / $slopePerMinute);
        }

        $anomaly = $this->detectAnomaly($series);
        $risk = $this->riskLevel($room, $forecast, $utilization, $minutesToCapacity);

        return [
            'room_id' => $room->id,
            'name' => $room->name,
            'floor' => $room->floor,
            'building' => $room->building,
            'status' => $room->status,
            'capacity' => $room->capacity,
            'people_count' => $room->people_count,
            'forecast_people_count' => (int) round($forecast),
            'forecast_minutes' => $this->forecastMinutes,
            'growth_per_hour' => round($slopePerMinute * 60, 1),
            'utilization' => round($utilization, 3),
            'risk_level' => $risk,
            'minutes_to_capacity' => $minutesToCapacity,
            'anomaly' => $anomaly,
            'confidence' => $this->confidence($series),
            'reason' => $this->reason($room, $forecast, $utilization, $minutesToCapacity, $anomaly, $samples),
            'samples' => $samples,
        ];
    }

    /**
     * Convert snapshots into (minute, people) points.
     *
     * @return Collection<int, array{minute: float, people: float}>
     */
    private function series(Room $room): Collection
    {
        // reorder() drops the relation's default newest-first ordering; the
        // regression needs points in chronological order.
        $snapshots = $room->relationLoaded('snapshots')
            ? $room->getRelation('snapshots')
            : $room->snapshots()
                ->where('recorded_at', '>=', now()->subHours($this->windowHours))
                ->reorder('recorded_at', 'asc')
                ->get(['people_count', 'recorded_at']);

        if ($snapshots->isEmpty()) {
            return collect([['minute' => 0.0, 'people' => (float) $room->people_count]]);
        }

        $start = $snapshots->first()->recorded_at;

        return $snapshots->values()->map(function ($snapshot, int $index) use ($start): array {
            $minutes = $start !== null
                ? $start->diffInSeconds($snapshot->recorded_at) / 60
                : (float) ($index * 30);

            return [
                'minute' => (float) $minutes,
                'people' => (float) $snapshot->people_count,
            ];
        });
    }

    /**
     * Least-squares slope in people per minute.
     *
     * @param  Collection<int, array{minute: float, people: float}>  $series
     */
    private function slope(Collection $series): float
    {
        $count = $series->count();

        if ($count < 2) {
            return 0.0;
        }

        $xs = $series->pluck('minute')->map(fn ($value): float => (float) $value)->all();
        $ys = $series->pluck('people')->map(fn ($value): float => (float) $value)->all();

        $meanX = array_sum($xs) / $count;
        $meanY = array_sum($ys) / $count;

        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($xs as $index => $x) {
            $deltaX = $x - $meanX;
            $numerator += $deltaX * ($ys[$index] - $meanY);
            $denominator += $deltaX * $deltaX;
        }

        if ($denominator <= 0.0) {
            return 0.0;
        }

        // Clamp to a physically plausible range so a bad data point cannot
        // produce an absurd forecast.
        return max(-10.0, min(10.0, $numerator / $denominator));
    }

    /**
     * Flag the latest change when it is statistically unusual compared with
     * the earlier changes in the window.
     *
     * @param  Collection<int, array{minute: float, people: float}>  $series
     * @return array{detected: bool, direction: string|null, change: int, z_score: float|null}
     */
    private function detectAnomaly(Collection $series): array
    {
        $points = $series->values();
        $rates = [];

        for ($index = 1; $index < $points->count(); $index++) {
            $deltaMinutes = max(1.0, $points[$index]['minute'] - $points[$index - 1]['minute']);
            $deltaPeople = $points[$index]['people'] - $points[$index - 1]['people'];
            $rates[] = $deltaPeople / $deltaMinutes;
        }

        if (count($rates) < 3) {
            return ['detected' => false, 'direction' => null, 'change' => 0, 'z_score' => null];
        }

        $latestRate = array_pop($rates);
        $count = count($rates);
        $mean = array_sum($rates) / $count;
        $variance = 0.0;

        foreach ($rates as $rate) {
            $variance += ($rate - $mean) ** 2;
        }

        $stdDev = sqrt($variance / $count);

        if ($stdDev < 0.0001) {
            return ['detected' => false, 'direction' => null, 'change' => 0, 'z_score' => null];
        }

        $zScore = ($latestRate - $mean) / $stdDev;
        $latestDelta = (float) $points->last()['people'] - (float) $points->get($points->count() - 2)['people'];
        $detected = abs($zScore) >= $this->anomalyThreshold
            && abs($latestDelta) >= $this->minAnomalyChange;

        return [
            'detected' => $detected,
            'direction' => $detected ? ($latestDelta > 0 ? 'increase' : 'decrease') : null,
            'change' => $detected ? (int) round($latestDelta) : 0,
            'z_score' => round($zScore, 2),
        ];
    }

    private function riskLevel(
        Room $room,
        float $forecast,
        float $utilization,
        ?int $minutesToCapacity
    ): string {
        if ($room->is_maintenance) {
            return 'low';
        }

        if ($room->people_count > $room->capacity) {
            return 'critical';
        }

        if ($minutesToCapacity !== null && $minutesToCapacity <= $this->forecastMinutes) {
            return 'high';
        }

        if ($forecast >= $room->capacity) {
            return 'high';
        }

        if ($utilization >= 0.8) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  Collection<int, array{minute: float, people: float}>  $series
     */
    private function confidence(Collection $series): string
    {
        $samples = $series->count();
        $span = $samples > 1
            ? (float) $series->last()['minute'] - (float) $series->first()['minute']
            : 0.0;

        if ($samples >= 6 && $span >= 60) {
            return 'high';
        }

        if ($samples >= 3 && $span >= 20) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array{detected: bool, direction: string|null, change: int, z_score: float|null}  $anomaly
     */
    private function reason(
        Room $room,
        float $forecast,
        float $utilization,
        ?int $minutesToCapacity,
        array $anomaly,
        int $samples
    ): string {
        $percent = (int) round($utilization * 100);

        if ($room->is_maintenance) {
            $reason = 'Room is under maintenance; occupancy is not being forecast.';
        } elseif ($room->people_count > $room->capacity) {
            $reason = sprintf(
                'Over capacity by %d people (%d/%d).',
                $room->people_count - $room->capacity,
                $room->people_count,
                $room->capacity
            );
        } elseif ($minutesToCapacity !== null && $minutesToCapacity <= $this->forecastMinutes) {
            $reason = sprintf(
                'Trending to capacity in about %d minutes (%d%% used now).',
                $minutesToCapacity,
                $percent
            );
        } elseif ($forecast >= $room->capacity) {
            $reason = sprintf('Projected to reach capacity within %d minutes.', $this->forecastMinutes);
        } elseif ($utilization >= 0.8) {
            $reason = sprintf('At %d%% of capacity; keep monitoring for further growth.', $percent);
        } elseif ($samples < $this->minSnapshots) {
            $reason = sprintf(
                'Limited history: %d%% of capacity used, so the forecast has low confidence.',
                $percent
            );
        } else {
            $reason = sprintf('Occupancy is stable at %d%% of capacity.', $percent);
        }

        if ($anomaly['detected']) {
            $reason .= sprintf(
                ' Unusual %s of %+d people detected.',
                $anomaly['direction'] === 'increase' ? 'jump' : 'drop',
                $anomaly['change']
            );
        }

        return $reason;
    }

    private function headline(int $atRisk, int $anomalies): string
    {
        $parts = [];

        if ($atRisk > 0) {
            $parts[] = $atRisk.' room'.($atRisk === 1 ? '' : 's').' need attention';
        }

        if ($anomalies > 0) {
            $parts[] = $anomalies.' unusual change'.($anomalies === 1 ? '' : 's').' detected';
        }

        return $parts === []
            ? 'All rooms are within capacity and no unusual changes were detected.'
            : ucfirst(implode(' and ', $parts)).'.';
    }

    private function riskRank(string $risk): int
    {
        return match ($risk) {
            'critical' => 0,
            'high' => 1,
            'medium' => 2,
            default => 3,
        };
    }
}
