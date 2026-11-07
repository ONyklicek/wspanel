@php
    use NyonCode\WireCore\Actions\Action;

    /** @var int $count */
    /** @var string $color */

    // Count badge surface. Colour resolves through the canonical soft badge palette
    // (Foundation HasColor::getBadgeColorClasses) so it matches every other pill in the UI.

    $badgeClasses = Action::getBadgeColorClasses($color);
@endphp
<span class="absolute -top-1.5 -right-1.5 inline-flex items-center justify-center min-w-[1.1rem] h-[1.1rem] px-1 text-[10px] font-bold rounded-full {{ $badgeClasses }}">{{ $count > 99 ? '99+' : $count }}</span>
