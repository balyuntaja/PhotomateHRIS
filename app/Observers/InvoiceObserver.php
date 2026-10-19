<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\InvoiceFinancialService;
use Illuminate\Support\Facades\Auth;

class InvoiceObserver
{
    /**
     * Sinkronkan pencatatan keuangan setiap invoice disimpan: pembuatan,
     * perubahan status, nominal, maupun tanggal acara.
     */
    public function saved(Invoice $invoice): void
    {
        app(InvoiceFinancialService::class)->sync($invoice, Auth::user());
    }

    /**
     * Invoice dihapus: batalkan pencatatan pemasukan yang berasal darinya.
     */
    public function deleted(Invoice $invoice): void
    {
        app(InvoiceFinancialService::class)->cancel($invoice);
    }
}
