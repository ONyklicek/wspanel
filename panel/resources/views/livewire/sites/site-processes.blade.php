<div>
    <x-wire::section :heading="__('Procesy a workery')"
        :description="$classic
            ? __('Web obsluhuje PHP-FPM :version (procesy se spouštějí podle potřeby). Níže jsou samostatně běžící workery a naplánované úlohy, živě ze systemd.', ['version' => $siteModel->php_version])
            : __('Proces aplikace, workery a naplánované úlohy tohoto webu, živě ze systemd.')">
        {{ $this->table }}
    </x-wire::section>
</div>
