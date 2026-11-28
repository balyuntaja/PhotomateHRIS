<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\ExpressEventInvoiceService;
use App\Services\InvoiceFinancialService;
use Illuminate\Support\Facades\Auth;

class InvoiceObserver
{
    /**
     * Sinkronkan pencatatan keuangan dan nilai kontrak pada Event HPP Express
     * setiap invoice disimpan: pembuatan, perubahan status, nominal, maupun tanggal acara.
     */
    public function saved(Invoice $invoice): void
    {
        app(InvoiceFinancialService::class)->sync($invoice, Auth::user());
        app(ExpressEventInvoiceService::class)->syncEventsForInvoice($invoice);
    }

    /**
     * Sebelum invoice dihapus: sesuaikan Event HPP Express apabila ada yang terhubung.
     */
    public function deleting(Invoice $invoice): void
    {
        app(ExpressEventInvoiceService::class)->handleInvoiceDeleting($invoice);
    }

    /**
     * Invoice dihapus: batalkan pencatatan pemasukan yang berasal darinya.
     */
    public function deleted(Invoice $invoice): void
    {
        app(InvoiceFinancialService::class)->cancel($invoice);
    }
}
