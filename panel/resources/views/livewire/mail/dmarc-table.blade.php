<div>
    <x-panel.table-section :heading="__('DMARC reporty')"
        :description="__('Kdo posílá poštu jménem domény a jestli prošla kontrolou DKIM a SPF, podle přehledů od přijímajících serverů.')">
        @if ($summary['reports'] > 0)
            <p class="mb-3 text-sm">
                {{ __('Posledních :days dní: :reports reportů, :messages zpráv, z toho', ['days' => $days, 'reports' => $summary['reports'], 'messages' => number_format($summary['messages'], 0, ',', ' ')]) }}
                <strong @class(['text-danger-600 dark:text-danger-400' => $summary['failed'] > 0])>
                    {{ __(':failed neprošlo', ['failed' => number_format($summary['failed'], 0, ',', ' ')]) }}
                </strong>
                @if ($summary['sources'] > 0)
                    {{ __('(:sources odesílajících serverů: buď podvržená pošta, nebo služba, která za doménu posílá a chybí v SPF/DKIM).', ['sources' => $summary['sources']]) }}
                @endif
            </p>
        @endif
        {{ $this->table }}
    </x-panel.table-section>
</div>
