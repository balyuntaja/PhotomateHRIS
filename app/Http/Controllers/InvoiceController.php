<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceController extends Controller
{
    public function generatePdf(Invoice $invoice)
    {
        $invoice->load('invoiceItems');
        
        $pdf = Pdf::loadView('pdf.invoice', compact('invoice'));
        
        return $pdf->stream($invoice->invoice_number . '.pdf');
    }
}
