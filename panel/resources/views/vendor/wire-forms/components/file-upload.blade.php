@php
    use NyonCode\WireForms\Components\FileUpload;
    use NyonCode\WireForms\WireFormsServiceProvider;

    assert($field instanceof FileUpload);

    $wireModifier = $field->getWireModelModifier();
    $wireAttr = 'wire:model' . ($wireModifier ? ".{$wireModifier}" : '');
    $acceptedTypes = $field->getAcceptedFileTypes();
    $isMultiple = $field->isMultiple();
    $fieldId = $field->getId();
    $maxSize = $field->getMaxSize();
    $filePreviews = $field->getFilePreviews(data_get($this, $field->getStatePath()));
    $canDelete = $field->isDeletable() && ! $field->isDisabled();

    // Crop/resize runs in the browser so an oversized original never travels.
    // Only then does the field need its own picker input; otherwise it keeps the
    // plain wire:model input and the untouched upload path.
    $processesImages = $field->processesImages();
    // An avatar is shown round — that is what makes it read as an avatar rather
    // than a thumbnail. The 1:1 crop that goes with it is applied on upload.
    $thumbShape = $field->isAvatar() ? 'rounded-full' : 'rounded-sm';
    $imageConfig = $field->getImageProcessingConfig();
    $assetVersion = @filemtime(WireFormsServiceProvider::ASSETS_PATH.'/wire-forms-image.js') ?: null;
    $assetUrl = $processesImages
        ? route('wire-forms.asset', ['asset' => 'image']).($assetVersion ? '?id='.$assetVersion : '')
        : null;
@endphp

@if($processesImages)
    {{-- Pre-bundled, injected via @assets so it also runs for a field rendered
         into a Livewire-loaded modal, where a morphed <script> would not. --}}
    @assets
    <script src="{{ $assetUrl }}"></script>
    @endassets
@endif

@include('wire-forms::partials.field-assets')

@include('wire-forms::partials.field-wrapper-start')

<div
        @if($processesImages)
            {{-- Crop/resize before Livewire uploads: the shared component owns the
                 picker → process → hand to wire:model flow. --}}
            x-data="wireImageUpload(@js($imageConfig))"
        @else
            {{-- Body registered as `wireFileDropzone`; see image-processor.js for
                 the processing counterpart above. --}}
            x-data="wireFileDropzone()"
        @endif
        class="space-y-2"
