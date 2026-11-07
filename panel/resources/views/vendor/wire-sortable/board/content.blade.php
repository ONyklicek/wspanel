{{-- The board as a page's whole content — what a `Page` composing WithBoard
     names in its `$view`. A component that wants the board beside other things
     includes `wire-sortable::board.board` with `$this->boardLanesForView()`. --}}
@include('wire-sortable::board.board', ['lanes' => $this->boardLanesForView()])
