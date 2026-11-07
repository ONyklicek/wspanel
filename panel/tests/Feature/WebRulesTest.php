<?php

namespace Tests\Feature;

use App\Actions\Sites\ManageWebRules;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WebRulesTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create(['domain' => 'rules.example.com', 'mode' => 'classic', 'framework' => 'php', 'php_version' => '8.4', 'status' => 'active', 'system_user' => 'web_rules_example_com']);
    }

    public function test_rules_go_to_the_agent_and_are_remembered(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('site.directives', ['domain' => 'rules.example.com', 'directives' => 'encode gzip'])
            ->andReturn(['directives' => 'encode gzip']);
        $this->app->instance(AgentClient::class, $agent);
        $site = $this->site();

        app(ManageWebRules::class)->set($site, 'encode gzip');

        $this->assertSame('encode gzip', $site->fresh()->web_rules);
    }

    public function test_a_refused_directive_is_explained_in_czech(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(new AgentException('invalid_params', 'directives: line 2: directive "reverse_proxy" is not allowed (allowed: header)', 'site.directives'));
        $this->app->instance(AgentClient::class, $agent);
        $site = $this->site();

        try {
            app(ManageWebRules::class)->set($site, "encode gzip\nreverse_proxy 127.0.0.1:8025");
            $this->fail('expected a refusal');
        } catch (AgentException $e) {
            $this->assertStringStartsWith('Řádek 2: direktivu „reverse_proxy“ v pravidlech webu použít nejde.', $e->getMessage());
        }
        $this->assertNull($site->fresh()->web_rules);
    }
}
