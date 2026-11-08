<!doctype html>
<html lang="cs">
<head><meta charset="utf-8"><title>{{ __('Nástroj nelze otevřít') }}</title></head>
<body style="font-family: system-ui; margin: 3rem">
    <h1>{{ __('Nástroj nelze otevřít') }}</h1>
    <p>{{ $message }}</p>
    <p><a href="{{ url('/databases') }}">{{ __('Zpět na databáze') }}</a></p>
</body>
</html>
