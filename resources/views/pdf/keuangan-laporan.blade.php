<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>{{ $judulDokumen }} - {{ $periode }}</title>
    <style>
        @page {
            margin: 25px 30px 45px 30px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
            margin: 0;
            padding: 0;
        }

        .header {
            width: 100%;
            margin-bottom: 5px;
        }

        .logo {
            max-width: 160px;
            max-height: 70px;
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

        .table-no-border td,
        .table-no-border th {
            border: none;
            padding: 0;
        }

        h2 {
            font-size: 13px;
            margin: 10px 0 6px;
            page-break-after: avoid;
            break-after: avoid;
        }

        h3 {
            font-size: 12px;
            margin: 12px 0 6px;
            page-break-after: avoid;
            break-after: avoid;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 5px 6px;
        }

        th {
            background-color: #f4f4f4;
            text-align: left;
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

        .meta-info {
            margin-top: 6px;
            margin-bottom: 4px;
            font-size: 10px;
            color: #555;
            line-height: 1.4;
        }

        .summary-table {
            width: 100%;
            page-break-inside: avoid;
            break-inside: avoid;
            margin-bottom: 8px;
        }

        .summary-table th {
            width: 70%;
        }

        .transactions-table {
            width: 100%;
            table-layout: fixed;
            font-size: 9px;
            margin-bottom: 0;
            border-collapse: collapse;
        }

        .transactions-table thead {
            display: table-header-group;
        }

        .transactions-table tbody {
            display: table-row-group;
        }

        .transactions-table tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .transactions-table th,
        .transactions-table td {
            padding: 4px 5px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .transactions-table thead th {
            background-color: #eef2f7;
        }

        .totals-table {
            width: 50%;
            margin-left: 50%;
            margin-top: 10px;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .totals-table tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .totals-table th {
            text-align: left;
        }

        .totals-table .balance th,
        .totals-table .balance td {
            background-color: #f4f4f4;
            font-weight: bold;
        }
    </style>
</head>

<body>
    @php
        $transactions = $transactions ?? collect();
        $accountName = $accountName ?? 'Semua Akun';
        $rowNumber = 0;
    @endphp

    @include('pdf.partials.laporan-header', [
        'judulDokumen' => $judulDokumen,
        'periode' => $periode,
        'accountName' => $accountName,
    ])

    <div class="meta-info">
        <strong>Periode:</strong> {{ $period['label'] }}<br>
        <strong>Akun:</strong> {{ $accountName }}
    </div>

    <h2>Ringkasan</h2>
    <table class="summary-table">
        <thead>
            <tr>
                <th>Keterangan</th>
                <th class="text-right">Nominal</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Total Pemasukan</td>
                <td class="text-right">{{ \App\Utils\RupiahHelper::format($summary['income']) }}</td>
            </tr>
            <tr>
                <td>Total Pengeluaran</td>
                <td class="text-right">{{ \App\Utils\RupiahHelper::format($summary['expense']) }}</td>
            </tr>
            <tr>
                <td>Saldo</td>
                <td class="text-right">{{ \App\Utils\RupiahHelper::format($summary['balance']) }}</td>
            </tr>
        </tbody>
    </table>

    <h3>Rincian Transaksi</h3>

    <table class="transactions-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 4%;">No</th>
                <th style="width: 9%;">Tanggal</th>
                <th style="width: 8%;">Jenis</th>
                <th style="width: 12%;">Akun</th>
                <th style="width: 12%;">Kategori</th>
                <th style="width: 18%;">Deskripsi</th>
                <th style="width: 10%;">Metode</th>
                <th class="text-right" style="width: 12%;">Nominal</th>
                <th style="width: 15%;">Dibayarkan / Disubmit Oleh</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($transactions as $transaction)
                @php($rowNumber++)
                <tr>
                    <td class="text-center">{{ $rowNumber }}</td>
                    <td>{{ $transaction->transaction_date?->format('d M Y') ?? '-' }}</td>
                    <td>{{ $transaction->type_label }}</td>
                    <td>{{ $transaction->account?->name ?? 'Tanpa Akun' }}</td>
                    <td>{{ $transaction->category_label }}</td>
                    <td>{{ filled($transaction->description) ? \Illuminate\Support\Str::limit($transaction->description, 70) : '-' }}</td>
                    <td>{{ $transaction->payment_method_label }}</td>
                    <td class="text-right {{ $transaction->is_income ? 'text-income' : 'text-expense' }}">
                        {{ $transaction->signed_formatted_amount }}
                    </td>
                    <td>{{ $transaction->recording_info_name }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center">Belum ada transaksi pada periode ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals-table">
        <tbody>
            <tr>
                <th>Total Pemasukan</th>
                <td class="text-right text-income">{{ \App\Utils\RupiahHelper::format($summary['income']) }}</td>
            </tr>
            <tr>
                <th>Total Pengeluaran</th>
                <td class="text-right text-expense">{{ \App\Utils\RupiahHelper::format($summary['expense']) }}</td>
            </tr>
            <tr class="balance">
                <th>Saldo</th>
                <td class="text-right">{{ \App\Utils\RupiahHelper::format($summary['balance']) }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Footer setiap halaman: identitas laporan, nomor halaman, tanggal cetak --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans');
            $color = [0.42, 0.45, 0.50];
            $y = $pdf->get_height() - 30;

            $pdf->page_text(30, $y, {!! json_encode('Laporan Keuangan Photomate - ' . $periode) !!}, $font, 8, $color);

            $pageLabelWidth = $fontMetrics->getTextWidth('Halaman 10 dari 10', $font, 8);
            $pdf->page_text(($pdf->get_width() - $pageLabelWidth) / 2, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 8, $color);

            $printedLabel = {!! json_encode('Dicetak pada: ' . $tanggalCetak) !!};
            $printedLabelWidth = $fontMetrics->getTextWidth($printedLabel, $font, 8);
            $pdf->page_text($pdf->get_width() - 30 - $printedLabelWidth, $y, $printedLabel, $font, 8, $color);
        }
    </script>
</body>

</html>
