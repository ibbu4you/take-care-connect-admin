<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Staff tooling. Nothing here should ever appear in a search result. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#335CFF">

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
      | Inter, self-hosted from public/fonts/inter (see scripts/publish-fonts.mjs).
      |
      | Declared here rather than in app.css on purpose. A url() inside a
      | stylesheet resolves against wherever that stylesheet was served from,
      | and in development that is the Vite server, which does not serve
      | public/ — so every font 404'd until somebody ran a production build.
      | Declared in the page, /fonts/... always means this app's own origin.
      |
      | The two weights nearly every screen uses are preloaded; the paths must
      | match the src below exactly, or the file is downloaded twice.
    --}}
    <link rel="preload" href="/fonts/inter/inter-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/inter/inter-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>
    <style>
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: 400; font-display: swap; src: url('/fonts/inter/inter-latin-400-normal.woff2') format('woff2'); }
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: 500; font-display: swap; src: url('/fonts/inter/inter-latin-500-normal.woff2') format('woff2'); }
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: 600; font-display: swap; src: url('/fonts/inter/inter-latin-600-normal.woff2') format('woff2'); }
        @font-face { font-family: 'Inter'; font-style: normal; font-weight: 700; font-display: swap; src: url('/fonts/inter/inter-latin-700-normal.woff2') format('woff2'); }
    </style>

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
