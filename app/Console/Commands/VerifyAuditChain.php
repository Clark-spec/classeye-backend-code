<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditChain;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify {--room= : Only verify one room ID}';

    protected $description = 'Verify the tamper-evident activity log hash chain';

    public function handle(AuditChain $audit): int
    {
        $roomOption = $this->option('room');
        $result = $audit->verify($roomOption !== null ? (int) $roomOption : null);

        $this->info(sprintf(
            'Checked %d hashed entries (%d legacy entries skipped).',
            $result['checked'],
            $result['legacy']
        ));

        if ($result['valid']) {
            $this->info('Audit chain is valid.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d entries failed verification.', count($result['broken'])));
        $this->table(
            ['Log ID', 'Room ID'],
            array_map(fn (array $row): array => [$row['id'], $row['room_id']], $result['broken'])
        );

        return self::FAILURE;
    }
}
