<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>TestPilot AI · {{ $config['project'] }}</title>
    <link rel="stylesheet" href="{{ $config['basePath'] }}/assets/app.css?v={{ $config['assetVersion'] }}">
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <div id="app"></div>
    <script>
        window.TestPilot = @json($config);
    </script>
    <script type="module" src="{{ $config['basePath'] }}/assets/app.js?v={{ $config['assetVersion'] }}"></script>
</body>
</html>
