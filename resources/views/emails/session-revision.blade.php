@component('emails.components.layout', [
    'title' => 'Revisi Diperlukan pada Rekap Sesi - #' . $report->report_number,
    'preheader' => 'Supervisor meminta revisi untuk rekap sesi #' . $report->report_number . '. Silakan periksa catatan dan perbaiki data Anda.',
    'statusBadge' => [
        'text' => 'PERLU REVISI',
        'style' => 'background-color:#ffe4e6;color:#9f1239;border:1px solid #fecdd3;',
    ],
    'actionUrl' => $actionUrl,
    'actionText' => 'Perbaiki & Submit Ulang',
])

<div style="text-align:left;">
    <h2 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">
        Halo, Tim {{ $report->cabang->nama_cabang ?? 'Cabang' }}
    </h2>
    <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.6;">
        Rekap sesi foto yang Anda ajukan memerlukan beberapa perbaikan berdasarkan pemeriksaan Supervisor. Mohon untuk meninjau catatan di bawah ini, memperbarui data pada sistem, dan melakukan pengajuan ulang.
    </p>

    <!-- Revision Note Card (Highlight) -->
    <div style="background-color:#fff1f2;border:1px solid #fecdd3;border-left:4px solid #e11d48;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#be123c;padding-bottom:6px;">
                    ⚠️ Catatan / Alasan Revisi dari Supervisor:
                </td>
            </tr>
            <tr>
                <td style="font-size:14px;color:#881337;line-height:1.6;font-weight:500;">
                    "{{ $reason }}"
                </td>
            </tr>
            @if($supervisorName)
            <tr>
                <td style="font-size:12px;color:#9f1239;padding-top:8px;">
                    &mdash; Diberikan oleh: <strong>{{ $supervisorName }}</strong>
                </td>
            </tr>
            @endif
        </table>
    </div>

    <!-- Session Info Card -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:24px;overflow:hidden;">
        <tr>
            <td colspan="2" style="background-color:#f1f5f9;padding:12px 16px;border-bottom:1px solid #e2e8f0;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;">
                    Informasi Rekap yang Perlu Diperbaiki
                </span>
            </td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;width:35%;border-bottom:1px solid #f1f5f9;">Nomor Rekap</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:600;border-bottom:1px solid #f1f5f9;">#{{ $report->report_number }}</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Tanggal Sesi</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">{{ $report->report_date ? $report->report_date->translatedFormat('l, d F Y') : '-' }}</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Cabang</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:600;border-bottom:1px solid #f1f5f9;">{{ $report->cabang->nama_cabang ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Total Transaksi Saat Ini</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">{{ number_format($report->total_transactions, 0, ',', '.') }} transaksi</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;">Total Omzet Saat Ini</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:600;">Rp {{ number_format($report->grand_total_amount, 0, ',', '.') }}</td>
        </tr>
    </table>

    <p style="margin:0 0 16px;font-size:13px;color:#64748b;line-height:1.5;">
        Silakan klik tombol di bawah ini untuk membuka halaman pengeditan rekap sesi, perbarui data yang diperlukan, lalu klik <strong>"Submit Ulang"</strong>.
    </p>
</div>

@endcomponent
