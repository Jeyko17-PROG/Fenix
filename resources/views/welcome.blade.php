{{--
    Shell HTML de la SPA de React. Es la ÚNICA página que entrega Laravel para
    cualquier ruta que no sea /api, /storage, /build o /up (ver routes/web.php):
    el ruteo real de pantalla a pantalla lo hace react-router-dom en el
    navegador, montando sobre <div id="root">.
--}}
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Fénix · Velocidad y eficiencia en tu punto de venta</title>
    <meta name="description"
        content="Fénix · Velocidad y eficiencia en tu punto de venta. Gestión de clientes, inventario, facturación y reservas.">

    {{-- Íconos y PWA: viven permanentemente en public/, no dependen del build de Vite. --}}
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="64x64" href="{{ asset('favicon.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('pwa-192x192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#e04a0a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Fénix">
    <meta name="mobile-web-app-capable" content="yes">

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/src/main.jsx'])
</head>

<body>
    <div id="root"></div>
</body>

</html>
