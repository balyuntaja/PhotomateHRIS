<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>{{ $judulDokumen }} - {{ $periode }}</title>
    <style>
        @page {
            margin: 140px 40px 90px 40px;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 12px;
            color: #333;
        }

        .header,
        .footer {
            width: 100%;
            position: fixed;
            left: 0;
            right: 0;
        }

        .footer {
            bottom: -30px;
            height: 40px;
            font-size: 14px;
            text-align: right;
            padding-top: 5px;
        }

        .footer .page-number:before {
            content: "Halaman " counter(page);
        }

        .logo {
            max-width: 240px;
            max-height: 120px;
        }

        .company-info {
            text-align: right;
            font-size: 12px;
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

        .content {
            margin-top: 200px;
        }

        h2 {
            font-size: 16px;
            margin-bottom: 6px;
        }

        h3 {
            font-size: 14px;
            margin: 20px 0 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px 10px;
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

        .meta-info {
            margin-bottom: 16px;
            font-size: 12px;
            color: #555;
        }

        .note {
            font-size: 11px;
            color: #777;
            margin-top: 6px;
        }

        .group-row th {
            background-color: #eaeaea;
        }
    </style>
</head>

<body>
    @include('pdf.partials.laporan-header', [
        'judulDokumen' => $judulDokumen,
        'periode' => $periode,
    ])

    <div class="content">
        <div class="meta-info">
            <strong>Periode:</strong> {{ $period['label'] }}<br>
            <strong>Cabang:</strong> {{ $cabangName }}
        </div>

        <h2>Ringkasan</h2>
        <table>
            <tbody>
                <tr>
                    <th>Total Pemasukan</th>
                    <td class="text-right">Rp {{ number_format($summary['income'], 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <th>Total Pengeluaran</th>
                    <td class="text-right">Rp {{ number_format($summary['expense'], 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <th>Saldo</th>
                    <td class="text-right">Rp {{ number_format($summary['balance'], 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        @foreach (['income' => 'Rincian Pemasukan', 'expense' => 'Rincian Pengeluaran'] as $type => $sectionTitle)
            <h3>{{ $sectionTitle }}</h3>
            <table>
                <thead>
                    <tr>
                        <th>Kategori</th>
                        <th class="text-right" style="width: 25%;">Nominal</th>
                        <th class="text-center" style="width: 15%;">Kontribusi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($categoryBreakdown[$type]['groups'] as $group)
                        @if ($group['label'])
                            <tr class="group-row">
                                <th colspan="3">{{ $group['label'] }}</th>
                            </tr>
                        @endif

                        @foreach ($group['items'] as $item)
                            <tr>
                                <td>{{ $item['name'] }}</td>
                                <td class="text-right">Rp {{ number_format($item['total'], 0, ',', '.') }}</td>
                                <td class="text-center">{{ number_format($item['percentage'], 1, ',', '.') }}%</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="3" class="text-center">Belum ada data pada periode ini.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th>Total</th>
                        <th class="text-right">Rp {{ number_format($categoryBreakdown[$type]['total'], 0, ',', '.') }}</th>
                        <th class="text-center">100%</th>
                    </tr>
                </tfoot>
            </table>
        @endforeach

        <h3>Selisih Kas per Cabang</h3>
        <table>
            <thead>
                <tr>
                    <th>Cabang</th>
                    <th class="text-right" style="width: 22%;">Pemasukan</th>
                    <th class="text-right" style="width: 22%;">Pengeluaran</th>
                    <th class="text-right" style="width: 22%;">Selisih Kas</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($branchBreakdown as $branch)
                    <tr>
                        <td>{{ $branch['branch'] }}</td>
                        <td class="text-right">Rp {{ number_format($branch['income'], 0, ',', '.') }}</td>
                        <td class="text-right">Rp {{ number_format($branch['expense'], 0, ',', '.') }}</td>
                        <td class="text-right">Rp {{ number_format($branch['net'], 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center">Belum ada data cabang pada periode ini.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="note">
            Catatan: Laporan ini dikompilasi otomatis dari modul Keuangan Photomate. Selisih kas bukan merupakan laba akuntansi.
            Transaksi yang dihapus tidak dihitung dalam laporan ini.
        </div>
    </div>

    @include('pdf.partials.laporan-footer', [
        'tanggalCetak' => $tanggalCetak,
    ])
</body>

</html>
