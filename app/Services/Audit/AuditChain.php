<?php

namespace App\Services\Audit;

use App\Models\ActivityLog;

/**
 * Verifies the per-room activity hash chain. Legacy rows created before the
 * chain existed have a null hash and are reported separately instead of being
 * treated as tampered.
 */
class AuditChain
{
    /**
     * @return array{valid: bool, checked: int, legacy: int, broken: array<int, array{id: int, room_id: int}>}
     */
    public function verify(?int $roomId = null): array
    {
        $query = ActivityLog::query()
            ->orderBy('room_id')
            ->orderBy('id');

        if ($roomId !== null && $roomId > 0) {
            $query->where('room_id', $roomId);
        }

        $previousByRoom = [];
        $broken = [];
        $checked = 0;
        $legacy = 0;

        foreach ($query->cursor() as $log) {
            if ($log->hash === null) {
                $legacy++;

                continue;
            }

            $previous = $previousByRoom[$log->room_id] ?? null;

            if ($log->prev_hash !== $previous || ! hash_equals($log->computeHash($previous), $log->hash)) {
                $broken[] = ['id' => $log->id, 'room_id' => $log->room_id];
            }

            $previousByRoom[$log->room_id] = $log->hash;
            $checked++;
        }

        return [
            'valid' => $broken === [],
            'checked' => $checked,
            'legacy' => $legacy,
            'broken' => $broken,
        ];
    }
}
