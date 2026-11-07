{{-- A plain read-only table for dashboard cards. Rows are arrays keyed like $columns; 'hint' adds a second line to the first column. --}}
<x-wire::section :heading="$heading ?? null" :description="$description ?? null">
<div class="overflow-x-auto">
    @if ($rows === [])
        <p class="py-4 text-sm text-gray-500">{{ $empty ?? 'Nic k zobrazení.' }}</p>
    @else
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500 dark:border-white/10">
                    @foreach ($columns as $label)
                        <th class="py-2 pe-4 font-medium">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr>
                        @foreach (array_keys($columns) as $i => $key)
                            <td class="py-2 pe-4 align-top {{ $i === 0 ? 'font-medium text-gray-900 dark:text-white' : 'text-gray-700 dark:text-gray-300' }}">
                                @if ($i === 0 && ! empty($link ?? null) && ! empty($row[$link]))
                                    <a href="{{ $row[$link] }}" wire:navigate class="hover:underline">{{ $row[$key] }}</a>
                                @else
                                    {{ $row[$key] ?? '—' }}
                                @endif
                                @if ($i === 0 && ! empty($row['hint']))
                                    <span class="block font-mono text-xs font-normal text-gray-500">{{ $row['hint'] }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
</x-wire::section>
