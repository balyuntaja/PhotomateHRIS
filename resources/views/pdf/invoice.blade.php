<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 14px;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 20px;
        }
        .header {
            width: 100%;
            margin-bottom: 40px;
        }
        .header td {
            vertical-align: top;
        }
        .title {
            font-size: 28px;
            font-weight: bold;
            color: #1a202c;
            text-align: right;
            text-transform: uppercase;
        }
        .logo-text {
            font-size: 24px;
            font-weight: bold;
            color: #2b6cb0;
        }
        .info-table {
            width: 100%;
            margin-bottom: 40px;
        }
        .info-table td {
            width: 50%;
            vertical-align: top;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #4a5568;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 5px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .items-table th {
            background-color: #f7fafc;
            color: #4a5568;
            font-weight: bold;
            text-align: left;
            padding: 10px;
            border-bottom: 2px solid #cbd5e0;
        }
        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .items-table th.right, .items-table td.right {
            text-align: right;
        }
        .summary-table {
            width: 40%;
            float: right;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        .summary-table td {
            padding: 8px 0;
        }
        .summary-table td.label {
            font-weight: bold;
            color: #4a5568;
        }
        .summary-table td.amount {
            text-align: right;
        }
        .summary-table tr.total td {
            font-size: 18px;
            font-weight: bold;
            border-top: 2px solid #cbd5e0;
            padding-top: 10px;
            color: #1a202c;
        }
        .footer-info {
            clear: both;
            margin-top: 40px;
        }
        .payment-box, .notes-box {
            margin-bottom: 20px;
            background-color: #f7fafc;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #e2e8f0;
        }
        .payment-box h4, .notes-box h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #2d3748;
        }
        .notes-content {
            white-space: pre-wrap;
            font-size: 13px;
            color: #4a5568;
        }
    </style>
</head>
<body>

    <table class="header">
        <tr>
            <td>
                <!-- Logo can be an image later, using text for now to avoid broken images -->
                <div class="logo-text">PHOTOMATE</div>
                <div style="color: #718096; margin-top: 5px; font-size: 13px;">
                    Jasa Layanan Photobooth Profesional<br>
                    Jakarta, Indonesia
                </div>
            </td>
            <td class="title">
                INVOICE
                <div style="font-size: 14px; color: #718096; font-weight: normal; margin-top: 5px;">
                    #{{ $invoice->invoice_number }}
                </div>
                <div style="font-size: 14px; color: #{{ $invoice->status == 'Lunas' ? '38a169' : ($invoice->status == 'Dibatalkan' ? 'e53e3e' : 'dd6b20') }}; margin-top: 5px;">
                    {{ strtoupper($invoice->status) }}
                </div>
            </td>
        </tr>
    </table>

    <table class="info-table">
        <tr>
            <td style="padding-right: 20px;">
                <div class="section-title">Tagihan Kepada</div>
                <div style="font-weight: bold; font-size: 16px;">{{ $invoice->client_name }}</div>
                <div><strong>PIC:</strong> {{ $invoice->person_in_charge }}</div>
                <div style="margin-top: 10px;">
                    <strong>Acara:</strong> {{ $invoice->event_name }}<br>
                    <strong>Lokasi:</strong> {{ $invoice->event_location }}<br>
                    <strong>Tanggal Acara:</strong>
                    @if($invoice->event_date)
                        {{ $invoice->event_date->format('d F Y') }}
                        @if($invoice->event_end_date && $invoice->event_end_date->ne($invoice->event_date))
                            s/d {{ $invoice->event_end_date->format('d F Y') }}
                        @endif
                    @else
                        -
                    @endif
                </div>
            </td>
            <td>
                <div class="section-title">Detail Invoice</div>
                <table style="width: 100%;">
                    <tr>
                        <td style="padding: 4px 0; color: #718096; width: 40%;">Tanggal Invoice:</td>
                        <td style="padding: 4px 0; text-align: right;">{{ $invoice->invoice_date->format('d F Y') }}</td>
                    </tr>
                    <tr>
                        <td style="padding: 4px 0; color: #718096;">Jatuh Tempo:</td>
                        <td style="padding: 4px 0; text-align: right;">{{ $invoice->due_date->format('d F Y') }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th>Deskripsi Layanan</th>
                <th>Durasi</th>
                <th>Pukul</th>
                <th class="right">Jumlah (Rp)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->invoiceItems as $item)
            <tr>
                <td>{{ $item->service_description }}</td>
                <td>{{ $item->duration }}</td>
                <td>{{ $item->time_range }}</td>
                <td class="right">{{ number_format($item->amount, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td class="label">Subtotal:</td>
            <td class="amount">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
        </tr>
        @if($invoice->discount > 0)
        <tr>
            <td class="label">Diskon:</td>
            <td class="amount">- Rp {{ number_format($invoice->discount, 0, ',', '.') }}</td>
        </tr>
        @endif
        <tr class="total">
            <td class="label">Grand Total:</td>
            <td class="amount">Rp {{ number_format($invoice->total, 0, ',', '.') }}</td>
        </tr>
        @if($invoice->status == 'Sebagian Dibayar' && $invoice->down_payment > 0)
        <tr>
            <td class="label">Down Payment:</td>
            <td class="amount">Rp {{ number_format($invoice->down_payment, 0, ',', '.') }}</td>
        </tr>
        <tr style="border-top: 1px solid #cbd5e0;">
            <td class="label">Sisa Pembayaran:</td>
            <td class="amount" style="font-weight: bold; color: #dd6b20;">Rp {{ number_format($invoice->total - $invoice->down_payment, 0, ',', '.') }}</td>
        </tr>
        @endif
    </table>

    <div class="footer-info">
        @if($invoice->payment_bank || $invoice->payment_account_name || $invoice->payment_account_number)
        <div class="payment-box">
            <h4>METODE PEMBAYARAN</h4>
            <div>
                <strong>Bank:</strong> {{ $invoice->payment_bank }}<br>
                <strong>Atas Nama:</strong> {{ $invoice->payment_account_name }}<br>
                <strong>No Rekening:</strong> {{ $invoice->payment_account_number }}
            </div>
        </div>
        @endif

        @if($invoice->notes)
        <div class="notes-box">
            <h4>CATATAN</h4>
            <div class="notes-content">{{ $invoice->notes }}</div>
        </div>
        @endif
    </div>

</body>
</html>
