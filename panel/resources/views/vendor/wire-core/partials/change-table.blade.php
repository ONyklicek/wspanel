@php
    use NyonCode\WireCore\Foundation\View\Palette;
@endphp
{{-- A before-and-after diff, as a table.

     One markup for the two places this stack draws a change: the audit trail
     slide-over (`wire-core::audit.trail`) and the `ChangesEntry` on a page. They
     were the same table written twice, and had already drifted — one of them
     printed a boolean as `1`.

     Rows come from NyonCode\WireCore\Foundation\ValueObjects\ChangeSet, so a
     value's text is decided in PHP and this file only places it. A `null` here
     means *there was nothing*, which is why it is a distinct branch rather than
     an empty cell: "was empty, now set" and "was set, now empty" are the two
     rows a reader looks for first. --}}
@props([
    'rows' => [],
    'dense' => false,
])

<div class="overflow-hidden overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
    <table @class(['min-w-full', 'text-xs' => $dense, 'text-sm' => ! $dense])>
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                <th class="px-3 py-1.5 text-start font-medium text-gray-500 dark:text-gray-400">{{ __('wire-core::audit.field') }}</th>
                <th class="px-3 py-1.5 text-start font-medium text-gray-500 dark:text-gray-400">{{ __('wire-core::audit.old_value') }}</th>
                <th class="px-3 py-1.5 text-start font-medium text-gray-500 dark:text-gray-400">{{ __('wire-core::audit.new_value') }}</th>
            </tr>
        </thead>

        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            @foreach ($rows as $row)
                <tr>
                    <td class="px-3 py-1.5 font-medium break-all text-gray-700 dark:text-gray-300">{{ $row['field'] }}</td>

                    <td class="px-3 py-1.5 break-all {{ Palette::getTextColorClasses('danger') }}">
                        @if ($row['before'] !== null)
                            <span class="rounded-sm px-1 {{ Palette::getSoftTintClasses('danger') }}">{{ $row['before'] }}</span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400 italic">{{ __('wire-core::audit.empty') }}</span>
                        @endif
                    </td>

                    <td class="px-3 py-1.5 break-all {{ Palette::getTextColorClasses('success') }}">
                        @if ($row['after'] !== null)
                            <span class="rounded-sm px-1 {{ Palette::getSoftTintClasses('success') }}">{{ $row['after'] }}</span>
                        @else
                            <span class="text-gray-500 dark:text-gray-400 italic">{{ __('wire-core::audit.empty') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
