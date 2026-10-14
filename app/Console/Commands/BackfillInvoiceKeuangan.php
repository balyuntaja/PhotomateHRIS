<?php

namespace App\Console\Commands;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Services\InvoiceFinancialService;
use App\Utils\RupiahHelper;
use Illuminate\Console\Command;

class BackfillInvoiceKeuangan extends Command
{
    protected $signature = 'keuangan:backfill-invoice
                            {--dry-run : Tampilkan rencana perubahan tanpa menyimpan apa pun}
                            {--actor= : ID karyawan yang dicatat sebagai pembuat transaksi backfill (opsional)}';

    protected $description = 'Catat transaksi pemasukan untuk invoice berstatus Lunas yang belum tercatat di laporan keuangan';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $actor = null;

        if ($actorId = $this->option('actor')) {
            $actor = Karyawan::query()->where('karyawan_id', $actorId)->first();

            if (! $actor) {
                $this->error('Karyawan dengan ID "' . $actorId . '" tidak ditemukan.');

                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->warn('MODE DRY-RUN: tidak ada perubahan yang disimpan.');
        }

        $processed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        Invoice::query()
            ->where('status', InvoiceFinancialService::STATUS_LUNAS)
            ->chunkById(100, function ($invoices) use (&$processed, &$created, &$updated, &$skipped, $dryRun, $actor): void {
                foreach ($invoices as $invoice) {
                    if (blank($invoice->event_date)) {
                        $skipped++;
                        $this->warn('  Dilewati (tanpa tanggal acara): ' . $invoice->invoice_number);

                        continue;
                    }

                    $processed++;

                    $alreadyRecorded = FinancialTransaction::withTrashed()
                        ->where('source_type', InvoiceFinancialService::SOURCE)
                        ->where('source_id', (string) $invoice->id)
                        ->exists();

                    if ($dryRun) {
                        $this->line('  [dry-run] ' . $invoice->invoice_number . ' · ' . $invoice->client_name . ' · ' . RupiahHelper::format($invoice->total) . ' · ' . $invoice->event_date->toDateString());

                        continue;
                    }

                    if (app(InvoiceFinancialService::class)->sync($invoice, $actor)) {
                        $alreadyRecorded ? $updated++ : $created++;
                    }
                }
            });

        $this->newLine();
        $this->table(['Ringkasan', 'Jumlah'], [
            ['Invoice Lunas diproses', $processed],
            ['Transaksi keuangan dibuat', $created],
            ['Transaksi keuangan diperbarui', $updated],
            ['Invoice dilewati (tanpa tanggal acara)', $skipped],
        ]);

        $this->info($dryRun ? 'Dry-run selesai. Jalankan tanpa --dry-run untuk menyimpan.' : 'Backfill selesai.');

        return self::SUCCESS;
    }
}
