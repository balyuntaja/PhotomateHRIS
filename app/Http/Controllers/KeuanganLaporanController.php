<?php

namespace App\Http\Controllers;

use App\Models\Cabang;
use App\Services\KeuanganService;
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

            $pdf = Pdf::loadView('pdf.keuangan-laporan', [
                'period' => $period,
                'cabangName' => $cabangId
                    ? (Cabang::find($cabangId)?->nama_cabang ?? 'Semua Cabang')
                    : 'Semua Cabang',
                'summary' => $service->summary($period['start'], $period['end'], $cabangId),
                'categoryBreakdown' => $service->categoryBreakdown($period['start'], $period['end'], $cabangId),
                'branchBreakdown' => $service->branchBreakdown($period['start'], $period['end']),
                'judulDokumen' => 'Laporan Keuangan Photomate',
                'periode' => $period['label'],
                'tanggalCetak' => now()->day . ' '
                    . \App\Utils\MonthHelper::formatPeriod((int) now()->month, (int) now()->year)
                    . ' ' . now()->format('H:i'),
            ]);

            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'dpi' => 150,
                'defaultFont' => 'sans-serif',
                'isRemoteEnabled' => true,
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
