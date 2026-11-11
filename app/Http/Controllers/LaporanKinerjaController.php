<?php

namespace App\Http\Controllers;

use App\Models\Karyawan;
use App\Services\LaporanKinerjaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LaporanKinerjaController extends Controller
{
    public function cetak(Request $request, int $tahun, int $bulan)
    {
        $this->authorizeReportAccess($request->user());

        try {
            /** @var LaporanKinerjaService $service */
            $service = app(LaporanKinerjaService::class);

            $summary = $service->getMonthlySummary($tahun, $bulan);

            if (empty($summary)) {
                abort(404, 'Data laporan kinerja tidak ditemukan untuk periode tersebut.');
            }

            $dailyPerformance = $service->getDailyPerformance($tahun, $bulan);

            $pdfData = [
                'summary' => $summary,
                'dailyPerformance' => $dailyPerformance,
                'judulDokumen' => 'Laporan Kinerja Karyawan',
                'periode' => $summary['period']['label'],
                'tanggalCetak' => Carbon::now()->format('d/m/Y H:i:s'),
            ];

            $pdf = Pdf::loadView('pdf.laporan-kinerja', $pdfData);
            $pdf->setPaper('A4', 'portrait');
            $pdf->setOptions([
                'dpi' => 150,
                'defaultFont' => 'sans-serif',
                'isRemoteEnabled' => true,
            ]);

            $filename = sprintf('laporan-kinerja-%d-%02d.pdf', $tahun, $bulan);

            return $pdf->download($filename);
        } catch (\Throwable $th) {
            Log::error('Gagal mencetak laporan kinerja: ' . $th->getMessage(), [
                'tahun' => $tahun,
                'bulan' => $bulan,
            ]);

            abort(500, 'Terjadi kesalahan saat membuat PDF laporan kinerja.');
        }
    }

    private function authorizeReportAccess(?Karyawan $user): void
    {
        abort_unless(
            $user !== null
                && ($user->isSuperAdmin()
                    || $user->can('view_laporan_kinerja')
                    || $user->can('view_any_laporan_kinerja')),
            403,
            'Anda tidak memiliki akses ke laporan kinerja.'
        );
    }
}
