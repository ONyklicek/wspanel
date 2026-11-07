{{-- Single root for Livewire. The floating-assets partial registers the shared
     wire-core bundle (which defines the wireEditableCell Alpine engine the
     editable entries commit through) via Livewire's @assets directive. --}}
<div data-msg-unsaved-edits="{{ __('wire-core::messages.unsaved_edits') }}">
    @include('wire-core::partials.floating-assets')

    {{ $panel }}
</div>
