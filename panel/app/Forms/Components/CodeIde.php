<?php

declare(strict_types=1);

namespace App\Forms\Components;

use NyonCode\WireForms\Components\Field;

/**
 * A code editor like in an IDE (CodeMirror 6): syntax highlighting, line
 * numbers, folding, search/replace, multiple cursors; "Format" through
 * Prettier for PHP, JS, CSS, HTML, JSON, YAML and Markdown.
 */
class CodeIde extends Field
{
    protected string $language = 'plaintext';

    protected int $minHeight = 480;

    public function language(string $language): static
    {
        $this->language = $language;

        return $this;
    }

    public function minHeight(int $pixels): static
    {
        $this->minHeight = $pixels;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getMinHeight(): int
    {
        return $this->minHeight;
    }

    public function getStateType(): string
    {
        return 'string';
    }

    protected function viewName(): string
    {
        return 'forms.components.code-ide';
    }
}
