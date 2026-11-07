{{-- Create / edit option actions in the searchable-select panel footer.
     Opens the field's create/edit option modals (InteractsWithSelectCreation) and
     closes the Alpine dropdown. Lives inside the combobox x-data scope, so `open`
     and `selected` are in reach.

     Expected variables:
       $statePath    string       the Select's wire:model / state path
       $hasCreate    bool         render the "create" button
       $hasEdit      bool         render the "edit selected" button (single-select)
       $createLabel  string       create button label
       $editLabel    string       edit button label --}}
<div class="border-t border-gray-200 dark:border-gray-700 p-1 space-y-0.5">
    @if($hasEdit)
        <button
            type="button"
            x-show="selected"
            wire:click="mountEditOption('{{ $statePath }}')" data-testid="form-select-{{ $statePath }}-edit-option"
            @click="open = false"
            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-150"
        >
            {!! icon('pencil', 'w-4 h-4', 'w-4 h-4 shrink-0') !!}
            <span>{{ $editLabel }}</span>
        </button>
    @endif

    @if($hasCreate)
        <button
            type="button"
            wire:click="mountCreateOption('{{ $statePath }}')" data-testid="form-select-{{ $statePath }}-create-option"
            @click="open = false"
            class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium text-primary-600 dark:text-primary-400 hover:bg-gray-100 dark:hover:bg-gray-700 dark:hover:text-primary-300 transition-colors duration-150"
        >
            {!! icon('plus', 'w-4 h-4', 'w-4 h-4 shrink-0') !!}
            <span>{{ $createLabel }}</span>
        </button>
    @endif
</div>
