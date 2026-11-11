<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="min-h-screen">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        @if (! app()->isProduction())
            <meta name="robots" content="noindex, nofollow">
        @endif

        @php
            $blogPost = $page['component'] === 'Blog/Show' ? $page['props']['post'] : null;
            $metaTitle = config('app.name', 'Laravel').' - '.($blogPost['title'] ?? __('Revenue attribution analytics for Laravel apps'));
            $ogTitle = $blogPost['title'] ?? $metaTitle;
            $metaDescription = $blogPost['excerpt'] ?? __('Discover which channels actually drive revenue, not just traffic. Server-side analytics built for Laravel apps. GDPR compliant, 100% accurate, ad-blocker proof.');
        @endphp

        <title inertia>{{ $metaTitle }}</title>
        <meta name="description" content="{{ $metaDescription }}">

        <meta property="og:title" content="{{ $ogTitle }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ rtrim(config('app.url'), '/') }}{{ request()->getPathInfo() }}">
        <meta property="og:type" content="{{ $blogPost ? 'article' : 'website' }}">

        @if ($page['component'] === 'Blog/Show' && config('app.app_type') === 'saas')
            @include('partials.og-image-blog-post')
        @endif

        @php
            $supportedLocales = array_keys(config('locales.supported', []));
            $defaultLocale = config('locales.default', 'en');
            $publicPaths = config('locales.public_paths', []);
            $currentPath = request()->getPathInfo();
            $firstSegment = request()->segment(1);
            $pathWithoutLocale = $currentPath;
            if ($firstSegment && $firstSegment !== $defaultLocale && in_array($firstSegment, $supportedLocales)) {
                $pathWithoutLocale = '/' . ltrim(substr($currentPath, strlen('/' . $firstSegment)), '/');
            }
            if ($pathWithoutLocale === '') {
                $pathWithoutLocale = '/';
            }
            $isLocalizablePage = collect($publicPaths)->contains(fn ($p) => $pathWithoutLocale === $p || str_starts_with($pathWithoutLocale, $p . '/'));
        @endphp

        @if($isLocalizablePage)
            @foreach($supportedLocales as $loc)
                @if($loc === $defaultLocale)
                    <link rel="alternate" hreflang="{{ $loc }}" href="{{ rtrim(config('app.url'), '/') }}{{ $pathWithoutLocale }}">
                    <link rel="alternate" hreflang="x-default" href="{{ rtrim(config('app.url'), '/') }}{{ $pathWithoutLocale }}">
                @else
                    <link rel="alternate" hreflang="{{ $loc }}" href="{{ rtrim(config('app.url'), '/') }}/{{ $loc }}{{ $pathWithoutLocale === '/' ? '' : $pathWithoutLocale }}">
                @endif
            @endforeach
        @endif

        {{-- change this also in docs vitepress config.js --}}
        <link rel="canonical" href="{{ rtrim(config('app.url'), '/') }}{{ request()->getPathInfo() }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preload" as="style" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" media="print" onload="this.media='all'">
        <noscript>
            <link rel="stylesheet" href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap">
        </noscript>

        <!-- Icons -->
        <link rel="apple-touch-icon" sizes="180x180" href="{{favicon('/apple-touch-icon.png')}}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{favicon('/favicon-32x32.png')}}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{favicon('/favicon-16x16.png')}}">
        <link rel="manifest" href="{{ route('web-manifest') }}">

        <!-- preloads -->
        <link rel="prefetch" href="{{ asset('/images/side_bg.jpg') }}" as="image">

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans min-h-screen bg-white dark:bg-dark-900 font-normal text-gray-950 dark:text-white antialiased">
        @impersonating($guard = null)
            <div class="relative z-10 w-full bg-red-200 dark:text-dark-800 px-20 py-2 text-center">
                Attention: you are impersonating user <span class="font-bold">{{ auth()->user()->name }}</span>
                <a class="rounded-md bg-gray-100 hover:bg-gray-200 px-3 py-1 border border-gray-400 ml-2" href="{{ route('impersonate.leave.custom') }}">Leave</a>
            </div>
        @endImpersonating

        @inertia

        <script src="/js/dark_mode.js"></script>
    </body>
</html>
