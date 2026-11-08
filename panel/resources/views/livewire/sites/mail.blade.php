<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    @include('wire-panels::pages.partials.action-modals')

    @if ($domain)
        @livewire(\App\Livewire\Mail\MailboxTable::class, ['domain' => $domain->getKey()], key('site-mailboxes-'.$domain->getKey()))
        @livewire(\App\Livewire\Mail\MailAliasTable::class, ['domain' => $domain->getKey()], key('site-aliases-'.$domain->getKey()))

        <x-wire::section :heading="__('Nastavení poštovních programů')" :description="__('Thunderbird, Outlook, telefon: server a porty, přihlašuje se celou adresou.')">
            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                @foreach ($settings as $label => $value)
                    <dt class="text-gray-500">{{ $label }}</dt><dd class="font-mono">{{ $value }}</dd>
                @endforeach
            </dl>
        </x-wire::section>
    @elseif ($installed)
        <x-wire::section :heading="__('Pošta pro :domain', ['domain' => $name])" :description="__('Doména zatím na tomto serveru poštu nepřijímá. Po zřízení tu založíte schránky a aliasy; do DNS domény pak patří MX záznam na ').\App\Actions\Mail\ManageMailHosting::mailHost().'.'">
            <x-wire::button icon="outline:plus" wire:click="setUp">{{ __('Zřídit poštu pro :domain', ['domain' => $name]) }}</x-wire::button>
        </x-wire::section>
    @else
        <x-wire::callout color="gray" :heading="__('Příjem pošty není nainstalovaný')">{{ __('Schránky půjde zakládat, až administrátor doinstaluje poštu (Server → Moduly).') }}</x-wire::callout>
    @endif
</div>
