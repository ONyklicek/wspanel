{{-- The panel's language, in the user menu: the other language as one row. --}}
@auth
    @php($other = app()->getLocale() === 'en' ? 'cs' : 'en')
    <form method="POST" action="{{ route('locale.set', $other) }}">
        @csrf
        <x-wire::menu-item type="submit" icon="outline:language" data-testid="locale-switch">
            {{ \App\Http\Middleware\SetLocale::LOCALES[$other] }}
        </x-wire::menu-item>
    </form>
@endauth
