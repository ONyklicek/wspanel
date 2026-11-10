@if ($rows === [])
    @if ($empty !== '')<p class="text-sm text-gray-500">{{ $empty }}</p>@endif
@else
    <div class="overflow-x-auto">
    <table class="w-full text-left text-sm">
        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($rows as $r)
                <tr wire:key="{{ $remove }}-{{ $r['name'] ?? $loop->index }}">
                    <td class="py-1 pe-3 font-mono">{{ $r['name'] ?? '?' }}</td>
                    <td class="py-1 pe-3 text-xs text-gray-500">
                        @isset($r['level']){{ __('úroveň :n', ['n' => $r['level']]) }}@endisset
                        {{ $r['reason'] ?? '' }}
                    </td>
                    <td class="py-1 text-right"><x-wire::button size="xs" color="gray" outlined wire:click="act({{ \Illuminate\Support\Js::from($remove) }}, {{ \Illuminate\Support\Js::from($r['name'] ?? '') }})" :disabled="! $running">{{ $label }}</x-wire::button></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
@endif
