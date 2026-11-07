{{-- Body of the table action modal / slide-over: optional wizard step indicator
     + the form or infolist instance. Shared by both surfaces. Rendered via the
     Htmlable Modal/SlideOver object's bodyView (Rule 5). Expects $isWizard,
     $wizardSteps, $wizardCurrentStep, $actionFormInstance, $actionInfolistInstance
     in scope (passed as bodyData). --}}
@if($isWizard)
    @include('wire-table::tables.partials.wizard-steps', [
        'steps' => $wizardSteps,
        'currentStep' => $wizardCurrentStep,
    ])
@endif

@if($actionFormInstance)
    {{ $actionFormInstance }}
@elseif($actionInfolistInstance)
    {{ $actionInfolistInstance }}
@endif
