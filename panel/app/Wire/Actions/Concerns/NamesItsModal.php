<?php

namespace App\Wire\Actions\Concerns;

/**
 * A modal says what it does: without a heading of its own it is headed by
 * the action's name ("Zálohovat teď", not "Potvrdit akci"), and its button
 * does it ("Smazat", "Aktualizovat"; settings "Uložit", a new thing
 * "Vytvořit") rather than "Potvrdit".
 */
trait NamesItsModal
{
    public function getModalHeading(mixed $context = null): string
    {
        $heading = parent::getModalHeading($context);
        if ($heading !== __('wire-core::actions.confirm_heading')) {
            return $heading;
        }

        return rescue(fn () => (string) $this->getLabel($context), $heading, report: false) ?: $heading;
    }

    public function getModalSubmitActionLabel(): string
    {
        $label = parent::getModalSubmitActionLabel();
        if ($label !== __('wire-core::actions.confirm_submit')) {
            return $label;
        }
        $name = $this->getName();
        if ($this->hasFormModal()) {
            return match (true) {
                (bool) preg_match('/^(create|add|new)/i', $name) => __('Vytvořit'),
                (bool) preg_match('/^install/i', $name) => __('Nainstalovat'),
                // An operation does what it says ("Aktualizovat", "Obnovit", "Rozbalit sem").
                (bool) preg_match('/^(upgrade|update|import|upload|restore|rollback|reboot|extract|compress|mount|assign|switch|staging|delete|drop|remove|sftp|run|send|copy|move|generate)/i', $name) => rescue(fn () => (string) $this->getLabel(), __('Uložit'), report: false) ?: __('Uložit'),
                // Anything else is settings: saved.
                default => __('Uložit'),
            };
        }

        // A confirmation: the action itself ("Smazat", "Restartovat"); a label
        // that needs a record stays "Potvrdit".
        return rescue(fn () => (string) $this->getLabel(), $label, report: false) ?: $label;
    }
}
