<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">

    <!-- Scripts -->
    <script src="https://cdn.paddle.com/paddle/v2/paddle.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.27.0/moment.min.js" integrity="sha512-rmZcZsyhe0/MAjquhTgiUcb4d9knaFc7b5xAfju483gbEXTkeJRUMIPk6s3ySZMYUHEcjKbjLjyddGWMrNEvZg==" crossorigin="anonymous"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/lodash.js/4.17.20/lodash.min.js" integrity="sha512-90vH1Z83AJY9DmlWa8WkjkV79yfS2n2Oxhsi2dZbIv0nC4E6m5AbH8Nh156kkM7JePmqD6tcZsfad1ueoaovww==" crossorigin="anonymous"></script>

    <!-- Styles -->
    <style>
        {!! file_get_contents($cssPath) !!}
    </style>

    @if (strpos((string) config('spark.brand.color'), '#') === 0)
    <style>
        .bg-custom-hex {
            background-color: {!! config('spark.brand.color') !!};
        }
    </style>
    @endif

    <style>
        .font-sans {
            font-family: Figtree, ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
        }

        .dark * {
            color: #f6f6f6; /* dark[100] */
        }
        .dark .bg-white {
            background-color: #1a1a1a !important; /* dark[900] */
        }
        .dark .bg-gray-100,
        .dark .bg-gray-200 {
            background-color: #292929 !important; /* dark[800] */
        }
        .dark .text-gray-500,
        .dark .text-gray-600 {
            color: #d6d6d6 !important; /* dark[300] */
        }
        .dark .text-gray-700,
        .dark .text-gray-800,
        .dark .text-gray-900 {
            color: #f6f6f6 !important; /* dark[100] */
        }
        .dark .border-gray-100,
        .dark .border-gray-200 {
            border-color: #555555 !important; /* dark[600] */
        }
        .dark textarea {
            background-color: #1a1a1a !important; /* dark[900] */
            color: #f6f6f6 !important; /* dark[100] */
        }

        .dark [class*="shadow-"] {
            border: 1px solid #434343 !important; /* dark[700] */
        }
    </style>

    <script src="/js/dark_mode.js?v={{ substr(md5_file(public_path('js/dark_mode.js')), 0, 8) }}"></script>

    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia

    <!-- Scripts -->
    <script>
        window.translations = <?php echo $translations; ?>;

        {!! file_get_contents($jsPath) !!}
    </script>
</body>
</html>
