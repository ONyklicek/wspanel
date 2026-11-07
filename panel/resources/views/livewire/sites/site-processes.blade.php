<div>
    <x-wire::section heading="Procesy a workery"
        :description="$classic
            ? 'Web obsluhuje PHP-FPM '.$siteModel->php_version.' (procesy se spouštějí podle potřeby). Níže jsou samostatně běžící workery a naplánované úlohy, živě ze systemd.'
            : 'Proces aplikace, workery a naplánované úlohy tohoto webu, živě ze systemd.'">
        {{ $this->table }}
    </x-wire::section>
</div>
