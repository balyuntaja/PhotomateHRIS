<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Primary SEO Meta Tags -->
    <title>{{ $seo['title'] ?? 'Photomate.id - Jasa Sewa Photobooth Premium Malang & Jawa Timur' }}</title>
    <meta name="description" content="{{ $seo['description'] ?? 'Photomate.id menyediakan jasa penyewaan photobooth premium di Malang, Jawa Timur. Cetak foto instan kecepatan tinggi dengan template kustom untuk pernikahan, corporate event, ulang tahun, dan wisuda.' }}">
    <meta name="keywords" content="{{ $seo['keywords'] ?? 'photobooth malang, sewa photobooth malang, photobooth premium jawa timur, souvenir foto instan, cetak foto cepat, photomate, photobooth murah malang, photobooth pernikahan' }}">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="{{ $seo['url'] ?? request()->url() }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="{{ $seo['type'] ?? 'website' }}">
    <meta property="og:url" content="{{ $seo['url'] ?? request()->url() }}">
    <meta property="og:title" content="{{ $seo['title'] ?? 'Photomate.id - Jasa Sewa Photobooth Premium Malang & Jawa Timur' }}">
    <meta property="og:description" content="{{ $seo['description'] ?? 'Photomate.id menyediakan jasa penyewaan photobooth premium di Malang, Jawa Timur.' }}">
    <meta property="og:image" content="{{ $seo['image'] ?? asset('logophotomateblue.png') }}">
    <meta property="og:site_name" content="Photomate.id">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="{{ $seo['url'] ?? request()->url() }}">
    <meta name="twitter:title" content="{{ $seo['title'] ?? 'Photomate.id - Jasa Sewa Photobooth Premium Malang & Jawa Timur' }}">
    <meta name="twitter:description" content="{{ $seo['description'] ?? 'Photomate.id menyediakan jasa penyewaan photobooth premium di Malang, Jawa Timur.' }}">
    <meta name="twitter:image" content="{{ $seo['image'] ?? asset('logophotomateblue.png') }}">

    <link rel="icon" type="image/svg+xml" href="/logophotomateblue.png" />
    
    <!-- Structured Data (JSON-LD) -->
    @if(isset($seo['schema']))
    <script type="application/ld+json">
        {!! json_encode($seo['schema'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
    @endif

    @viteReactRefresh
    @vite(['resources/js/react/main.tsx'])
    <script>
        window.CMS_DATA = @json($cmsData ?? []);
    </script>
</head>
<body>
    <div id="root"></div>
</body>
</html>
