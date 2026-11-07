<?php

namespace Tests\Feature;

use App\Filament\Pages\Help;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HelpTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_documentation_page_renders_with_working_links(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (array_keys(Help::PAGES) as $slug) {
            $this->assertFileExists(Help::docsPath()."/{$slug}.md");
            $html = (string) Livewire::test(Help::class, ['doc' => $slug])->instance()->renderedDoc();
            $this->assertStringNotContainsString('.md"', $html, "{$slug}: link to a .md file was not rewritten");
            $this->assertStringContainsString('<h1', $html);
        }
    }

    public function test_unknown_page_falls_back_to_the_overview(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/help?doc=../../.env')->assertOk()->assertSee('Pro koho je co');
    }
}
