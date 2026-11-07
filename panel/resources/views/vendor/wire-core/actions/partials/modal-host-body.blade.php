{{-- Body of the action modal-host: optional wizard step indicator + the form or
     infolist instance. Expects $component, $isWizard, $wizardSteps,
     $currentStep, $actionFormInstance, $actionInfolistInstance in scope. --}}
@if($isWizard)
    <ol class="mb-6 flex items-center gap-2" aria-label="{{ __('Steps') }}">
        @foreach($wizardSteps as $index => $step)
            @php
                $isCurrent = $index === $currentStep;
                $isDone = $index < $currentStep;
            @endphp
            <li @class(['flex items-center gap-2', 'flex-1' => ! $loop->last])>
                <span @class([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                    'bg-primary-600 text-white' => $isCurrent,
                    'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-200' => $isDone,
                    'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => ! $isCurrent && ! $isDone,
                ])>{{ $index + 1 }}</span>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $step['label'] ?? '' }}</span>
                @unless($loop->last)
                    <span class="h-px flex-1 bg-gray-200 dark:bg-gray-600"></span>
                @endunless
            </li>
        @endforeach
    </ol>
@endif

@if($actionFormInstance)
    {{ $actionFormInstance }}
@elseif($actionInfolistInstance)
    {{ $actionInfolistInstance }}
@endif
