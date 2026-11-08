<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>{{ __('Otevírám') }} {{ $tool === 'phpmyadmin' ? 'phpMyAdmin' : 'Adminer' }}…</title>
</head>
<body style="font-family: system-ui; margin: 3rem">
    <form id="signon" method="post" action="{{ $action }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <p>{{ __('Otevírám') }} <strong>{{ $label ?? __('databázi ').$database->name }}</strong>…</p>
        <noscript><button type="submit">{{ __('Pokračovat') }}</button></noscript>
    </form>
    <script>document.getElementById('signon').submit();</script>
</body>
</html>
