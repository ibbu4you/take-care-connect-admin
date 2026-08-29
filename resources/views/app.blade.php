<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Staff tooling. Nothing here should ever appear in a search result. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#283A8E">

    {{--
      | The TCIF seal, rasterised from public/img/logo.webp.
      |
      | PNG rather than the webp itself: browser support for webp *favicons*
      | still trails support for webp images, and the failure mode is a blank
      | tab rather than an error anybody would notice. The Apple icon is
      | composited onto white because iOS fills transparency with black.
    --}}
    <link rel="icon" href="/favicon.png" type="image/png" sizes="64x64">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    {{--
      | Preloaded because DM Sans is the only family the app loads and every
      | screen is text. The paths match the @font-face rules in app.css exactly;
      | a mismatch here downloads the file twice rather than failing loudly.
    --}}
    <link rel="preload" href="/fonts/dm-sans/dm-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/dm-sans/dm-sans-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>

    {{--
      | Theme applied before first paint.
      |
      | Inline and synchronous on purpose: if this waited for the bundle, a
      | dark-mode user would get a full white flash on every page load. Wrapped
      | in try/catch because localStorage throws outright in some privacy modes,
      | and a theme preference is not worth a blank page.
    --}}
    <script>
        (function () {
            try {
                var stored = localStorage.getItem('tcc-theme');
                var dark = stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches);
                if (dark) { document.documentElement.classList.add('dark'); }
            } catch (e) {}
        })();
    </script>

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="min-h-screen bg-app font-sans text-foreground antialiased">
    @inertia
</body>
</html>
