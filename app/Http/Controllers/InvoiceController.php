<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Karyawan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class InvoiceController extends Controller
{
    /**
     * Masa berlaku tautan PDF invoice yang dibagikan ke customer.
     */
    public const PUBLIC_LINK_TTL_DAYS = 7;

    public function generatePdf(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        $canViewInvoice = $user instanceof Karyawan
            && ($user->isSuperAdmin() || $user->can('view_invoice'));

        // Customer dapat membuka invoice tanpa login hanya melalui signed URL.
        abort_unless(
            $canViewInvoice || $request->hasValidSignature(),
            403,
            'Anda tidak memiliki akses ke invoice ini.'
        );

        $invoice->load('invoiceItems');

        $pdf = Pdf::loadView('pdf.invoice', compact('invoice'));

        return $pdf->stream($invoice->invoice_number . '.pdf');
    }

    /**
     * Buat temporary signed URL untuk dibagikan ke customer.
     */
    public static function temporaryPdfUrl(Invoice $invoice): string
    {
        return URL::temporarySignedRoute(
            'invoice.pdf',
            now()->addDays(self::PUBLIC_LINK_TTL_DAYS),
            ['invoice' => $invoice->getKey()]
        );
    }
}
