<?php

namespace Tests\Feature;

use App\Livewire\Pages\HelpPage as Help;
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

    public function test_pictures_of_the_guide_are_shown_and_only_from_the_docs_folder(): void
    {
        $this->actingAs(User::factory()->create());

        $html = (string) Livewire::test(Help::class, ['doc' => 'github'])->instance()->renderedDoc();
        preg_match_all('/<img src="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1], 'the GitHub guide has screenshots');
        foreach ($m[1] as $src) {
            $this->assertStringStartsWith(url('/help/img/'), html_entity_decode($src));
            $this->get(html_entity_decode($src))->assertOk()->assertHeader('Content-Type', 'image/png');
        }
        foreach (['..%2F..%2F.env', '.env', 'x.php', 'missing.png'] as $bad) {
            $this->get('/help/img/'.$bad)->assertNotFound();
        }
    }

    public function test_unknown_page_falls_back_to_the_overview(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/help?doc=../../.env')->assertOk()->assertSee('Pro koho je co');
    }
}
