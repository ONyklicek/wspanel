<?php

namespace App\Livewire\Concerns;

/**
 * A page's menu label and heading live in static properties, which cannot
 * call __(): translated when they are read (the Czech text is the key).
 */
trait TranslatesPageText
{
    public static function label(): string
    {
        return __(parent::label());
    }

    public function getTitle(): ?string
    {
        $title = parent::getTitle();

        return $title === null ? null : __($title);
    }
}
