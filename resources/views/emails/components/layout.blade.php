<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $title ?? 'Photomate HRIS' }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;">
    <!-- Preheader (hidden text for email preview) -->
    @isset($preheader)
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">
        {{ $preheader }}
    </div>
    @endisset

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:24px 0;">
        <tr>
            <td align="center">
                <!-- Main Container -->
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.06);">

                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg,#1e293b 0%,#334155 50%,#1e293b 100%);padding:32px 40px;text-align:center;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <!-- Logo Circle -->
                                        <div style="display:inline-block;width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#6366f1,#8b5cf6);text-align:center;line-height:48px;margin-bottom:12px;">
                                            <span style="color:#ffffff;font-size:22px;font-weight:700;">P</span>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-top:4px;">
                                        <h1 style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.02em;">Photomate HRIS</h1>
                                        <p style="margin:4px 0 0;font-size:13px;color:#94a3b8;font-weight:400;">Human Resource Information System</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Status Badge -->
                    @isset($statusBadge)
                    <tr>
                        <td style="padding:24px 40px 0;text-align:center;">
                            <div style="display:inline-block;padding:8px 20px;border-radius:100px;font-size:13px;font-weight:600;letter-spacing:0.05em;{{ $statusBadge['style'] ?? '' }}">
                                {{ $statusBadge['text'] ?? '' }}
                            </div>
                        </td>
                    </tr>
                    @endisset

                    <!-- Body Content -->
                    <tr>
                        <td style="padding:28px 40px 12px;">
                            {{ $slot }}
                        </td>
                    </tr>

                    <!-- CTA Button -->
                    @isset($actionUrl)
                    <tr>
                        <td style="padding:8px 40px 32px;text-align:center;">
                            <a href="{{ $actionUrl }}" target="_blank" style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;border-radius:8px;letter-spacing:0.02em;box-shadow:0 4px 12px rgba(99,102,241,0.35);">
                                {{ $actionText ?? 'Buka Dashboard' }}
                            </a>
                        </td>
                    </tr>
                    @endisset

                    <!-- Divider -->
                    <tr>
                        <td style="padding:0 40px;">
                            <div style="height:1px;background-color:#e2e8f0;"></div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:24px 40px 32px;text-align:center;">
                            <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.6;">
                                Email ini dikirim secara otomatis oleh sistem <strong>Photomate HRIS</strong>.<br>
                                Mohon tidak membalas email ini.
                            </p>
                            <p style="margin:12px 0 0;font-size:11px;color:#cbd5e1;">
                                &copy; {{ date('Y') }} Photomate. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
