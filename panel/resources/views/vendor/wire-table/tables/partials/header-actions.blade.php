{{-- The toolbar's header-action buttons. --}}
{{-- Variables: $headerActions --}}
{{-- Rendered on its own so the same list can sit bare in the toolbar or inside
     the responsive wrapper that hides it below the mobile breakpoint
     (Table::collapseHeaderActionsOnMobile()). --}}
@foreach($headerActions as $headerAction)
    @if($headerAction->canExecute())
        {!! $headerAction->render() !!}
    @endif
@endforeach
