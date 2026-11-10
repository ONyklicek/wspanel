<?php

namespace App\Actions\Games;

use App\Enums\Game;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\AgentMessages;
use Illuminate\Support\Facades\Cache;

/**
 * Game packages (Server → Herní balíčky): which games and variants game
 * servers can run. Built-in ones, WSPanel's signed catalog, the
 * administrator's own; the agent checks every manifest.
 */
class ManageGamePackages
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @return array{packages: list<array<string, mixed>>, problems: list<array<string, string>>, catalog?: list<array<string, mixed>>, catalog_error?: string}
     *
     * @throws AgentException
     */
    public function list(bool $catalog = false): array
    {
        $out = $this->agent->call('games.packages', $catalog ? ['catalog' => true] : []);
        // Not an exception: the agent's words, in the panel's language.
        if (isset($out['catalog_error'])) {
            $out['catalog_error'] = AgentMessages::cs((string) $out['catalog_error']);
        }

        return $out;
    }

    /** @throws AgentException */
    public function install(string $id): array
    {
        return $this->changed($this->agent->call('games.package_install', ['id' => $id]));
    }

    /** The administrator's own package (a manifest). @throws AgentException */
    public function upload(string $manifest): array
    {
        return $this->changed($this->agent->call('games.package_upload', ['manifest' => $manifest]));
    }

    /** @throws AgentException */
    public function remove(string $id): array
    {
        return $this->changed($this->agent->call('games.package_remove', ['id' => $id]));
    }

    /**
     * Games and variants changed: what the panel knows of them is asked again.
     *
     * @param  array<string, mixed>  $out
     * @return array<string, mixed>
     */
    private function changed(array $out): array
    {
        Cache::forget('games.catalog');
        Game::flush();

        return $out;
    }
}
