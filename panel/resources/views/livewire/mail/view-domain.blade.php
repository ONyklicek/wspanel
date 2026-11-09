<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    @include('wire-panels::pages.partials.action-modals')

    @if ($dnsMissing !== [])
        <x-wire::callout color="warning" :heading="__('DNS domény zatím není nastavené pro poštu')">
            <ul class="list-disc ps-5">
                @foreach ($dnsMissing as $check)
                    <li><strong>{{ $check['label'] }}</strong>: {{ $check['detail'] }}</li>
                @endforeach
            </ul>
            <p class="mt-2">{{ __('Schránky založit jde (třeba kvůli převodu pošty), přijímat a spolehlivě odesílat budou až po úpravě DNS. Podrobnosti v Kontrole doručitelnosti.') }}</p>
        </x-wire::callout>
    @endif

    @livewire(\App\Livewire\Mail\MailboxTable::class, ['domain' => $domain->getKey()], key('mailboxes-'.$domain->getKey()))
    @livewire(\App\Livewire\Mail\MailAliasTable::class, ['domain' => $domain->getKey()], key('aliases-'.$domain->getKey()))
    @livewire(\App\Livewire\Mail\DmarcReportTable::class, ['domain' => $domain->getKey()], key('dmarc-'.$domain->getKey()))

    <x-wire::section :heading="__('Nastavení poštovních programů')" :description="__('Thunderbird, Outlook, telefon: server a porty, přihlašuje se celou adresou.')">
        <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
            @foreach ($settings as $label => $value)
                <dt class="text-gray-500">{{ $label }}</dt><dd class="font-mono">{{ $value }}</dd>
            @endforeach
        </dl>
    </x-wire::section>
</div>
