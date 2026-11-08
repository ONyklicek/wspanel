<div class="space-y-6">
    @if ($error)
        <x-wire::callout color="danger" :heading="__('Agent neodpovídá')">{{ $error }}</x-wire::callout>
    @endif

    <x-wire::section :heading="__('Zablokované adresy')" :description="__('Adresy, které opakovaně selhaly při přihlášení (SSH, pošta…), jsou na čas zablokované.')">
        @php($banned = collect($bans)->filter())
        @if ($banned->isEmpty())
            <p class="text-sm text-gray-500">{{ __('Žádná adresa teď není zablokovaná') }}{{ $bans ? __(' (hlídá: ').implode(', ', array_keys($bans)).')' : '' }}.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500"><tr><th class="py-1 pe-3 font-normal">{{ __('Adresa') }}</th><th class="py-1 pe-3 font-normal">{{ __('Kde') }}</th><th class="py-1"></th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($banned as $jail => $ips)
                        @foreach ($ips as $ip)
                            <tr wire:key="ban-{{ $jail }}-{{ $ip }}">
                                <td class="py-1 pe-3 font-mono text-xs">{{ $ip }}</td>
                                <td class="py-1 pe-3 text-gray-500">{{ $jail }}</td>
                                <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="unban('{{ $ip }}')">{{ __('Odblokovat') }}</x-wire::button></td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-wire::section>
</div>