>
    {{-- One picture of one person is not a drop target with a file list under
         it. `avatar()` gets a round preview and a button; everything else
         keeps the dropzone, and both hold the same inputs inside the same
         Alpine root — what changes is where you click, not what happens
         then. --}}
    @if($field->isAvatar())
        @include('wire-forms::partials.file-upload-avatar')
    @else
        {{-- Drop zone --}}
        <div
                @click="openPicker()" data-testid="form-file-{{ $field->getStatePath() }}-dropzone"
                @dragover.prevent="isDragging = true"
                @dragleave.prevent="isDragging = false"
                @drop.prevent="handleDrop($event)"
                :class="{
                'border-primary-500 bg-primary-50 dark:bg-primary-900/10': isDragging,
                'border-gray-300 dark:border-gray-600': !isDragging,
            }"
                @class([
                    'relative flex flex-col items-center justify-center px-6 py-8 border-2 border-dashed rounded-lg cursor-pointer',
                    'hover:border-gray-400 dark:hover:border-gray-500 transition-colors duration-150',
                    'bg-white dark:bg-gray-800',
                    'border-red-500' => $errors->has($field->getStatePath()),
                ])
        >
            <div class="pointer-events-none text-center" wire:loading.remove
                 wire:target="{{ $field->getWireModelAttribute() }}">
                {!! icon('outline:arrow-up-tray', 'w-4 h-4', 'mx-auto h-10 w-10 text-gray-500 dark:text-gray-400', '', [':class' => "{ 'text-primary-500': isDragging }"]) !!}
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                    <span class="font-medium text-primary-600 dark:text-primary-400">{{ __('Click to upload') }}</span>
                    {{ __('or drag and drop') }}
                </p>
                @if(!empty($acceptedTypes) || $maxSize)
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if(!empty($acceptedTypes))
                            {{ implode(', ', array_map(fn($t) => ltrim($t, '.'), $acceptedTypes)) }}
                        @endif
                        @if($maxSize)
                            {{ !empty($acceptedTypes) ? ' — ' : '' }}{{ __('max') }} {{ $maxSize >= 1024 ? round($maxSize / 1024, 1) . ' MB' : $maxSize . ' KB' }}
                        @endif
                    </p>
                @endif
            </div>

            {{-- Loading indicator --}}
            <div class="pointer-events-none text-center" wire:loading wire:target="{{ $field->getWireModelAttribute() }}">
                @include('wire-core::partials.spinner', ['class' => 'mx-auto h-8 w-8 text-primary-500'])
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Uploading...') }}</p>
            </div>

            @include('wire-forms::partials.file-upload-inputs')
        </div>
        {{-- Files in the field state: stored paths + pending uploads (store-on-submit) --}}
        @if (! empty($filePreviews))
            <ul class="space-y-2">
                @foreach ($filePreviews as $preview)
                    <li class="flex items-center gap-3 rounded-lg border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 p-3">
                        {{-- The picture when the field deals in pictures, and
                             otherwise the file's own extension over its family's
                             colour — one grey document icon told a contract, a
                             price list and a print archive apart not at all.
                             See ADR 0033. --}}
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden {{ $thumbShape }}">
                            {{-- The canonical FileThumb, rendered as an object:
                                 rule 5 keeps `<x-wire::file-thumb>` for
                                 consumers and has the framework draw without
                                 the tag. Same class, same view. --}}
                            {!! \NyonCode\WireCore\Foundation\View\ComponentRenderer::render(
                                new \NyonCode\WireCore\Foundation\View\FileThumb(
                                    name: $preview['name'],
                                    url: $preview['isImage'] ? $preview['url'] : null,
                                    size: 'md',
                                ),
                            ) !!}
                        </span>

                        <div class="flex-1 min-w-0">
                            @if ($preview['stored'] && $preview['url'])
                                <a href="{{ $preview['url'] }}" target="_blank" rel="noopener"
                                   class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 truncate block">
                                    {{ $preview['name'] }}
                                </a>
                            @else
                                <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">{{ $preview['name'] }}</p>
                            @endif
                            @unless ($preview['stored'])
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Pending upload') }}</p>
                            @endunless
                        </div>

                        @if ($canDelete)
                            <button
                                    type="button"
                                    wire:click="removeUploadedFile('{{ $field->getStatePath() }}', {{ (int) $preview['index'] }})" data-testid="form-file-{{ $field->getStatePath() }}-remove-{{ (int) $preview['index'] }}"
                                    class="shrink-0 p-1 text-gray-500 hover:text-red-500 dark:hover:text-red-400 transition-colors duration-150"
                            >
                                {!! icon('x-mark', 'w-4 h-4', 'h-4 w-4') !!}
                                <span class="sr-only">{{ __('Remove') }}</span>
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endif


    @if($processesImages && $field->cropsInteractively())
        {{-- Crop frame. Deliberately not a library: the canvas pipeline already
             crops and downscales, so all that was missing was letting the user place
             the frame — a drag, not a 50 kB dependency. --}}
        <div
                x-show="cropping"
                x-cloak
                @keydown.escape.window="cancelCrop()"
                {{-- It covers the page, so it is a dialog and has to behave like
                     one: announced as such, and holding the focus. Without the
                     trap the focus stayed on <body> when it opened and Tab
                     reached the file picker *behind* the overlay. --}}
                role="dialog"
                aria-modal="true"
                aria-labelledby="crop-title-{{ $field->getStatePath() }}"
                @include('wire-core::modals.partials.focus-trap', ['openExpression' => 'cropping'])
                class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/70 p-4"
                data-testid="form-file-{{ $field->getStatePath() }}-cropper"
        >
            <div class="w-full max-w-lg rounded-lg bg-white p-4 shadow-xl dark:bg-gray-800" @click.stop>
                <p id="crop-title-{{ $field->getStatePath() }}" class="mb-3 text-sm font-medium text-gray-900 dark:text-white">{{ __('Choose the visible area') }}</p>

                <div
                        class="relative select-none overflow-hidden rounded-sm"
                        @pointermove="onDrag($event)"
                        @pointerup.window="endDrag()"
                        @pointercancel.window="endDrag()"
                >
                    <img
                            x-ref="cropImage"
                            :src="cropUrl"
                            @load="fitFrame()"
                            alt=""
                            class="block max-h-[60vh] w-full object-contain"
                            draggable="false"
                    />

                    {{-- Everything outside the frame is dimmed by the frame's own
                         huge shadow, so no four-box overlay is needed. --}}
                    <div
                            x-show="frame.width > 0"
                            @pointerdown="startDrag($event)"
                            data-testid="form-file-{{ $field->getStatePath() }}-crop-frame"
                            class="absolute cursor-move border-2 border-white shadow-[0_0_0_9999px_rgba(0,0,0,0.5)] {{ $field->isAvatar() ? 'rounded-full' : '' }}"
                            :style="`left:${frame.left}px; top:${frame.top}px; width:${frame.width}px; height:${frame.height}px`"
                    ></div>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" @click="cancelCrop()"
                            data-testid="form-file-{{ $field->getStatePath() }}-crop-cancel"
                            class="rounded-md px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ __('Cancel') }}
                    </button>
                    <button type="button" @click="confirmCrop()"
                            data-testid="form-file-{{ $field->getStatePath() }}-crop-confirm"
                            class="rounded-md bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        {{ __('Crop') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

@include('wire-forms::partials.field-wrapper-end')
