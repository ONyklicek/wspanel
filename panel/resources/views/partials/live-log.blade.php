{{--
    Output that grows while a command runs (a deploy, the site console). It is
    the browser's: drawn once, then appended from an event the component
    dispatches with {content, reset}. Carried in the component state, a long
    log rode every poll in both directions.

    @param string $event    the browser event to listen to
    @param string $initial  what to draw on the first render
    @param string $empty    shown while there is no output
    @param string $class    the box's classes
--}}
<pre wire:ignore
     x-data="{ placeholder: @js($empty), blank: @js($initial === '') }"
     x-init="$el.scrollTop = $el.scrollHeight"
     x-on:{{ $event }}.window="
         const atEnd = $el.scrollHeight - $el.scrollTop - $el.clientHeight < 40;
         if ($event.detail.reset || blank) { $el.textContent = '' }
         $el.textContent += $event.detail.content;
         blank = $el.textContent === '';
         if (blank) { $el.textContent = placeholder }
         if (atEnd || $event.detail.reset) { $el.scrollTop = $el.scrollHeight }
     "
     class="{{ $class }}">{{ $initial !== '' ? $initial : $empty }}</pre>
