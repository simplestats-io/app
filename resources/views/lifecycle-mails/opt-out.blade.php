<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Unsubscribe | {{ config('app.name') }}</title>
    <style>
        body { font: 18px/1.5 Helvetica, sans-serif; color: #333; margin: 0; padding: 120px 16px; }
        article { max-width: 560px; margin: 0 auto; }
        h1 { font-size: 32px; margin: 0 0 16px; }
        button { font: inherit; color: #fff; background: #6366f1; border: 0; border-radius: 6px; padding: 10px 20px; cursor: pointer; }
        button:hover { background: #4f46e5; }
        a { color: #6366f1; }
    </style>
</head>
<body>
<article>
    @if ($optedOut)
        <h1>You are unsubscribed</h1>
        <p>You will not get onboarding tips or feedback questions from {{ config('app.name') }} anymore. Mails about your account, reports and alerts you set up yourself still arrive as before.</p>
    @else
        <h1>Unsubscribe from tips and questions?</h1>
        <p>{{ config('app.name') }} occasionally sends you setup tips and asks for feedback. Mails about your account, reports and alerts you set up yourself are not affected.</p>
        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit">Unsubscribe</button>
        </form>
    @endif
    <p><a href="{{ route('home') }}">Back to {{ config('app.name') }}</a></p>
</article>
</body>
</html>
