{{-- The header actions a widget carries.

     One partial and not one per widget view, unlike the heading beside it: a
     heading is wrapped differently by every surface on purpose, but a row of
     action buttons is the same row wherever it sits, and four copies of it is
     the duplication CLAUDE.md forbids from the other direction. Each surface
     still decides *where* to include it.

     An action with nothing to dispatch to is not drawn, and that is a guard
     rather than tidiness. `getActionExpression()` is null when the widget has no
     key to be addressed by, and the shared button falls back to the *infolist*
     dispatch when it is handed no expression — a different method, on a host that
     either does not have it or has it for something else entirely. A button that
     calls the wrong thing is worse than one that is not there.

     It cannot happen through `WithWidgets`, which stamps a key on every widget
     before anything renders; it is reachable only by echoing a widget by hand.
     Which action is drawn is `Widget::getRenderableActions()`'s decision, not
     this template's — the rendering contract puts that in PHP.

     Variables: $widget --}}
@if($widget->hasRenderableActions())
    <div class="flex shrink-0 flex-wrap items-center gap-1.5">
        @foreach($widget->getRenderableActions() as $widgetAction)
            @include('wire-core::partials.component-action', [
                'action' => $widgetAction,
                'clickExpression' => $widget->getActionExpression($widgetAction),
                'testidPrefix' => 'widget-action',
            ])
        @endforeach
    </div>
@endif
