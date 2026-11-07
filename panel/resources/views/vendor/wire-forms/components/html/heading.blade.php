{{-- Html::heading(). The level's tag and its type scale are resolved by the
     factory (rule 1: presentation decisions in PHP); this partial owns the tag
     it puts them on. --}}
<{{ $tag }} class="{{ $classes }} text-gray-900 dark:text-white">{{ $text }}</{{ $tag }}>
