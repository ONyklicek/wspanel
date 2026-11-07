{{-- Base text column cell. Column owns state/config; this partial owns markup.

     Every wrapper is written as a tag pair guarded by its own condition rather
     than assembled into a string: the cell chrome is markup, and markup lives in the
     template (AI_CODING_STANDARD § Rendering). The nesting order is fixed —
     description > tooltip > copy > link > icon + text — so the pairs can be
     opened top-down and closed bottom-up without any of it becoming PHP.

     The conditions are written as raw `<?php if ?>` rather than `@if`, and that
     is not a style slip: Livewire wraps every `@if` in a
     `<!--[if BLOCK]><![endif]-->` morph-marker pair, and this template renders
     once per cell — fifteen pairs per cell measured 3.6 kB per row against a
     1.9 kB budget when this was first written with directives. The markup is
     what belongs in the template; the branching is control flow either way, and
     Blade compiles `@if` to exactly this.

     Tags that must touch are written touching, and a line holding only PHP emits
     nothing (PHP eats the newline after `?>`), so the only whitespace this cell
     produces is the single space beside an icon — exactly as before. That matters
     twice over: the skeleton path compiles this same template once per cell
     *shape* and splices per row, and the payload fuse budgets whitespace text
     nodes per row. --}}
@php
    /** @var string $content raw formatted value (escaped here unless $isHtml) */
    /** @var string $textClasses */
    /** @var bool $isHtml */
    /** @var string $iconHtml resolved icon svg (may be empty) */
    /** @var string $iconPosition before|after */
    /** @var string|null $url */
    /** @var bool $openInNewTab */
    /** @var bool $navigate the link carries wire:navigate */
    /** @var bool $copyable */
    /** @var mixed $copyValue */
    /** @var string $copyMessage */
    /** @var string|null $tooltip */
    /** @var string|null $description */
    /** @var string $descriptionPosition above|below */

    $hasIcon = $iconHtml !== '';
    $iconAfter = $iconPosition === 'after';
    $hasDescription = $description !== null && $description !== '';
    $descriptionAbove = $hasDescription && $descriptionPosition === 'above';

    // The button is core's — the same affordance an infolist entry uses, compiled
    // once per shape there and spliced, so a copyable cell still costs no view
    // render. Resolved here because it is a *string owner*, not markup: the
    // template only echoes what it returns. The wrapper it sits in is written
    // below; `partials.copyable` holds the same wrapper for ColorColumn.
    $copyButtonHtml = $copyable
        ? app(\NyonCode\WireCore\Foundation\View\CopyButton::class)->render(
            value: (string) $copyValue,
            message: (string) $copyMessage,
            label: $copyMessage ?? __('wire-table::messages.copy'),
            title: __('wire-table::messages.copy'),
            testId: 'cell-copy',
        )
        : '';
@endphp
<?php if ($hasDescription): ?><div><?php endif; ?>
<?php if ($descriptionAbove): ?><p class="text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p><?php endif; ?>
<?php if ($tooltip): ?><span title="{{ $tooltip }}" class="cursor-help"><?php endif; ?>
<?php if ($copyable): ?><span class="inline-flex items-center gap-1.5 group"><?php endif; ?>
<?php if ($url): ?><a href="{{ $url }}"<?php if ($openInNewTab): ?> target="_blank"<?php endif; ?><?php if ($navigate ?? false): ?> wire:navigate<?php endif; ?> class="text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300 hover:underline"><?php endif; ?>
<?php if ($hasIcon && ! $iconAfter): ?>{!! $iconHtml !!} <?php endif; ?>
<?php if ($textClasses !== ''): ?><span class="{{ $textClasses }}"><?php endif; ?>
<?php if ($isHtml): ?>{!! $content !!}<?php else: ?>{{ $content }}<?php endif; ?>
<?php if ($textClasses !== ''): ?></span><?php endif; ?>
<?php if ($hasIcon && $iconAfter): ?> {!! $iconHtml !!}<?php endif; ?>
<?php if ($url): ?></a><?php endif; ?>
<?php if ($copyable): ?>{!! $copyButtonHtml !!}</span><?php endif; ?>
<?php if ($tooltip): ?></span><?php endif; ?>
<?php if ($hasDescription && ! $descriptionAbove): ?><p class="text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p><?php endif; ?>
<?php if ($hasDescription): ?></div><?php endif; ?>
