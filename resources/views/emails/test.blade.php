@component('emails.components.layout', [
    'title' => 'Tes Integrasi Email - Photomate HRIS',
    'preheader' => 'Email pengujian untuk memverifikasi integrasi Resend pada Photomate HRIS.',
    'statusBadge' => [
        'text' => 'TES KONFIGURASI',
        'style' => 'background-color:#e0e7ff;color:#3730a3;border:1px solid #c7d2fe;',
    ],
    'actionUrl' => url('/admin'),
    'actionText' => 'Buka Dashboard',
])

<div style="text-align:left;">
    <h2 style="margin:0 0 8px;font-size:18px;font-weight:700;color:#0f172a;">
        Halo!
    </h2>
    <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.6;">
        Ini adalah email pengujian untuk memverifikasi bahwa integrasi <strong>Resend</strong> pada Photomate HRIS telah dikonfigurasi dengan benar.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:24px;overflow:hidden;">
        <tr>
            <td colspan="2" style="background-color:#f1f5f9;padding:12px 16px;border-bottom:1px solid #e2e8f0;">
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569;">
                    Status Pengujian
                </span>
            </td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;width:35%;border-bottom:1px solid #f1f5f9;">Mailer Aktif</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:600;border-bottom:1px solid #f1f5f9;">{{ config('mail.default') }}</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Queue Connection</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:600;border-bottom:1px solid #f1f5f9;">{{ config('queue.default') }}</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;border-bottom:1px solid #f1f5f9;">Waktu Pengujian</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;border-bottom:1px solid #f1f5f9;">{{ now()->translatedFormat('d F Y, H:i') }} WIB</td>
        </tr>
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;">Lingkungan</td>
            <td style="padding:10px 16px;font-size:13px;color:#0f172a;font-weight:600;">{{ config('app.env') }}</td>
        </tr>
    </table>

    @if($note)
    <div style="background-color:#f8fafc;border-left:4px solid #6366f1;padding:12px 16px;border-radius:0 8px 8px 0;margin-bottom:24px;">
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#4338ca;display:block;margin-bottom:4px;">Catatan:</span>
        <p style="margin:0;font-size:13px;color:#334155;font-style:italic;">"{{ $note }}"</p>
    </div>
    @endif

    <p style="margin:0;font-size:13px;color:#64748b;line-height:1.5;">
        Jika Anda menerima email ini, berarti konfigurasi Resend, template email, dan pengiriman melalui queue telah berjalan dengan baik.
    </p>
</div>

@endcomponent
