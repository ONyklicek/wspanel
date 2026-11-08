{{-- The toolbar's header-action buttons. --}}
{{-- Variables: $headerActions --}}
{{-- Rendered on its own so the same list can sit bare in the toolbar or inside
     the responsive wrapper that hides it below the mobile breakpoint
     (Table::collapseHeaderActionsOnMobile()). --}}
{{-- Local fix: an Action (not a HeaderAction) in a table's header rendered
     wire:click="mountAction(…)", which a table component does not have (a
     MethodNotFoundException, shown in production as "This page has expired").
     The table's own record-less header methods take it instead. --}}
@foreach($headerActions as $headerAction)
    @if($headerAction->canExecute())
        @if($headerAction instanceof \NyonCode\WireCore\Actions\Action)
            {!! $headerAction->render(null, new \NyonCode\WireTable\Actions\HeaderActionClickResolver) !!}
        @else
            {!! $headerAction->render() !!}
        @endif
    @endif
@endforeach
