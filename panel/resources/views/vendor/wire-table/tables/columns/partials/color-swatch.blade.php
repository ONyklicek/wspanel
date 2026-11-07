{{-- The colour itself: the chip, and the literal value beside it unless the
     column is swatch-only.

     Its own partial because the copy affordance wraps *rendered* markup — see
     `copyable` — so the cell needs this fragment as a value. That used to be a
     string built in a @php block in `tables.columns.color`; the markup lives here
     instead, which is also where a consumer overrides it.

     $swatch is a CSS colour already validated by Foundation\Support\CssColor, so
     it can only be a colour by the time it reaches the style attribute.

     Mind the whitespace: the tags touch, or the chip and its value would drift
     apart by a text node the gap-2 does not account for. --}}
<span class="inline-flex items-center gap-2"><span class="w-4 h-4 rounded-sm ring-1 ring-gray-200 dark:ring-gray-700 shrink-0" style="background-color: {{ $swatch }};"></span>@if($displayValue !== '')<span class="font-mono text-gray-700 dark:text-gray-300">{{ $displayValue }}</span>@endif</span>
