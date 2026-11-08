<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Vytvořit GitHub aplikaci') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; padding: 16px; background: Canvas; color: CanvasText; }
        main { max-width: 32rem; text-align: center; }
        button { font: inherit; padding: .6rem 1.2rem; border-radius: .5rem; border: 0; background: #1f2937; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
<main>
    <form id="manifest" method="post" action="{{ $action }}">
        <input type="hidden" name="manifest" value="{{ $manifest }}">
        <p>{{ __('Přesměrování na GitHub…') }}</p>
        <button type="submit">{{ __('Pokračovat na GitHub') }}</button>
    </form>
</main>
<script>document.getElementById('manifest').submit();</script>
</body>
</html>
