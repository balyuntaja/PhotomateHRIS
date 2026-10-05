<?php

namespace App\Http\Controllers;

use App\Models\Cabang;
use App\Models\FinancialTransaction;
use App\Services\KeuanganService;
use App\Utils\MonthHelper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class KeuanganLaporanController extends Controller
{
    public function cetak(Request $request)
    {
        $user = Auth::user();

        abort_unless(
            $user?->isSuperAdmin() || ($user?->can('print_keuangan_report') ?? false),
            403,
            'Anda tidak memiliki akses untuk mencetak laporan keuangan.'
        );

        try {
            $service = app(KeuanganService::class);

            $period = $service->resolvePeriod(
                $request->query('periode'),
                $request->query('dari'),
                $request->query('sampai'),
            );

            $cabangId = filled($request->query('cabang_id')) ? $request->query('cabang_id') : null;

            $transactions = $service
                ->transactionsForReport($period['start'], $period['end'], $cabangId)
                ->get();

            $income = (int) $transactions
                ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
                ->sum('amount');

            $expense = (int) $transactions
                ->where('transaction_type', FinancialTransaction::TYPE_EXPENSE)
                ->sum('amount');

            $pdf = Pdf::loadView('pdf.keuangan-laporan', [
                'period' => $period,
                'cabangName' => $cabangId
                    ? (Cabang::find($cabangId)?->nama_cabang ?? 'Semua Cabang')
                    : 'Semua Cabang',
                'summary' => [
                    'income' => $income,
                    'expense' => $expense,
                    'balance' => $income - $expense,
                ],
                'transactions' => $transactions,
                'judulDokumen' => 'Laporan Keuangan Photomate',
                'periode' => $period['label'],
                'tanggalCetak' => now()->day . ' '
                    . MonthHelper::formatPeriod((int) now()->month, (int) now()->year),
            ]);

            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'dpi' => 150,
                'defaultFont' => 'sans-serif',
                'isRemoteEnabled' => true,
                // Diperlukan agar footer nomor halaman (page_text) dapat dirender.
                'isPhpEnabled' => true,
            ]);

            $filename = sprintf(
                'laporan-keuangan-%s-%s.pdf',
                $period['start']->format('Ymd'),
                $period['end']->format('Ymd')
            );

            return $pdf->download($filename);
        } catch (\Throwable $th) {
            Log::error('Gagal mencetak laporan keuangan Photomate: ' . $th->getMessage(), [
                'periode' => $request->query('periode'),
                'cabang_id' => $request->query('cabang_id'),
            ]);

            abort(500, 'Terjadi kesalahan saat membuat PDF laporan keuangan.');
        }
    }
}
