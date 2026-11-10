<?php

namespace App\Http\Controllers\Api;

use App\Actions\Games\ManageGamePackages;
use App\Actions\Games\ManageGameServers;
use App\Enums\Game;
use App\Models\GameServer;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * REST API v1: game servers and game packages (docs/api.md,
 * docs/openapi.yaml). Games follow the installed packages; packages are the
 * administrators' (with the game servers module on), servers everybody's
 * own (a client sees and reaches only theirs).
 */
class GamesController
{
    /** GET /games: the games and variants servers can run, with the versions each offers now. */
    public function games(ManageGameServers $servers): JsonResponse
    {
        abort_unless(Gate::allows('games'), 404);
        $versions = $servers->catalog();

        return response()->json(['data' => collect(Game::all())->map(fn (Game $g) => [
            'id' => $g->value, 'name' => $g->label(), 'engine' => $g->engine(), 'proto' => $g->proto(),
            'default_port' => $g->defaultPort(), 'min_memory_mb' => $g->minMemoryMb(), 'available' => $g->available(),
            'flavors' => collect($g->flavors())->map(fn (string $label, string $id) => [
                'id' => $id, 'label' => $label, 'package' => $g->flavorPackage($id), 'addons' => $g->addons($id),
                'versions' => array_values($versions[$g->value][$id] ?? []),
            ])->values()->all(),
        ])->values()->all()]);
    }

    /** GET /game-servers */
    public function servers(): JsonResponse
    {
        abort_unless(Gate::allows('games'), 404);

        return response()->json(['data' => GameServer::query()->orderBy('id')->get()->map(fn (GameServer $s) => $this->server($s))->all()]);
    }

    /** GET /game-servers/{id} */
    public function showServer(int $id): JsonResponse
    {
        abort_unless(Gate::allows('games'), 404);

        return response()->json(['data' => $this->server(GameServer::query()->findOrFail($id))]);
    }

    /** GET /game-packages: installed packages, and those not in effect with why. */
    public function packages(ManageGamePackages $packages): JsonResponse
    {
        return $this->agent(fn () => $packages->list(), fn (array $out) => [
            'data' => $out['packages'] ?? [], 'problems' => $out['problems'] ?? [],
        ]);
    }

    /** GET /game-packages/catalog: what WSPanel's signed catalog offers. */
    public function catalog(ManageGamePackages $packages): JsonResponse
    {
        return $this->agent(fn () => $packages->list(true), fn (array $out) => array_filter([
            'data' => $out['catalog'] ?? [], 'error' => $out['catalog_error'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /** POST /game-packages: the administrator's own package; the body is its manifest. */
    public function upload(Request $request, ManageGamePackages $packages): JsonResponse
    {
        $manifest = (string) $request->getContent();
        if ($manifest === '' || strlen($manifest) > 65536 || ! json_validate($manifest)) {
            return response()->json(['error' => 'the body is the package manifest: JSON, at most 64 KB'], 422);
        }

        return $this->agent(fn () => $packages->upload($manifest), fn (array $out) => ['data' => $out['installed'] ?? null], 201);
    }

    /** POST /game-packages/{id}/install: a package (or its newer version) from WSPanel's catalog. */
    public function install(string $id, ManageGamePackages $packages): JsonResponse
    {
        return $this->agent(fn () => $packages->install($id), fn (array $out) => ['data' => $out['installed'] ?? null], 201);
    }

    /** DELETE /game-packages/{id}: while no game server runs anything of it. */
    public function remove(string $id, ManageGamePackages $packages): JsonResponse
    {
        return $this->agent(fn () => $packages->remove($id), fn () => ['removed' => $id]);
    }

    /**
     * An agent call answered as the API answers: its refusals with their
     * HTTP status (invalid 422, conflict 409, missing 404), the rest 502.
     */
    private function agent(callable $call, callable $shape, int $status = 200): JsonResponse
    {
        abort_unless(Gate::allows('game-packages'), 404);
        try {
            return response()->json($shape($call()), $status);
        } catch (AgentException $e) {
            $code = ['invalid_params' => 422, 'conflict' => 409, 'not_found' => 404][$e->errorCode] ?? 502;

            return response()->json(['error' => $e->getMessage()], $code);
        }
    }

    /** @return array<string, mixed> */
    private function server(GameServer $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'game' => $s->game->value, 'flavor' => $s->flavor, 'version' => $s->version,
            'build' => $s->build, 'status' => $s->status, 'address' => $s->address(), 'port' => $s->port,
            'memory_mb' => $s->memory_mb, 'cpu_percent' => $s->cpu_percent,
        ];
    }
}
