{{-- Customise / Save / Cancel / Reset, ready-made.

     The methods behind these are public on `WithWidgets`, and the chrome stays
     the caller's to write — a dashboard inside somebody's own page layout wants
     its buttons where that layout puts buttons, not where this partial does.
     What this is for is the common case: a page that wants the feature and not a
     design exercise.

     Renders nothing at all unless the host opted in, so including it
     unconditionally is safe on a dashboard nobody may rearrange.

     On an `autosave()` dashboard every change is already stored, so the mode
     ends with Done rather than Save and Cancel — a Cancel there would promise
     an undo nothing can give. Reset is offered only while the user has a
     layout of their own; on the default there is nothing to reset to.

     Variables: $customisable, $editing, $savedLayouts, $layoutNames, $autosave, $hasStoredLayout --}}
@if($customisable)
    @php
        // Handed in by `widgetGridData()`, defaulted here for the caller that
        // assembles its own payload — a dashboard that never asked for named
        // layouts must not have a switcher appear because a key was missing.
        $savedLayouts = $savedLayouts ?? false;
        $layoutNames = $layoutNames ?? [];
        $autosave = $autosave ?? false;
        // Unknown means "assume there is something": a caller assembling its
        // own payload keeps the Reset it always had.
        $hasStoredLayout = $hasStoredLayout ?? true;
    @endphp

    <div class="wire-widget-layout-controls flex items-center justify-end gap-2">
        @if($savedLayouts)
            {{-- The switcher, and only where there is something to switch to.
                 An empty select beside a "Save as" button reads as a list that
                 failed to load rather than as one nobody has filled yet. --}}
            @if($layoutNames !== [])
                {{-- The chosen name is Alpine's, not the component's: it is what
                     the delete button acts on and nothing else reads it, so
                     keeping it on the server would be a round trip and a
                     property to rehydrate for a value that dies with the page.
                     Applying a layout copies it onto the current one, so the
                     select is a verb rather than a state the page returns to. --}}
                <div x-data="{ layout: '' }" class="flex items-center gap-2">
                    <select
                        x-model="layout"
                        x-on:change="$wire.applyWidgetLayout(layout)"
                        data-testid="widget-layout-view" @wireEl('widget-layout-view')
                        aria-label="{{ __('wire-core::messages.widget_saved_layouts') }}"
                        class="rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-base sm:text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300"
                    >
                        <option value="">{{ __('wire-core::messages.widget_saved_layout_current') }}</option>
                        @foreach($layoutNames as $layoutName)
                            <option value="{{ $layoutName }}">{{ $layoutName }}</option>
                        @endforeach
                    </select>

                    <button type="button"
                            x-show="layout !== ''"
                            x-on:click="$wire.deleteWidgetLayout(layout); layout = ''"
                            data-testid="widget-layout-delete" @wireEl('widget-layout-delete')
                            aria-label="{{ __('wire-core::messages.widget_delete_layout') }}"
                            class="rounded-sm p-1 text-gray-500 transition hover:text-red-600 dark:hover:text-red-400">
                        {!! icon('outline:trash', 'w-4 h-4', 'h-4 w-4') !!}
                    </button>
                </div>
            @endif

            {{-- `window.prompt` for the name, as the table's own saved views do:
                 a modal for one string is a modal to maintain, and the host is
                 free to call `saveWidgetLayoutAs()` from its own chrome. --}}
            <button type="button"
                    x-on:click="$wire.saveWidgetLayoutAs(window.prompt(@js(__('wire-core::messages.widget_save_layout_prompt'))) ?? '')"
                    data-testid="widget-layout-save-as" @wireEl('widget-layout-save-as')
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ __('wire-core::messages.widget_save_layout_as') }}
            </button>
        @endif

        @if($editing)
            @if($autosave)
            {{-- Everything is stored already; Done only closes the mode. It
                 calls the same save as Save does, which writes nothing new. --}}
            <button type="button" wire:click="saveWidgetLayout"
                    data-testid="widget-layout-done" @wireEl('widget-layout-done')
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-primary-700">
                {!! icon('outline:check', 'w-4 h-4', 'h-4 w-4') !!}
                {{ __('wire-core::messages.widget_done_layout') }}
            </button>
            @else
            <button type="button" wire:click="saveWidgetLayout"
                    data-testid="widget-layout-save" @wireEl('widget-layout-save')
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white transition hover:bg-primary-700">
                {!! icon('outline:check', 'w-4 h-4', 'h-4 w-4') !!}
                {{ __('wire-core::messages.widget_save_layout') }}
            </button>

            <button type="button" wire:click="cancelEditingWidgets"
                    data-testid="widget-layout-cancel" @wireEl('widget-layout-cancel')
                    class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                {{ __('wire-core::messages.widget_cancel_layout') }}
            </button>
            @endif

            @if($hasStoredLayout)
            {{-- Last, and visually quietest: it throws away a layout somebody
                 may have spent a while on, and the two buttons it sits beside
                 are the ones being reached for. --}}
            <button type="button" wire:click="resetWidgetLayout"
                    data-testid="widget-layout-reset" @wireEl('widget-layout-reset')
                    class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                {{ __('wire-core::messages.widget_reset_layout') }}
            </button>
            @endif
        @else
            <button type="button" wire:click="startEditingWidgets"
                    data-testid="widget-layout-edit" @wireEl('widget-layout-edit')
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700">
                {!! icon('outline:adjustments-horizontal', 'w-4 h-4', 'h-4 w-4') !!}
                {{ __('wire-core::messages.widget_customise') }}
            </button>
        @endif
    </div>
@endif
