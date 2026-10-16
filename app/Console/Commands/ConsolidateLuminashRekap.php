<?php

namespace App\Console\Commands;

use App\Models\Karyawan;
use App\Services\LuminashRekapService;
use App\Utils\RupiahHelper;
use Illuminate\Console\Command;
use Throwable;

class ConsolidateLuminashRekap extends Command
{
    protected $signature = 'keuangan:consolidate-luminash-rekap
                            {--dry-run : Tampilkan rencana perubahan tanpa menyimpan apa pun}
                            {--actor= : ID karyawan yang dicatat sebagai pembuat transaksi rekap (opsional)}';

    protected $description = 'Satukan transaksi impor CSV Luminash cabang Golio (kategori Pendapatan Booth) menjadi satu transaksi rekap per tanggal tanpa menghitung ganda di laporan keuangan';

    public function handle(): int
    {
        $service = app(LuminashRekapService::class);
        $dryRun = (bool) $this->option('dry-run');

        $actor = null;

        if ($actorId = $this->option('actor')) {
            $actor = Karyawan::query()->where('karyawan_id', $actorId)->first();

            if (! $actor) {
                $this->error('Karyawan dengan ID "' . $actorId . '" tidak ditemukan.');

                return self::FAILURE;
            }
        }

        try {
            $dates = $service->pendingDates();
            $plan = collect($service->plan($dates));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($plan->isEmpty()) {
            $this->info('Tidak ada transaksi Luminash Golio yang perlu direkap.');

            return self::SUCCESS;
        }

        $this->table(
            ['Tanggal', 'Jumlah Transaksi', 'Total', 'Aksi'],
            $plan->map(fn (array $row): array => [
                $row['date'],
                $row['count'],
                RupiahHelper::format($row['total']),
                $row['action'] === 'update' ? 'Perbarui rekap' : 'Buat rekap',
            ])->all(),
        );

        if ($dryRun) {
            $this->warn('MODE DRY-RUN: tidak ada perubahan yang disimpan.');

            return self::SUCCESS;
        }

        try {
            $stats = $service->consolidateDates($dates, $actor);
        } catch (Throwable $exception) {
            $this->error('Gagal: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Baris rekap dibuat', $stats['created']],
            ['Baris rekap diperbarui', $stats['updated']],
            ['Transaksi komponen', $stats['components']],
            ['Total nominal', RupiahHelper::format($stats['total'])],
        ]);

        $this->info('Selesai.');

        return self::SUCCESS;
    }
}
