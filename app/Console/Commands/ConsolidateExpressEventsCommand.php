<?php

namespace App\Console\Commands;

use App\Services\ExpressEventConsolidationService;
use Illuminate\Console\Command;

class ConsolidateExpressEventsCommand extends Command
{
    protected $signature = 'hpp:consolidate-express-events';

    protected $description = 'Audit dan konsolidasikan data historis Event HPP Express berdasarkan identitas event operasional unik serta tandai data ambigu untuk verifikasi admin.';

    public function handle(ExpressEventConsolidationService $service): int
    {
        $result = $service->consolidateHistoricalEvents();

        $this->table(['Ringkasan Konsolidasi HPP Express', 'Jumlah'], [
            ['Baris event duplikat berhasil digabungkan', $result['merged_events']],
            ['Total event HPP aktif setelah konsolidasi', $result['kept_separate']],
            ['Event ditandai Perlu Verifikasi Admin', $result['flagged_for_verification']],
        ]);

        if ($result['merged_details'] !== []) {
            $this->info('Detail penggabungan event yang terbukti satu identitas operasional:');
            $rows = array_map(fn (array $d): array => [
                $d['primary_event_id'],
                $d['event_name'],
                $d['identity_key'],
                implode(', ', $d['merged_event_ids']),
            ], $result['merged_details']);
            $this->table(['ID Utama', 'Nama Event', 'Identitas', 'ID Duplikat Digabung'], $rows);
        }

        if ($result['flagged_details'] !== []) {
            $this->warn('Event yang memerlukan verifikasi Admin (tidak digabung otomatis):');
            $rows = array_map(fn (array $d): array => [
                $d['event_id'],
                $d['reason'],
            ], $result['flagged_details']);
            $this->table(['ID Event', 'Catatan Verifikasi'], $rows);
        }

        return self::SUCCESS;
    }
}
