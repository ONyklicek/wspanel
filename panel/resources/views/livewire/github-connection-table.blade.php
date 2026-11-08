<div>
    <x-wire::section :heading="__('GitHub')" :description="\App\Services\GitHub\GitHubApp::configured() ? __('Repozitáře, ke kterým má panel přístup, vybíráte na GitHubu: tlačítko Repozitáře u účtu.') : null">
        {{ $this->table }}
    </x-wire::section>
</div>
