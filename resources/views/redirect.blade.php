<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Redirecting to payment</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; display: flex; min-height: 100vh; margin: 0; align-items: center; justify-content: center; color: #1f2937; background: #f9fafb; }
        main { text-align: center; padding: 1.5rem; }
        button { font: inherit; padding: .65rem 1.4rem; border: 0; border-radius: .4rem; background: #1f2937; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <form id="nepal-pay-redirect" method="POST" action="{{ $action }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <p>Redirecting you to the payment page&hellip;</p>
        <noscript>
            <p>JavaScript is disabled. Press the button to continue.</p>
            <button type="submit">Continue to payment</button>
        </noscript>
    </form>
</main>
<script @if ($nonce) nonce="{{ $nonce }}" @endif>document.getElementById('nepal-pay-redirect').submit();</script>
</body>
</html>
