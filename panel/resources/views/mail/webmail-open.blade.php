<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex">
    <title>Otevírám webmail…</title>
</head>
<body style="font-family: system-ui; margin: 3rem">
    <form id="signon" method="post" action="{{ $action }}">
        <input type="hidden" name="_wsp_sso" value="{{ $token }}">
        <p>Otevírám webmail schránky <strong>{{ $address }}</strong>…</p>
        <noscript><button type="submit">Pokračovat</button></noscript>
    </form>
    <script>document.getElementById('signon').submit();</script>
</body>
</html>
