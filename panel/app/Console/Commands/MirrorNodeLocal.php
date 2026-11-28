<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What of the panel's database belongs to this node only, kept across a
 * mirror's promotion (the agent runs it): the promotion replaces the whole
 * database with the primary's, but game servers are each node's own (never
 * copied, never applied on the other node). "export" writes this node's
 * rows before the database is replaced; "import" (after the migrations)
 * drops the other node's game servers from the new database and puts this
 * node's back, with their ids (the agent keeps their files under them).
 * A game server's client is matched by its Linux group (the same on both
 * nodes); without a match the game server has no client.
 */
#[Signature('panel:mirror-node-local {action : export|import} {path : the JSON file}')]
#[Description('Keep this node\'s own rows (game servers) across a mirror promotion')]
class MirrorNodeLocal extends Command
{
    public function handle(): int
    {
        $path = (string) $this->argument('path');

        return match ((string) $this->argument('action')) {
            'export' => $this->export($path),
            'import' => $this->import($path),
            default => $this->fail('action: export or import'),
        };
    }

    private function export(string $path): int
    {
        $rows = DB::table('game_servers')->get()->map(fn ($r) => (array) $r)->all();
        $groups = DB::table('clients')->whereIn('id', array_filter(array_column($rows, 'client_id')))->pluck('linux_group', 'id')->all();
        foreach ($rows as &$r) {
            $r['client_linux_group'] = $r['client_id'] !== null ? ($groups[$r['client_id']] ?? null) : null;
        }
        unset($r);
        $json = json_encode(['format' => 1, 'game_servers' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $json) === false || ! rename($tmp, $path)) {
            $this->error("cannot write {$path}");

            return self::FAILURE;
        }
        @chmod($path, 0600);
        $this->info(count($rows).' game servers of this node kept');

        return self::SUCCESS;
    }

    private function import(string $path): int
    {
        if (! is_file($path)) {
            $this->error("{$path} does not exist (nothing was exported)");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $rows = (array) ($data['game_servers'] ?? []);
        $columns = array_flip(DB::getSchemaBuilder()->getColumnListing('game_servers'));
        $unmatched = 0;
        DB::transaction(function () use ($rows, $columns, &$unmatched) {
            // The other node's game servers run there, not here.
            DB::table('game_servers')->delete();
            $clients = DB::table('clients')->whereNotNull('linux_group')->pluck('id', 'linux_group')->all();
            foreach ($rows as $r) {
                $group = $r['client_linux_group'] ?? null;
                $r['client_id'] = $group !== null ? ($clients[$group] ?? null) : null;
                if ($group !== null && $r['client_id'] === null) {
                    $unmatched++;
                }
                DB::table('game_servers')->insert(array_intersect_key($r, $columns));
            }
        });
        if ($unmatched > 0) {
            $this->warn("{$unmatched} game servers lost their client (no client with the same Linux group on the primary)");
        }
        $this->info(count($rows).' game servers of this node restored');

        return self::SUCCESS;
    }
}
