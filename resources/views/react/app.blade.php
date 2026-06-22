<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Photomate - Photobooth Premium</title>
    <link rel="icon" type="image/svg+xml" href="/logophotomateblue.png" />
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
