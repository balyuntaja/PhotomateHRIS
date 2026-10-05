<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>{{ $judulDokumen }} - {{ $periode }}</title>
    <style>
        @page {
            margin: 40px 36px 80px 36px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #333;
        }

        /* Header dirender di dalam setiap blok halaman (bukan fixed),
           agar tidak bertabrakan dengan konten dan tetap muncul tiap halaman. */
        .header {
            position: static;
            width: 100%;
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
        }

        .page-block {
            page-break-after: always;
        }

        h2 {
            font-size: 14px;
            margin: 12px 0 8px;
        }

        h3 {
            font-size: 13px;
            margin: 12px 0 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
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
            margin-top: 12px;
            margin-bottom: 4px;
            font-size: 11px;
            color: #555;
        }

        .summary-table th {
            width: 70%;
        }

        .transactions-table {
            font-size: 9px;
            margin-bottom: 0;
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
            width: 60%;
            margin-left: 40%;
            margin-top: 12px;
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
        $firstPageRows = 12;
        $nextPageRows = 16;
        $rowNumber = 0;

        $pages = collect();
        if ($transactions->isNotEmpty()) {
            $pages->push($transactions->take($firstPageRows)->values());
            $pages = $pages->merge(
                $transactions->slice($firstPageRows)->values()->chunk($nextPageRows)->values()
            );
        }
    @endphp

    @forelse ($pages as $pageTransactions)
        <div class="{{ $loop->last ? '' : 'page-block' }}">
            @include('pdf.partials.laporan-header', [
                'judulDokumen' => $judulDokumen,
                'periode' => $periode,
                'cabangName' => $cabangName,
            ])

            @if ($loop->first)
                <div class="meta-info">
                    <strong>Periode:</strong> {{ $period['label'] }}<br>
                    <strong>Cabang:</strong> {{ $cabangName }}
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
                            <td class="text-right">Rp {{ number_format($summary['income'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Total Pengeluaran</td>
                            <td class="text-right">Rp {{ number_format($summary['expense'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td>Saldo</td>
                            <td class="text-right">Rp {{ number_format($summary['balance'], 0, ',', '.') }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            <h3>Rincian Transaksi{{ $loop->first ? '' : ' (lanjutan)' }}</h3>

            <table class="transactions-table">
                <thead>
                    <tr>
                        <th class="text-center" style="width: 4%;">No</th>
                        <th style="width: 10%;">Tanggal</th>
                        <th style="width: 9%;">Jenis</th>
                        <th style="width: 13%;">Kategori</th>
                        <th style="width: 25%;">Deskripsi</th>
                        <th style="width: 12%;">Cabang</th>
                        <th style="width: 11%;">Metode</th>
                        <th class="text-right" style="width: 16%;">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($pageTransactions as $transaction)
                        @php($rowNumber++)
                        <tr>
                            <td class="text-center">{{ $rowNumber }}</td>
                            <td>{{ $transaction->transaction_date?->format('d M Y') ?? '-' }}</td>
                            <td>{{ $transaction->type_label }}</td>
                            <td>{{ $transaction->category?->name ?? '-' }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($transaction->description, 70) ?: '-' }}</td>
                            <td>{{ $transaction->cabang?->nama_cabang ?? 'Tanpa Cabang' }}</td>
                            <td>{{ $transaction->paymentMethod?->name ?? '-' }}</td>
                            <td class="text-right {{ $transaction->is_income ? 'text-income' : 'text-expense' }}">
                                {{ $transaction->signed_formatted_amount }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($loop->last)
                <table class="totals-table">
                    <tbody>
                        <tr>
                            <th>Total Pemasukan</th>
                            <td class="text-right text-income">Rp {{ number_format($summary['income'], 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <th>Total Pengeluaran</th>
                            <td class="text-right text-expense">Rp {{ number_format($summary['expense'], 0, ',', '.') }}</td>
                        </tr>
                        <tr class="balance">
                            <th>Saldo</th>
                            <td class="text-right">Rp {{ number_format($summary['balance'], 0, ',', '.') }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </div>
    @empty
        @include('pdf.partials.laporan-header', [
            'judulDokumen' => $judulDokumen,
            'periode' => $periode,
            'cabangName' => $cabangName,
        ])

        <div class="meta-info">
            <strong>Periode:</strong> {{ $period['label'] }}<br>
            <strong>Cabang:</strong> {{ $cabangName }}
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
                    <td class="text-right">Rp {{ number_format($summary['income'], 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Total Pengeluaran</td>
                    <td class="text-right">Rp {{ number_format($summary['expense'], 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td>Saldo</td>
                    <td class="text-right">Rp {{ number_format($summary['balance'], 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        <h3>Rincian Transaksi</h3>
        <table class="transactions-table">
            <thead>
                <tr>
                    <th class="text-center" style="width: 4%;">No</th>
                    <th style="width: 10%;">Tanggal</th>
                    <th style="width: 9%;">Jenis</th>
                    <th style="width: 13%;">Kategori</th>
                    <th style="width: 25%;">Deskripsi</th>
                    <th style="width: 12%;">Cabang</th>
                    <th style="width: 11%;">Metode</th>
                    <th class="text-right" style="width: 16%;">Nominal</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td colspan="8" class="text-center">Belum ada transaksi pada periode ini.</td>
                </tr>
            </tbody>
        </table>

        <table class="totals-table">
            <tbody>
                <tr>
                    <th>Total Pemasukan</th>
                    <td class="text-right text-income">Rp {{ number_format($summary['income'], 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <th>Total Pengeluaran</th>
                    <td class="text-right text-expense">Rp {{ number_format($summary['expense'], 0, ',', '.') }}</td>
                </tr>
                <tr class="balance">
                    <th>Saldo</th>
                    <td class="text-right">Rp {{ number_format($summary['balance'], 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    @endforelse

    {{-- Footer setiap halaman: identitas laporan, nomor halaman, tanggal cetak --}}
    <script type="text/php">
        if (isset($pdf)) {
            $font = $fontMetrics->getFont('DejaVu Sans');
            $color = [0.42, 0.45, 0.50];
            $y = 800;

            $pdf->page_text(36, $y, {!! json_encode('Laporan Keuangan Photomate - ' . $periode) !!}, $font, 8, $color);

            $pageLabelWidth = $fontMetrics->getTextWidth('Halaman 10 dari 10', $font, 8);
            $pdf->page_text(($pdf->get_width() - $pageLabelWidth) / 2, $y, 'Halaman {PAGE_NUM} dari {PAGE_COUNT}', $font, 8, $color);

            $printedLabel = {!! json_encode('Dicetak pada: ' . $tanggalCetak) !!};
            $printedLabelWidth = $fontMetrics->getTextWidth($printedLabel, $font, 8);
            $pdf->page_text($pdf->get_width() - 36 - $printedLabelWidth, $y, $printedLabel, $font, 8, $color);
        }
    </script>
</body>

</html>
