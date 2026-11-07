{{-- The combobox under its original name, kept for published views and
     applications that include it directly. Everything — the combobox markup,
     its variables, and the native/mobile dispatch — lives in
     wire-core::partials.select-control; with no $nativeMode this renders the
     combobox exactly as it always did. --}}
@include('wire-core::partials.select-control')
