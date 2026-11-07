{{-- The avatar layout: a round picture and two buttons.

     `avatar()` used to change only the shape of the thumbnail, so a profile page
     asking for one picture of one person got the same full-width dashed dropzone
     as a document library, with the face listed underneath it as a file. One
     image, of a known size, of a known shape, in a place where a form is asking
     you what you look like — that is not a drop target, it is a picture with a
     button beside it.

     The same inputs as the other layout, from the same partial, inside the same
     Alpine root: what changes is where you click, not what happens then. Drag
     and drop still works, because the picture is the drop target. --}}
@php
    $avatarPreview = $filePreviews[0] ?? null;
    $avatarUrl = ($avatarPreview['url'] ?? null) && ($avatarPreview['isImage'] ?? false)
        ? $avatarPreview['url']
        : null;
@endphp

<div class="flex items-center gap-4">
    <button
        type="button"
        @click="openPicker()"
        @dragover.prevent="isDragging = true"
        @dragleave.prevent="isDragging = false"
        @drop.prevent="handleDrop($event)"
        :class="{ 'ring-primary-500': isDragging }"
        data-testid="form-file-{{ $field->getStatePath() }}-dropzone"
        @class([
            'relative h-20 w-20 shrink-0 overflow-hidden rounded-full ring-2 ring-offset-2 transition',
            'ring-gray-200 dark:ring-gray-600 dark:ring-offset-gray-800',
            'ring-red-500' => $errors->has($field->getStatePath()),
        ])
    >
        @if ($avatarUrl)
            <img
                src="{{ $avatarUrl }}"
                alt="{{ $avatarPreview['name'] ?? '' }}"
                data-testid="form-file-{{ $field->getStatePath() }}-avatar"
                class="h-full w-full rounded-full object-cover"
            />
        @else
            <span class="flex h-full w-full items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
                {!! icon('outline:user', 'w-4 h-4', 'h-8 w-8 text-gray-500 dark:text-gray-400') !!}
            </span>
        @endif

        {{-- The spinner sits over the picture rather than replacing it: an avatar
             that vanishes while it uploads reads as one that was deleted. --}}
        <span
            class="absolute inset-0 flex items-center justify-center rounded-full bg-white/70 dark:bg-gray-900/70"
            wire:loading
            wire:target="{{ $field->getWireModelAttribute() }}"
        >
            @include('wire-core::partials.spinner', ['class' => 'h-6 w-6 text-primary-500'])
        </span>
    </button>

    <div class="min-w-0 space-y-1">
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                @click="openPicker()"
                data-testid="form-file-{{ $field->getStatePath() }}-choose"
                class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700"
            >
                {{ $avatarUrl ? __('Change photo') : __('Upload a photo') }}
            </button>

            @if ($avatarUrl && $canDelete)
                <button
                    type="button"
                    wire:click="removeUploadedFile('{{ $field->getStatePath() }}', {{ (int) ($avatarPreview['index'] ?? 0) }})"
                    data-testid="form-file-{{ $field->getStatePath() }}-remove-{{ (int) ($avatarPreview['index'] ?? 0) }}"
                    class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-500 transition hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400"
                >
                    {{ __('Remove') }}
                </button>
            @endif
        </div>

        @if(!empty($acceptedTypes) || $maxSize)
            <p class="text-xs text-gray-500 dark:text-gray-400">
                @if(!empty($acceptedTypes))
                    {{ implode(', ', array_map(fn($t) => ltrim($t, '.'), $acceptedTypes)) }}
                @endif
                @if($maxSize)
                    {{ !empty($acceptedTypes) ? ' — ' : '' }}{{ __('max') }} {{ $maxSize >= 1024 ? round($maxSize / 1024, 1) . ' MB' : $maxSize . ' KB' }}
                @endif
            </p>
        @endif
    </div>

    @include('wire-forms::partials.file-upload-inputs')
</div>
