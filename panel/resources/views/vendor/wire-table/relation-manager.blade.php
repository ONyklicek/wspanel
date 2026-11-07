{{-- Relation manager wrapper: optional heading over the relationship-scoped table. --}}
<div class="wire-relation-manager space-y-4">
    @if($title)
        <h3 class="text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
    @endif

    {{ $this->table }}
</div>
