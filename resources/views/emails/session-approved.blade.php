@component('emails.components.layout', [
    'title' => 'Rekap Sesi Disetujui - #' . $report->report_number,
    'preheader' => 'Kabar baik! Rekap sesi #' . $report->report_number . ' telah disetujui oleh ' . ($report->approver->nama_lengkap ?? 'Supervisor') . '.',
    'statusBadge' => [
        'text' => 'DISETUJUI (APPROVED)',
        'style' => 'background-color:#d1fae5;color:#065f46;border:1px solid #a7f3d0;',
    ],
    'actionUrl' => $actionUrl,
    'actionText' => 'Lihat Detail',
])

<div style="text-align:left;">
    <h2 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">
        Halo, Tim {{ $report->cabang->nama_cabang ?? 'Cabang' }}
    </h2>
    <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.6;">
        Rekap sesi foto yang diajukan telah diverifikasi dan <strong>disetujui</strong> oleh Supervisor. Data telah dikunci dan tercatat secara permanen di sistem.
    </p>

    <!-- Approval Info Card -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin-bottom:24px;padding:16px;">
        <tr>
            <td>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td style="font-size:13px;color:#166534;font-weight:600;padding-bottom:4px;">Disetujui Oleh:</td>
                        <td style="font-size:13px;color:#15803d;font-weight:700;text-align:right;padding-bottom:4px;">
                            {{ $report->approver->nama_lengkap ?? 'Supervisor' }}
                        </td>
                    </tr>
                    <tr>
                        <td style="font-size:13px;color:#166534;">Waktu Approval:</td>
                        <td style="font-size:13px;color:#15803d;text-align:right;">
                            {{ $report->approved_at ? $report->approved_at->translatedFormat('d F Y, H:i') : '-' }} WIB
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Detail Rekap Sesi -->
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:24px;overflow:hidden;">
        <tr>
            <td colspan="2" style="background-color:#f1f5f9;padding:12px 16px;border-bottom:1px solid #e2e8f0;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;">
                    Ringkasan Rekap Sesi
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
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Total Transaksi</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">{{ number_format($report->total_transactions, 0, ',', '.') }} transaksi ({{ number_format($report->total_sessions, 0, ',', '.') }} lembar)</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;">Total Omzet</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:700;">Rp {{ number_format($report->grand_total_amount, 0, ',', '.') }}</td>
        </tr>
    </table>

    <!-- Bonus Breakdown (if available) -->
    @if(!empty($bonusDetails) && ($bonusDetails['is_eligible_branch'] ?? false))
    <div style="background-color:#ffffff;border:1px solid #e0e7ff;border-radius:8px;margin-bottom:24px;overflow:hidden;">
        <div style="background:linear-gradient(135deg,#e0e7ff,#ede9fe);padding:12px 16px;border-bottom:1px solid #c7d2fe;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#4338ca;">
                            🎉 Rincian Bonus Crew
                        </span>
                    </td>
                    <td style="text-align:right;">
                        @if($bonusDetails['target_reached'] ?? false)
                            <span style="font-size:11px;font-weight:700;background-color:#10b981;color:#ffffff;padding:2px 8px;border-radius:4px;">
                                Target Tercapai
                            </span>
                        @else
                            <span style="font-size:11px;font-weight:600;background-color:#94a3b8;color:#ffffff;padding:2px 8px;border-radius:4px;">
                                Belum Capai Target
                            </span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:10px 16px;color:#64748b;border-bottom:1px solid #f1f5f9;">Total Sesi Cabang Hari Ini:</td>
                <td style="padding:10px 16px;color:#0f172a;font-weight:600;text-align:right;border-bottom:1px solid #f1f5f9;">
                    {{ $bonusDetails['day_total_sessions'] ?? 0 }} / {{ $bonusDetails['target_sessions'] ?? 30 }} sesi
                </td>
            </tr>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:10px 16px;color:#64748b;border-bottom:1px solid #f1f5f9;">Total Bonus Cabang:</td>
                <td style="padding:10px 16px;color:#4338ca;font-weight:700;text-align:right;border-bottom:1px solid #f1f5f9;">
                    Rp {{ number_format($bonusDetails['total_bonus'] ?? 0, 0, ',', '.') }}
                </td>
            </tr>

            @if(!empty($bonusDetails['crew_breakdown']))
            <tr>
                <td colspan="2" style="padding:8px 16px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;">
                    Pembagian Per Crew:
                </td>
            </tr>
            @foreach($bonusDetails['crew_breakdown'] as $crewBonus)
            <tr>
                <td style="padding:6px 16px 6px 24px;color:#334155;border-bottom:1px solid #f8fafc;">
                    &bull; {{ $crewBonus['nama_lengkap'] }}
                </td>
                <td style="padding:6px 16px;color:#059669;font-weight:700;text-align:right;border-bottom:1px solid #f8fafc;">
                    Rp {{ number_format($crewBonus['bonus'] ?? 0, 0, ',', '.') }}
                </td>
            </tr>
            @endforeach
            @endif
        </table>
    </div>
    @endif
</div>

@endcomponent
