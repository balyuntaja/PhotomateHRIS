<?php

namespace App\Services;

use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\Karyawan;
use App\Models\SessionReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoiceFinancialService
{
    public const CATEGORY_NAME = 'Pelunasan Invoice';

    public const SOURCE = 'invoice';

    public const STATUS_LUNAS = 'Lunas';

    public const ACCOUNT_NAME = 'Photomate Express';

    /**
     * Selaraskan pencatatan pemasukan dengan kondisi invoice terkini:
     * - Lunas: buat/perbarui satu transaksi pemasukan (tanggal = tanggal mulai acara).
     * - Selain Lunas: batalkan (soft delete) transaksi terkait bila ada.
     *
     * Idempotent: satu invoice hanya memiliki satu transaksi
     * (source_type "invoice" + source_id = ID invoice), termasuk saat status
     * berubah bolak-balik maupun saat disimpan ulang.
     */
    public function sync(Invoice $invoice, ?Karyawan $actor = null): ?FinancialTransaction
    {
        return DB::transaction(function () use ($invoice, $actor): ?FinancialTransaction {
            $transaction = $this->findTransaction($invoice);

            if ($invoice->status !== self::STATUS_LUNAS) {
                if ($transaction && ! $transaction->trashed()) {
                    $transaction->delete();
                }

                return null;
            }

            // Tanpa tanggal acara tidak ada tanggal transaksi yang sah untuk
            // laporan keuangan, jadi pencatatan dilewati.
            if (blank($invoice->event_date)) {
                return null;
            }

            $attributes = [
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
                'amount' => (int) round((float) $invoice->total),
                'category_id' => $this->incomeCategory()->id,
                'account_id' => $this->incomeAccount()->id,
                'cabang_id' => $this->resolveCabangId($invoice),
                'payment_status' => FinancialTransaction::PAYMENT_STATUS_LUNAS,
                'transaction_date' => $invoice->event_date->toDateString(),
                'period_year' => (int) $invoice->event_date->year,
                'period_month' => (int) $invoice->event_date->month,
                'description' => $this->description($invoice),
            ];

            if ($transaction) {
                if ($transaction->trashed()) {
                    $transaction->restore();
                }

                $transaction->fill($attributes)->save();

                return $transaction;
            }

            return FinancialTransaction::create($attributes + [
                'source_type' => self::SOURCE,
                'source_id' => (string) $invoice->id,
                'created_by' => $actor?->karyawan_id,
            ]);
        });
    }

    /**
     * Batalkan pencatatan pemasukan milik invoice (mis. invoice dihapus).
     */
    public function cancel(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice): bool {
            $transaction = $this->findTransaction($invoice);

            if (! $transaction || $transaction->trashed()) {
                return false;
            }

            $transaction->delete();

            return true;
        });
    }

    private function findTransaction(Invoice $invoice): ?FinancialTransaction
    {
        return FinancialTransaction::withTrashed()
            ->where('source_type', self::SOURCE)
            ->where('source_id', (string) $invoice->id)
            ->first();
    }

    /**
     * Kategori pemasukan pelunasan invoice; dibuat otomatis bila belum tersedia.
     */
    private function incomeCategory(): FinancialCategory
    {
        return FinancialCategory::firstOrCreate(
            [
                'name' => self::CATEGORY_NAME,
                'transaction_type' => FinancialTransaction::TYPE_INCOME,
            ],
            [
                'group_name' => null,
                'is_active' => true,
            ],
        );
    }

    /**
     * Akun keuangan penerima pendapatan invoice (Photomate Express).
     */
    private function incomeAccount(): FinancialAccount
    {
        $account = FinancialAccount::query()->where('name', self::ACCOUNT_NAME)->first();

        if (! $account) {
            throw new RuntimeException(
                'Akun "' . self::ACCOUNT_NAME . '" tidak ditemukan pada master akun keuangan. '
                . 'Tambahkan akun tersebut terlebih dahulu.'
            );
        }

        return $account;
    }

    /**
     * Cabang terkait invoice diambil dari rekap sesi yang menautkan invoice
     * (invoice tidak menyimpan cabang); null bila tidak tersedia.
     */
    private function resolveCabangId(Invoice $invoice): ?string
    {
        return SessionReport::query()
            ->where('invoice_id', $invoice->id)
            ->whereNotNull('cabang_id')
            ->orderByDesc('id')
            ->value('cabang_id');
    }

    private function description(Invoice $invoice): string
    {
        return 'Pelunasan Invoice ' . $invoice->invoice_number . ' - ' . $invoice->client_name;
    }
}
