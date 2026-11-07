{{-- Excel-style fill handle.
     Variables: $fillColumns (array<int, string>), $fillMax (int)

     ONE handle per table, not one per cell. Two reasons, and both are binding:
     a per-cell handle would be columns×rows elements of record-invariant markup
     (the render-cost model forbids exactly that), and it would have to live
     inside the editable cell partial — which TextInputColumn renders once into a
     skeleton and splices three per-record tokens into, so any fourth per-record
     thing there silently freezes at the first row.

     wireFillHandle positions this element over whichever editable cell is active
     and moves it as the selection changes. It stays inert without JS. --}}

@include('wire-core::partials.floating-assets')

{{-- Compact at rest so it does not compete with the data, and only expands into
     a labelled copy button once the pointer is on it. The icon comes from the
     canonical PHP owner via icon(), never as inline <svg>. --}}
<button
    type="button"
    data-testid="table-fill-handle" @wireEl('table-fill-handle')
    data-fill-handle
    aria-label="{{ __('wire-table::messages.fill_handle') }}"
    title="{{ __('wire-table::messages.fill_handle') }}"
    class="wire-fill-handle absolute z-20 border border-white bg-primary-600 text-white dark:border-gray-800 dark:bg-primary-500"
    hidden
>{!! icon('outline:document-duplicate', 'h-3 w-3', 'wire-fill-handle-icon') !!}</button>

{{-- The dragged range outline. aria-hidden: the range is announced through the
     handle, and a decorative rectangle would only add noise. --}}
<div
    data-testid="table-fill-overlay" @wireEl('table-fill-overlay')
    data-fill-overlay
    aria-hidden="true"
    class="wire-fill-overlay pointer-events-none absolute z-10 border-2 border-primary-500 dark:border-primary-400"
    hidden
></div>

@once
    <style>
        /* Only the JS-applied bits live here. Tailwind scans Blade and PHP, not
           resources/js, so a class this file never names would never be built. */

        .wire-fill-handle[hidden],
        .wire-fill-overlay[hidden] {
            display: none;
        }

        /* Positioned by its CENTRE on the cell's bottom-right corner, so growing
           on hover expands symmetrically instead of dragging the anchor sideways. */
        .wire-fill-handle {
            display: grid;
            place-items: center;
            transform: translate(-50%, -50%);
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 1px;
            cursor: crosshair;
            touch-action: none; /* let the pointer drag through, never scroll the page */
            transition: width 90ms ease, height 90ms ease, border-radius 90ms ease;
        }

        /* A generous invisible grab area around a deliberately small square: an
           8px target on a row boundary is fiddly to hit, and every pixel spent
           approaching it is a pixel over the row below. Pseudo-elements are not
           event targets, so this enlarges the hit region while `event.target`
           stays the button itself. */
        .wire-fill-handle::before {
            content: '';
            position: absolute;
            inset: -0.5rem;
        }

        .wire-fill-handle:hover,
        .wire-fill-handle:focus-visible {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 0.375rem;
        }

        /* Clipped by the 8px box at rest; fades in as the button opens. */
        .wire-fill-handle-icon {
            opacity: 0;
            transition: opacity 90ms ease;
        }

        .wire-fill-handle:hover .wire-fill-handle-icon,
        .wire-fill-handle:focus-visible .wire-fill-handle-icon {
            opacity: 1;
        }

        /* Touch has no hover: without this the icon would never show on a phone. */
        @media (hover: none) {
            .wire-fill-handle {
                width: 1.25rem;
                height: 1.25rem;
                border-radius: 0.375rem;
            }

            .wire-fill-handle-icon {
                opacity: 1;
            }
        }

        /* Rows the drag currently covers. Two declarations on purpose: the first
           is the fallback, the second picks up the consumer's primary hue when
           the browser supports color-mix. */
        .wire-fill-target {
            background-color: rgb(59 130 246 / 0.10);
            background-color: color-mix(in oklab, var(--color-primary-500, rgb(59 130 246)) 12%, transparent);
        }

        .dark .wire-fill-target {
            background-color: rgb(96 165 250 / 0.16);
            background-color: color-mix(in oklab, var(--color-primary-400, rgb(96 165 250)) 16%, transparent);
        }

        /* While dragging: no text selection anywhere, and the crosshair follows
           the pointer even outside the table. */
        body.wire-filling {
            cursor: crosshair;
            user-select: none;
            -webkit-user-select: none;
        }
    </style>
@endonce
