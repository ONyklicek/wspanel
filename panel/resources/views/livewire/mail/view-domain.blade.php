<div class="wire-resource-page space-y-4 sm:space-y-6">
    @include('wire-panels::pages.partials.header')
    @include('wire-panels::pages.partials.action-modals')

    <x-wire::section heading="Nastavení poštovních programů" description="Thunderbird, Outlook, telefon: server a porty, přihlašuje se celou adresou.">
        <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
            @foreach ($settings as $label => $value)
                <dt class="text-gray-500">{{ $label }}</dt><dd class="font-mono">{{ $value }}</dd>
            @endforeach
        </dl>
    </x-wire::section>

    @livewire(\App\Livewire\Mail\MailboxTable::class, ['domain' => $domain->getKey()], key('mailboxes-'.$domain->getKey()))
    @livewire(\App\Livewire\Mail\MailAliasTable::class, ['domain' => $domain->getKey()], key('aliases-'.$domain->getKey()))
</div>
