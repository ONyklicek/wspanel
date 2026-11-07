{{-- Typing into a picker's trigger, shared by DateTimePicker and TimePicker.

     Both triggers show a *formatted* value over state the widget owns — the box
     reads `9. 3. 2026 14:30` while the state holds `2026-03-09T14:30` — so a
     typed string has to be read back through the same format it was written
     with. That inverse parser is identical for the two views. What differs is
     what a parsed set of numbers *means* (a calendar day plus a clock, or a
     clock alone), so each view supplies its own applyTyped().

     Extracted for the same reason as date-time-native-input: two copies of the
     same parser is the pair that drifts, and a date parser that drifts by one
     format token stores the wrong day without saying so.

     Included INSIDE the x-data object literal, so every entry here is a property
     or a method and ends in a comma.

     Expects the including component to provide:
       - value              the widget's state
       - displayValue       the getter the trigger renders
       - applyTyped(parts)  commit {year, month, day, hours, minutes, seconds}
                            (whichever subset the format carries) and return
                            true, or return false to refuse the whole entry

     Provides: typed, typing, onTyped(), commitTyped(), cancelTyped(). --}}

{{-- The text mid-edit, and whether the trigger is showing it instead of the
     formatted value. `typing` is the flag rather than a non-empty `typed`,
     because emptying the box is itself an edit — it clears the field. --}}
typed: '',
typing: false,
typedFormat: @js($field->getTypedFormat()),

onTyped(text) {
    this.typing = true;
    this.typed = text;
},

{{-- The order the parts appear in, read straight off the format the trigger
     renders — the exact inverse of the formatter above. --}}
typedTokens() {
    const keys = {
        d: 'day', j: 'day', m: 'month', n: 'month', Y: 'year', y: 'year',
        H: 'hours', G: 'hours', i: 'minutes', s: 'seconds',
    };
    const out = [];
    for (let i = 0; i < this.typedFormat.length; i++) {
        const c = this.typedFormat[i];
        if (c === '\\') { i++; continue; }
        if (c in keys) out.push(keys[c]);
    }
    return out;
},

{{-- Read what was typed back into numbers.

     Positional rather than a strict regex: the format says what order the parts
     come in, and each run of digits fills the next place. So '9.3.2026',
     '09. 03. 2026' and '9/3/2026' all read alike, which is what someone
     retyping a date actually produces. --}}
readTyped(text) {
    const tokens = this.typedTokens();
    const numbers = String(text).match(/\d+/g);
    if (! tokens.length || ! numbers) return null;

    {{-- More numbers than the format has places is a typo, not a date. --}}
    if (numbers.length > tokens.length) return null;

    {{-- Stopping early is only a reading if what is left is the clock:
         '15.3.2027' into a datetime field means that day at the time already
         showing, while half a date has no reading at all. --}}
    const missing = tokens.slice(numbers.length);
    if (missing.some((key) => key === 'day' || key === 'month' || key === 'year')) return null;

    const parts = {};
    numbers.forEach((raw, i) => {
        const value = parseInt(raw, 10);
        {{-- '26' is this century; '2026' is already itself. --}}
        parts[tokens[i]] = (tokens[i] === 'year' && raw.length <= 2) ? value + 2000 : value;
    });

    return parts;
},

{{-- Commit on blur and on Enter. Something that will not parse, or that the
     bounds refuse, puts the last good value back rather than being stored
     half-read or silently dropped. --}}
commitTyped() {
    {{-- Blur fires on the way into the panel too; only a real edit commits. --}}
    if (! this.typing) return true;

    this.typing = false;
    const text = this.typed.trim();
    this.typed = '';

    if (text === '') {
        this.value = null;
        return true;
    }

    const parts = this.readTyped(text);

    return parts ? this.applyTyped(parts) : false;
},

cancelTyped() {
    this.typing = false;
    this.typed = '';
},
