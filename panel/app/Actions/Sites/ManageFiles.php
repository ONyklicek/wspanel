<?php

namespace App\Actions\Sites;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The file manager. The agent does the work as the site user, confined to the
 * site's home; uploads and downloads pass through the agent's artifact dir.
 */
class ManageFiles
{
    /** What the editor opens (the agent refuses more anyway). */
    public const MAX_EDIT = 2 * 1024 * 1024;

    /** What one edit may save (a socket request is capped at 1 MB). */
    public const MAX_SAVE = 900 * 1024;

    public function __construct(private readonly AgentClient $agent) {}

    /** @return array<string, mixed> @throws AgentException */
    private function call(Site $site, array $params): array
    {
        return $this->agent->call('site.files', ['domain' => $site->domain] + $params);
    }

    /** @return list<array{name: string, type: string, size: int, mtime: int, mode: string, target?: string}> */
    public function list(Site $site, string $path): array
    {
        return $this->call($site, ['action' => 'list', 'path' => $path])['entries'] ?? [];
    }

    /** @return array{content: string, mtime: int, size: int} */
    public function read(Site $site, string $path): array
    {
        $out = $this->call($site, ['action' => 'read', 'path' => $path]);

        return ['content' => (string) ($out['content'] ?? ''), 'mtime' => (int) ($out['mtime'] ?? 0), 'size' => (int) ($out['size'] ?? 0)];
    }

    public function write(Site $site, string $path, string $content, ?int $ifMtime = null): void
    {
        if (strlen($content) > self::MAX_SAVE) {
            throw new AgentException('invalid_params', __('Soubor je na uložení z prohlížeče příliš velký (max. 900 kB), použijte SFTP.'), 'site.files');
        }
        $this->call($site, array_filter(['action' => 'write', 'path' => $path, 'content' => $content, 'if_mtime' => $ifMtime], fn ($v) => $v !== null));
    }

    public function mkdir(Site $site, string $path): void
    {
        $this->call($site, ['action' => 'mkdir', 'path' => $path]);
    }

    public function rename(Site $site, string $path, string $to): void
    {
        $this->call($site, ['action' => 'rename', 'path' => $path, 'to' => $to]);
    }

    public function delete(Site $site, string $path): void
    {
        $this->call($site, ['action' => 'delete', 'path' => $path]);
    }

    public function chmod(Site $site, string $path, string $mode): void
    {
        $this->call($site, ['action' => 'chmod', 'path' => $path, 'mode' => $mode]);
    }

    public function extract(Site $site, string $path, string $to): int
    {
        return (int) ($this->call($site, ['action' => 'extract', 'path' => $path, 'to' => $to])['count'] ?? 0);
    }

    /** @param list<string> $paths */
    public function compress(Site $site, array $paths, string $to): int
    {
        return (int) ($this->call($site, ['action' => 'compress', 'paths' => $paths, 'to' => $to])['count'] ?? 0);
    }

    /** Moves a local (uploaded) file to the agent and into the site. */
    public function upload(Site $site, string $localPath, string $path, bool $replace = false): void
    {
        $name = 'up-'.bin2hex(random_bytes(16));
        $target = $this->artifactDir().'/'.$name;
        if (! @copy($localPath, $target)) {
            throw new RuntimeException(__('Soubor se nepodařilo předat agentovi.'));
        }
        @chmod($target, 0600);
        try {
            $this->call($site, ['action' => 'upload', 'path' => $path, 'upload' => $name, 'replace' => $replace]);
        } finally {
            @unlink($target); // the agent removes it; this covers a refusal before it got there
        }
    }

    /** @return string a local file with the content (delete it after sending) */
    public function download(Site $site, string $path): string
    {
        $out = $this->call($site, ['action' => 'download', 'path' => $path]);
        $name = (string) ($out['download'] ?? '');
        if (! preg_match('/^dl-[0-9a-f]{32}$/', $name)) {
            throw new RuntimeException(__('Agent nevrátil soubor ke stažení.'));
        }

        return $this->artifactDir().'/'.$name;
    }

    private function artifactDir(): string
    {
        return rtrim((string) config('agent.artifact_dir'), '/');
    }

    /** "current/public" + "index.php" -> "current/public/index.php"; "." is the home. */
    public static function join(string $dir, string $name): string
    {
        $dir = trim($dir, '/');

        return ($dir === '' || $dir === '.') ? $name : $dir.'/'.$name;
    }

    /** One file or folder name (no slashes, no dot-only names). */
    public static function validName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && strlen($name) <= 255 && ! Str::contains($name, ['/', '\\', "\0"]);
    }

    /** Editor language hint from the extension. */
    public static function language(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'php' => 'php', 'js', 'mjs' => 'javascript', 'ts' => 'typescript', 'json' => 'json', 'css' => 'css',
            'html', 'htm', 'blade' => 'html', 'md' => 'markdown', 'yml', 'yaml' => 'yaml', 'xml' => 'xml', 'sql' => 'sql',
            'sh' => 'shell', 'env' => 'dotenv', default => str_starts_with($name, '.env') ? 'dotenv' : 'plaintext',
        };
    }
}
