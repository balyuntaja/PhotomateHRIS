@component('emails.components.layout', [
    'title' => 'Rekap Sesi Baru Diajukan - #' . $report->report_number,
    'preheader' => 'Rekap sesi #' . $report->report_number . ' dari ' . ($report->cabang->nama_cabang ?? 'Cabang') . ' telah diajukan dan menunggu persetujuan Anda.',
    'statusBadge' => [
        'text' => 'MENUNGGU PERSETUJUAN',
        'style' => 'background-color:#fef3c7;color:#92400e;border:1px solid #fde68a;',
    ],
    'actionUrl' => $actionUrl,
    'actionText' => 'Periksa di Dashboard',
])

<div style="text-align:left;">
    <h2 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">
        Halo, Supervisor & Tim Manajemen
    </h2>
    <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.6;">
        Rekap sesi baru telah diajukan oleh tim di lapangan. Mohon untuk memeriksa dan memvalidasi rincian transaksi berikut sebelum memberikan persetujuan.
    </p>

    <!-- Info Card -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:24px;overflow:hidden;">
        <tr>
            <td colspan="2" style="background-color:#f1f5f9;padding:12px 16px;border-bottom:1px solid #e2e8f0;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;">
                    Detail Rekap Sesi
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
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Diajukan Oleh</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">{{ $report->submitter->nama_lengkap ?? '-' }}</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Waktu Pengajuan</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">{{ $report->submitted_at ? $report->submitted_at->translatedFormat('d F Y, H:i') : '-' }} WIB</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;vertical-align:top;">Crew Bertugas</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;">
                @if($report->crews->isNotEmpty())
                    <ul style="margin:0;padding-left:18px;">
                        @foreach($report->crews as $crew)
                            <li style="margin-bottom:2px;">{{ $crew->nama_lengkap }}</li>
                        @endforeach
                    </ul>
                @else
                    <span style="color:#94a3b8;">-</span>
                @endif
            </td>
        </tr>
    </table>

    <!-- Financial Summary Cards -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
        <tr>
            <td width="48%" style="vertical-align:top;background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:8px;padding:16px;text-align:center;">
                <p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#1e40af;">
                    Total Transaksi
                </p>
                <p style="margin:8px 0 0;font-size:24px;font-weight:800;color:#1e3a8a;">
                    {{ number_format($report->total_transactions, 0, ',', '.') }}
                </p>
                <p style="margin:4px 0 0;font-size:11px;color:#3b82f6;">
                    {{ number_format($report->total_sessions, 0, ',', '.') }} lembar cetak
                </p>
            </td>
            <td width="4%"></td>
            <td width="48%" style="vertical-align:top;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1px solid #a7f3d0;border-radius:8px;padding:16px;text-align:center;">
                <p style="margin:0;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#065f46;">
                    Total Omzet
                </p>
                <p style="margin:8px 0 0;font-size:24px;font-weight:800;color:#064e3b;">
                    Rp {{ number_format($report->grand_total_amount, 0, ',', '.') }}
                </p>
                <p style="margin:4px 0 0;font-size:11px;color:#10b981;">
                    Tunai: Rp {{ number_format($report->total_cash_amount, 0, ',', '.') }} | QRIS: Rp {{ number_format($report->total_qris_amount, 0, ',', '.') }}
                </p>
            </td>
        </tr>
    </table>

    @if($report->event_note)
    <div style="background-color:#f8fafc;border-left:4px solid #6366f1;padding:12px 16px;border-radius:0 8px 8px 0;margin-bottom:24px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#4338ca;display:block;margin-bottom:4px;">Catatan Tambahan:</span>
        <p style="margin:0;font-size:13px;color:#334155;font-style:italic;">"{{ $report->event_note }}"</p>
    </div>
    @endif
</div>

@endcomponent
