<!-- HEADER -->
<header class="header">
    <table class="table-no-border" style="width:100%;">
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
                    <img src="{{ $logoSrc }}" alt="Photomate" class="logo" style="max-width: 160px; max-height: 70px;">
                @else
                    <span style="font-size: 18px; font-weight: bold;">Photomate</span>
                @endif
            </td>
            <td class="w-50 company-info" style="vertical-align: top; padding-left: 10px;">
                <span style="font-size: 14px; font-weight: bold;">Photomate</span><br>
                <span style="font-size: 10px; color: #666;">photomate.id</span>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="padding-top: 8px;">
                <div style="font-size: 16px; font-weight:bold; text-align:center; border-top: 1px solid #222; border-bottom: 1px solid #222; padding: 5px 0; margin: 5px 0;">
                    {{ $judulDokumen }} - {{ $periode }}
                </div>
                @if (filled($cabangName ?? null))
                    <div style="font-size: 10px; text-align:center; color: #555;">
                        Cabang: {{ $cabangName }}
                    </div>
                @endif
            </td>
        </tr>
    </table>
</header>