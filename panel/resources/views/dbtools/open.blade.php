<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>Otevírám {{ $tool === 'phpmyadmin' ? 'phpMyAdmin' : 'Adminer' }}…</title>
</head>
<body style="font-family: system-ui; margin: 3rem">
    <form id="signon" method="post" action="{{ $action }}">
        <input type="hidden" name="token" value="{{ $token }}">
        <p>Otevírám databázi <strong>{{ $database->name }}</strong>…</p>
        <noscript><button type="submit">Pokračovat</button></noscript>
    </form>
    <script>document.getElementById('signon').submit();</script>
</body>
</html>
