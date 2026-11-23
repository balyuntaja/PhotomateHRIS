@php
    use App\Utils\RupiahHelper;

    $period = $report['period'];
    $summary = $report['summary'];
    $boothTable = $report['booth_table'];
    $utangTable = $report['utang_table'];
    $expenseMatrix = $report['expense_matrix'];
    $donutChart = $report['donut_chart'];
    $trendChart = $report['trend_chart'];

    $logoPath = public_path('images/logo.png');
    $hasLogo = file_exists($logoPath);
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>{{ $judulDokumen }} - {{ $periode }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 20px 24px 30px 24px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9px;
            color: #1f2937;
            margin: 0;
            padding: 0;
            line-height: 1.3;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        .header-table {
            width: 100%;
            margin-bottom: 8px;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 6px;
        }

        .header-table td {
            vertical-align: middle;
            border: none;
            padding: 0;
        }

        .title-doc {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .subtitle-doc {
            font-size: 10px;
            color: #475569;
            margin-top: 2px;
        }

        .company-info {
            text-align: right;
            font-size: 9px;
            color: #475569;
            line-height: 1.35;
        }

        .company-name {
            font-size: 12px;
            font-weight: bold;
            color: #0f172a;
        }

        /* Top visual section */
        .visual-card {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 10px;
            margin-bottom: 8px;
        }

        .visual-title {
            font-size: 10px;
            font-weight: bold;
            color: #1e293b;
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 0.3px;
        }

        .pill-booth {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
            margin-right: 6px;
        }

        .pill-janus { background-color: #dbeafe; color: #1e40af; border: 1px solid #bfdbfe; }
        .pill-golio { background-color: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .pill-express { background-color: #ede9fe; color: #5b21b6; border: 1px solid #ddd6fe; }

        /* Banner Bulan */
        .banner-month {
            background-color: #bae6fd;
            color: #0369a1;
            border: 1px solid #7dd3fc;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 5px 0;
            margin-bottom: 8px;
            border-radius: 4px;
        }

        /* Two columns container */
        .row-table {
            width: 100%;
            margin-bottom: 8px;
        }

        .row-table td.col-left {
            width: 32%;
            vertical-align: top;
            padding-right: 6px;
        }

        .row-table td.col-right {
            width: 68%;
            vertical-align: top;
            padding-left: 6px;
        }

        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5px;
            border: 1px solid #cbd5e1;
        }

        .data-table th, .data-table td {
            border: 1px solid #e2e8f0;
            padding: 3.5px 5px;
        }

        .header-purple {
            background-color: #e9d5ff;
            color: #581c87;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 9.5px;
            padding: 5px;
            border: 1px solid #d8b4fe !important;
        }

        .header-green {
            background-color: #bbf7d0;
            color: #14532d;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 9.5px;
            padding: 5px;
            border: 1px solid #86efac !important;
        }

        .header-salmon {
            background-color: #fecdd3;
            color: #881337;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 9.5px;
            padding: 5px;
            border: 1px solid #fda4af !important;
        }

        .header-yellow {
            background-color: #fef08a;
            color: #713f12;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            font-size: 9.5px;
            padding: 5px;
            border: 1px solid #fde047 !important;
        }

        .sub-header th {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: bold;
            font-size: 8px;
        }

        .text-left { text-align: left; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }

        .font-mono { font-family: 'DejaVu Sans Mono', monospace; }
        .font-bold { font-weight: bold; }

        .badge {
            display: inline-block;
            padding: 1.5px 4px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 8px;
        }

        .badge-success { background-color: #dcfce7; color: #166534; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-danger  { background-color: #ffe4e6; color: #9f1239; }

        .total-row td {
            background-color: #f1f5f9;
            font-weight: bold;
            border-top: 1.5px solid #94a3b8;
            color: #0f172a;
        }
    </style>
</head>

<body>
    {{-- Header Dokumen --}}
    <table class="header-table">
        <tr>
            <td style="width: 50%;">
                <div class="title-doc">REKAP PERBULAN</div>
                <div class="subtitle-doc">Ringkasan Konsolidasi Performa Bisnis Photomate · Periode: <strong>{{ $periode }}</strong></div>
            </td>
            <td style="width: 50%;" class="company-info">
                <div class="company-name">{{ $perusahaan->nama_perusahaan ?? 'Photomate Indonesia' }}</div>
                <div>{{ $perusahaan->email ?? 'info@photomate.id' }} · {{ $perusahaan->nomor_telepon ?? '-' }}</div>
                <div>Dicetak pada: {{ $tanggalCetak }}</div>
            </td>
        </tr>
    </table>

    {{-- Visual Section: Komposisi 3 Booth & Ringkasan Tren --}}
    <table style="width: 100%; margin-bottom: 8px;">
        <tr>
            {{-- Komposisi 3 Booth --}}
            <td style="width: 45%; vertical-align: top; padding-right: 6px;">
                <div class="visual-card">
                    <div class="visual-title">Komposisi Pendapatan 3 Booth</div>
                    <table style="width: 100%; margin-top: 4px;">
                        <tr>
                            @foreach($boothTable['rows'] as $bName => $bData)
                                @php
                                    $bPct = $boothTable['total']['income'] > 0 
                                        ? round(($bData['income'] / $boothTable['total']['income']) * 100, 1) 
                                        : 0;
                                    $pillClass = match($bName) {
                                        'Janus' => 'pill-janus',
                                        'Golio' => 'pill-golio',
                                        'Express' => 'pill-express',
                                        default => 'pill-janus',
                                    };
                                @endphp
                                <td style="width: 33.3%; text-align: center; vertical-align: top;">
                                    <div class="pill-booth {{ $pillClass }}" style="display: block; margin: 0 2px;">
                                        <div>{{ $bName }} ({{ $bPct }}%)</div>
                                        <div style="font-size: 8px; font-weight: normal; margin-top: 1px;">
                                            {{ RupiahHelper::format($bData['income']) }}
                                        </div>
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    </table>
                </div>
            </td>

            {{-- Tren 6 Bulan Terakhir --}}
            <td style="width: 55%; vertical-align: top; padding-left: 6px;">
                <div class="visual-card">
                    <div class="visual-title">Tren Pendapatan 6 Bulan Terakhir (Rp)</div>
                    <table class="data-table" style="font-size: 8px; margin-top: 2px;">
                        <tr class="sub-header">
                            <th class="text-left" style="width: 22%;">Bulan</th>
                            <th class="text-right" style="width: 26%;">Janus</th>
                            <th class="text-right" style="width: 26%;">Golio</th>
                            <th class="text-right" style="width: 26%;">Express</th>
                        </tr>
                        @php
                            $labels = $trendChart['labels'];
                            $janusData = $trendChart['datasets'][0]['data'] ?? [];
                            $golioData = $trendChart['datasets'][1]['data'] ?? [];
                            $expressData = $trendChart['datasets'][2]['data'] ?? [];
                        @endphp
                        @foreach($labels as $idx => $lbl)
                            <tr style="{{ $idx % 2 === 1 ? 'background-color: #f8fafc;' : '' }}">
                                <td class="text-left font-bold">{{ $lbl }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($janusData[$idx] ?? 0) }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($golioData[$idx] ?? 0) }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($expressData[$idx] ?? 0) }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            </td>
        </tr>
    </table>

    {{-- Banner Bulan --}}
    <div class="banner-month">
        {{ strtoupper($period['month_name']) }} {{ $period['year'] }}
    </div>

    {{-- Middle Section: Summary & Income Tables --}}
    <table class="row-table">
        <tr>
            {{-- SUMMARY TABLE --}}
            <td class="col-left">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th colspan="3" class="header-purple">SUMMARY {{ strtoupper($period['month_name']) }}</th>
                        </tr>
                        <tr class="sub-header">
                            <th class="text-left">Keterangan</th>
                            <th class="text-right">Nominal</th>
                            <th class="text-center">Persentase</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($summary as $row)
                            @php
                                $badgeClass = match($row['tone']) {
                                    'success' => 'badge-success',
                                    'warning' => 'badge-warning',
                                    'danger'  => 'badge-danger',
                                    default   => 'badge-warning',
                                };
                            @endphp
                            <tr>
                                <td class="text-left font-bold">{{ $row['keterangan'] }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($row['nominal']) }}</td>
                                <td class="text-center">
                                    <span class="badge {{ $badgeClass }}">
                                        {{ number_format($row['persentase'], 2, ',', '.') }}%
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </td>

            {{-- INCOME PER BOOTH TABLE --}}
            <td class="col-right">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th colspan="9" class="header-green">INCOME {{ strtoupper($period['month_name']) }}</th>
                        </tr>
                        <tr class="sub-header">
                            <th class="text-left">Booth</th>
                            <th class="text-right">Income</th>
                            <th class="text-right">HPP</th>
                            <th class="text-center">% HPP</th>
                            <th class="text-right">Nett Profit</th>
                            <th class="text-center">% Profit</th>
                            <th class="text-center">Lembar</th>
                            <th class="text-center">Sesi</th>
                            <th class="text-right">Laba Ditahan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($boothTable['rows'] as $bName => $bData)
                            <tr>
                                <td class="text-left font-bold">{{ $bName }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($bData['income']) }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($bData['hpp']) }}</td>
                                <td class="text-center">{{ number_format($bData['hpp_percentage'], 2, ',', '.') }}%</td>
                                <td class="text-right font-mono font-bold">{{ RupiahHelper::format($bData['nett_profit']) }}</td>
                                <td class="text-center">{{ number_format($bData['nett_profit_percentage'], 2, ',', '.') }}%</td>
                                <td class="text-center font-mono">{{ number_format($bData['total_lembar'], 0, ',', '.') }}</td>
                                <td class="text-center font-mono">{{ number_format($bData['total_sesi'], 0, ',', '.') }}</td>
                                <td class="text-right font-mono font-bold">{{ RupiahHelper::format($bData['laba_ditahan']) }}</td>
                            </tr>
                        @endforeach
                        {{-- TOTAL ROW --}}
                        <tr class="total-row">
                            <td class="text-left">TOTAL</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($boothTable['total']['income']) }}</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($boothTable['total']['hpp']) }}</td>
                            <td class="text-center">{{ number_format($boothTable['total']['hpp_percentage'], 2, ',', '.') }}%</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($boothTable['total']['nett_profit']) }}</td>
                            <td class="text-center">{{ number_format($boothTable['total']['nett_profit_percentage'], 2, ',', '.') }}%</td>
                            <td class="text-center font-mono">{{ number_format($boothTable['total']['total_lembar'], 0, ',', '.') }}</td>
                            <td class="text-center font-mono">{{ number_format($boothTable['total']['total_sesi'], 0, ',', '.') }}</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($boothTable['total']['laba_ditahan']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    {{-- Bottom Section: Utang & Expenses Tables --}}
    <table class="row-table">
        <tr>
            {{-- UTANG TABLE --}}
            <td class="col-left">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th colspan="4" class="header-salmon">UTANG</th>
                        </tr>
                        <tr class="sub-header">
                            <th class="text-left">Keterangan</th>
                            <th class="text-center">Sisa Wkt</th>
                            <th class="text-right">Total Utang</th>
                            <th class="text-right">Perbulan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($utangTable['rows'] as $utang)
                            <tr>
                                <td class="text-left font-bold">{{ $utang['keterangan'] }}</td>
                                <td class="text-center font-mono">{{ $utang['sisa_waktu'] }} bln</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($utang['total_utang']) }}</td>
                                <td class="text-right font-mono font-bold">{{ RupiahHelper::format($utang['perbulan']) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center" style="color: #94a3b8; font-style: italic; padding: 6px;">
                                    Tidak ada catatan kewajiban utang aktif
                                </td>
                            </tr>
                        @endforelse
                        <tr class="total-row">
                            <td colspan="3" class="text-left">Bayar per bulan</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($utangTable['total_perbulan']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </td>

            {{-- EXPENSES PER CABANG TABLE --}}
            <td class="col-right">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th colspan="5" class="header-yellow">EXPENSES {{ strtoupper($period['month_name']) }}</th>
                        </tr>
                        <tr class="sub-header">
                            <th class="text-left">Kategori</th>
                            <th class="text-right">Janus</th>
                            <th class="text-right">Golio</th>
                            <th class="text-right">Express</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($expenseMatrix['categories'] as $cat)
                            @php
                                $rMat = $expenseMatrix['matrix'][$cat] ?? ['Janus' => 0, 'Golio' => 0, 'Express' => 0, 'total' => 0];
                            @endphp
                            <tr>
                                <td class="text-left font-bold">{{ $cat }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($rMat['Janus'] ?? 0) }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($rMat['Golio'] ?? 0) }}</td>
                                <td class="text-right font-mono">{{ RupiahHelper::format($rMat['Express'] ?? 0) }}</td>
                                <td class="text-right font-mono font-bold" style="background-color: #fefce8;">
                                    {{ RupiahHelper::format($rMat['total'] ?? 0) }}
                                </td>
                            </tr>
                        @endforeach
                        <tr class="total-row">
                            <td class="text-left">TOTAL</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($expenseMatrix['column_totals']['Janus'] ?? 0) }}</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($expenseMatrix['column_totals']['Golio'] ?? 0) }}</td>
                            <td class="text-right font-mono">{{ RupiahHelper::format($expenseMatrix['column_totals']['Express'] ?? 0) }}</td>
                            <td class="text-right font-mono" style="background-color: #fef9c3;">
                                {{ RupiahHelper::format($expenseMatrix['grand_total']) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </table>

    {{-- DomPDF Canvas Scriptlet Footer --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans');
            $color = [0.42, 0.45, 0.50];
            $y = $pdf->get_height() - 22;

            $pdf->page_text(24, $y, {!! json_encode('Rekap Perbulan Photomate - ' . $periode) !!}, $font, 7.5, $color);

            $pageLabelWidth = $fontMetrics->getTextWidth('Halaman 10 dari 10', $font, 7.5);
            $pdf->page_text(($pdf->get_width() - $pageLabelWidth) / 2, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 7.5, $color);

            $printedLabel = {!! json_encode('Dicetak pada: ' . $tanggalCetak) !!};
            $printedLabelWidth = $fontMetrics->getTextWidth($printedLabel, $font, 7.5);
            $pdf->page_text($pdf->get_width() - 24 - $printedLabelWidth, $y, $printedLabel, $font, 7.5, $color);
        }
    </script>
</body>

</html>
