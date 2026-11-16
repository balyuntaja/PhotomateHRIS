<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>{{ $judulDokumen }} - {{ $periode }}</title>
    <style>
        @page {
            margin: 28px 32px 45px 32px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        .header {
            width: 100%;
            margin-bottom: 8px;
        }

        .logo {
            max-width: 160px;
            max-height: 65px;
        }

        .company-info {
            text-align: right;
            font-size: 11px;
            line-height: 1.4;
        }

        .w-50 {
            width: 50%;
            vertical-align: top;
        }

        .table-no-border {
            width: 100%;
            border-collapse: collapse;
        }

        .table-no-border td,
        .table-no-border th {
            border: none;
            padding: 0;
        }

        .title-bar {
            font-size: 15px;
            font-weight: bold;
            text-align: center;
            border-top: 1.5px solid #111827;
            border-bottom: 1.5px solid #111827;
            padding: 6px 0;
            margin: 8px 0 6px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .meta-info {
            margin-top: 4px;
            margin-bottom: 12px;
            font-size: 10px;
            color: #4b5563;
            line-height: 1.5;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 10px;
        }

        .meta-table td {
            border: none;
            padding: 2px 0;
            color: #4b5563;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            color: #111827;
            margin: 14px 0 6px 0;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            page-break-after: avoid;
            break-after: avoid;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .report-table th,
        .report-table td {
            border: 1px solid #d1d5db;
            padding: 6px 10px;
        }

        .report-table th {
            background-color: #f3f4f6;
            color: #111827;
            font-weight: bold;
            text-align: left;
            font-size: 10.5px;
        }

        .report-table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .report-table .row-total td {
            background-color: #e5e7eb;
            font-weight: bold;
            color: #111827;
            border-top: 1.5px solid #374151;
            font-size: 11px;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-income {
            color: #047857;
        }

        .text-expense {
            color: #b91c1c;
        }

        .laba-rugi-box {
            width: 100%;
            margin-top: 16px;
            border-collapse: collapse;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .laba-rugi-card {
            border: 1.5px solid #d1d5db;
            border-radius: 4px;
            padding: 12px 16px;
            margin-top: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .card-laba {
            background-color: #f0fdf4;
            border-color: #059669;
        }

        .card-rugi {
            background-color: #fef2f2;
            border-color: #dc2626;
        }

        .card-impas {
            background-color: #f3f4f6;
            border-color: #6b7280;
        }

        .badge-status {
            display: inline-block;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 3px;
        }

        .badge-laba {
            background-color: #dcfce7;
            color: #15803d;
        }

        .badge-rugi {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .badge-impas {
            background-color: #e5e7eb;
            color: #374151;
        }

        .nominal-highlight {
            font-size: 17px;
            font-weight: bold;
            margin-top: 4px;
        }

        .nominal-laba {
            color: #15803d;
        }

        .nominal-rugi {
            color: #b91c1c;
        }

        .nominal-impas {
            color: #374151;
        }
    </style>
</head>

<body>
    <!-- HEADER -->
    <header class="header">
        <table class="table-no-border">
            <tr>
                <td class="w-50" style="vertical-align: top;">
                    @php
                        $logoCandidates = [
                            resource_path('js/react/assets/img/logophotomateblue.png'),
                            public_path('build/assets/logophotomateblue.png'),
                            public_path('logophotomateblue.png'),
                        ];
                        $logoPath = collect($logoCandidates)->first(fn (string $path): bool => file_exists($path));
                        $logoSrc = $logoPath ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;
                    @endphp
                    @if($logoSrc)
                        <img src="{{ $logoSrc }}" alt="Photomate" class="logo">
                    @else
                        <span style="font-size: 18px; font-weight: bold; color: #1e3a8a;">Photomate</span>
                    @endif
                </td>
                <td class="w-50 company-info" style="vertical-align: top;">
                    <span style="font-size: 14px; font-weight: bold;">Photomate</span><br>
                    <span style="font-size: 10px; color: #6b7280;">photomate.id</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <div class="title-bar">
                        {{ $judulDokumen }}
                    </div>
                </td>
            </tr>
        </table>
    </header>

    <!-- META INFO -->
    <table class="meta-table">
        <tr>
            <td style="width: 15%; font-weight: bold;">Periode Laporan</td>
            <td style="width: 35%;">: {{ $period['label'] }}</td>
            <td style="width: 15%; font-weight: bold; text-align: right;">Filter Akun :</td>
            <td style="width: 35%; text-align: right;">{{ $accountName }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Tanggal Cetak</td>
            <td>: {{ $tanggalCetak }}</td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <!-- B. PENDAPATAN USAHA -->
    <div class="section-title">Pendapatan Usaha</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 70%;">Keterangan</th>
                <th class="text-right" style="width: 30%;">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($pendapatanRows as $cabang => $nominal)
                <tr>
                    <td>{{ $cabang }}</td>
                    <td class="text-right {{ $nominal > 0 ? 'text-income' : '' }}">
                        {{ \App\Utils\RupiahHelper::format($nominal) }}
                    </td>
                </tr>
            @endforeach

            @foreach ($pendapatanLain as $label => $nominal)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="text-right {{ $nominal > 0 ? 'text-income' : '' }}">
                        {{ \App\Utils\RupiahHelper::format($nominal) }}
                    </td>
                </tr>
            @endforeach

            <tr class="row-total">
                <td>Total Pendapatan</td>
                <td class="text-right text-income">
                    {{ \App\Utils\RupiahHelper::format($totalPendapatan) }}
                </td>
            </tr>
        </tbody>
    </table>

    <!-- C. BEBAN USAHA -->
    <div class="section-title">Beban Usaha</div>
    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 70%;">Keterangan</th>
                <th class="text-right" style="width: 30%;">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bebanRows as $kategori => $nominal)
                <tr>
                    <td>{{ $kategori }}</td>
                    <td class="text-right {{ $nominal > 0 ? 'text-expense' : '' }}">
                        {{ \App\Utils\RupiahHelper::format($nominal) }}
                    </td>
                </tr>
            @endforeach

            @foreach ($bebanLain as $label => $nominal)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="text-right {{ $nominal > 0 ? 'text-expense' : '' }}">
                        {{ \App\Utils\RupiahHelper::format($nominal) }}
                    </td>
                </tr>
            @endforeach

            <tr class="row-total">
                <td>Total Beban Usaha</td>
                <td class="text-right text-expense">
                    {{ \App\Utils\RupiahHelper::format($totalBebanUsaha) }}
                </td>
            </tr>
        </tbody>
    </table>

    <!-- D. LABA RUGI -->
    <div class="section-title">Hasil Usaha</div>
    <div class="laba-rugi-card card-{{ $statusType }}">
        <table class="table-no-border" style="width: 100%;">
            <tr>
                <td style="width: 60%; vertical-align: middle;">
                    <div style="font-size: 11px; color: #4b5563; margin-bottom: 2px;">
                        Laba / Rugi = Total Pendapatan ({{ \App\Utils\RupiahHelper::format($totalPendapatan) }}) - Total Beban Usaha ({{ \App\Utils\RupiahHelper::format($totalBebanUsaha) }})
                    </div>
                    <div style="margin-top: 4px;">
                        <span class="badge-status badge-{{ $statusType }}">
                            Status: {{ $statusLabel }}
                        </span>
                    </div>
                </td>
                <td style="width: 40%; text-align: right; vertical-align: middle;">
                    <div style="font-size: 11px; font-weight: bold; color: #4b5563; text-transform: uppercase;">
                        {{ $statusLabel }} Bersih
                    </div>
                    <div class="nominal-highlight nominal-{{ $statusType }}">
                        {{ $labaRugi < 0 ? '-' : '' }}{{ \App\Utils\RupiahHelper::format(abs($labaRugi)) }}
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- DomPDF Canvas Footer -->
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans');
            $color = [0.42, 0.45, 0.50];
            $y = $pdf->get_height() - 30;

            $pdf->page_text(32, $y, {!! json_encode('Laporan Laba Rugi Booth - ' . $periode) !!}, $font, 8, $color);

            $pageLabelWidth = $fontMetrics->getTextWidth('Halaman 10 dari 10', $font, 8);
            $pdf->page_text(($pdf->get_width() - $pageLabelWidth) / 2, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 8, $color);

            $printedLabel = {!! json_encode('Dicetak pada: ' . $tanggalCetak) !!};
            $printedLabelWidth = $fontMetrics->getTextWidth($printedLabel, $font, 8);
            $pdf->page_text($pdf->get_width() - 32 - $printedLabelWidth, $y, $printedLabel, $font, 8, $color);
        }
    </script>
</body>

</html>
