<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\Perusahaan;
use App\Services\KeuanganService;
use App\Services\RekapPerbulanService;
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

            $requestedAccountId = $request->query('account_id');
            $accountId = is_scalar($requestedAccountId) && filled($requestedAccountId)
                ? FinancialAccount::query()->whereKey($requestedAccountId)->value('id')
                : null;

            $accountName = $accountId
                ? (FinancialAccount::find($accountId)?->name ?? 'Semua Akun')
                : 'Semua Akun';

            $format = (string) $request->query('format', 'detail');

            if ($format === 'laba_rugi') {
                return $this->cetakLabaRugi($service, $period, $accountId, $accountName);
            }

            return $this->cetakDetail($service, $period, $accountId, $accountName);
        } catch (\Throwable $th) {
            Log::error('Gagal mencetak laporan keuangan Photomate: ' . $th->getMessage(), [
                'periode' => $request->query('periode'),
                'account_id' => $request->query('account_id'),
                'format' => $request->query('format'),
            ]);

            abort(500, 'Terjadi kesalahan saat membuat PDF laporan keuangan.');
        }
    }

    /**
     * Format 1: Laporan Detail Transaksi (Continuous Content Flow).
     */
    protected function cetakDetail(
        KeuanganService $service,
        array $period,
        ?int $accountId,
        string $accountName
    ) {
        $transactions = $service
            ->transactionsForReport($period['start'], $period['end'], null, $accountId)
            ->get();

        $income = (int) $transactions
            ->where('transaction_type', FinancialTransaction::TYPE_INCOME)
            ->sum('amount');

        $expense = (int) $transactions
            ->where('transaction_type', FinancialTransaction::TYPE_EXPENSE)
            ->sum('amount');

        $pdf = Pdf::loadView('pdf.keuangan-laporan', [
            'period' => $period,
            'accountName' => $accountName,
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

        $pdf->setPaper('A4', 'landscape');
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
    }

    /**
     * Format 2: Laporan Laba Rugi Photomate.
     */
    protected function cetakLabaRugi(
        KeuanganService $service,
        array $period,
        ?int $accountId,
        string $accountName
    ) {
        $data = $service->labaRugiReport($period['start'], $period['end'], $accountId, true);

        $tanggalCetak = now()->day . ' '
            . MonthHelper::formatPeriod((int) now()->month, (int) now()->year);

        $pdf = Pdf::loadView('pdf.keuangan-laba-rugi', [
            'period' => $period,
            'accountName' => $accountName,
            'pendapatanRows' => $data['pendapatan']['rows'],
            'pendapatanLain' => [],
            'totalPendapatan' => $data['pendapatan']['total'],
            'bebanRows' => $data['beban']['rows'],
            'bebanLain' => [],
            'totalBebanUsaha' => $data['beban']['total'],
            'labaRugi' => $data['ringkasan']['laba_rugi'],
            'statusLabel' => $data['ringkasan']['status_label'],
            'statusType' => $data['ringkasan']['status'],
            'judulDokumen' => 'Laporan Laba Rugi Photomate',
            'periode' => $period['label'],
            'tanggalCetak' => $tanggalCetak,
        ]);

        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions([
            'dpi' => 150,
            'defaultFont' => 'sans-serif',
            'isRemoteEnabled' => true,
            'isPhpEnabled' => true,
        ]);

        $filename = sprintf(
            'laporan-laba-rugi-photomate-%s-%s.pdf',
            $period['start']->format('Ymd'),
            $period['end']->format('Ymd')
        );

        return $pdf->download($filename);
    }

    /**
     * Format 3: Rekap Perbulan Photomate (Landscape Dashboard Overview).
     */
    public function cetakRekapPerbulan(Request $request)
    {
        $user = Auth::user();

        abort_unless(
            $user?->isSuperAdmin() || ($user?->can('print_keuangan_report') ?? false) || ($user?->can('view_keuangan_report') ?? false),
            403,
            'Anda tidak memiliki akses untuk mencetak rekap perbulan.'
        );

        try {
            $service = app(RekapPerbulanService::class);

            $month = (int) $request->query('bulan', now()->month);
            $year = (int) $request->query('tahun', now()->year);

            $report = $service->getReportData($year, $month);
            $perusahaan = Perusahaan::first();

            $pdf = Pdf::loadView('pdf.rekap-perbulan', [
                'report' => $report,
                'perusahaan' => $perusahaan,
                'judulDokumen' => 'Rekap Perbulan Photomate',
                'periode' => $report['period']['label'],
                'tanggalCetak' => now()->day . ' ' . MonthHelper::formatPeriod((int) now()->month, (int) now()->year),
            ]);

            $pdf->setPaper('A4', 'landscape');
            $pdf->setOptions([
                'dpi' => 150,
                'defaultFont' => 'sans-serif',
                'isRemoteEnabled' => true,
                'isPhpEnabled' => true,
            ]);

            $filename = sprintf(
                'rekap-perbulan-photomate-%s-%s.pdf',
                $year,
                str_pad((string) $month, 2, '0', STR_PAD_LEFT)
            );

            return $pdf->download($filename);
        } catch (\Throwable $th) {
            Log::error('Gagal mencetak rekap perbulan Photomate: ' . $th->getMessage(), [
                'bulan' => $request->query('bulan'),
                'tahun' => $request->query('tahun'),
                'trace' => $th->getTraceAsString(),
            ]);

            abort(500, 'Terjadi kesalahan saat membuat PDF rekap perbulan.');
        }
    }
}
