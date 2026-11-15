<?php

namespace Tests\Unit;

use App\Services\Agent\AgentException;
use App\Support\AgentMessages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AgentMessagesTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function messages(): array
    {
        return [
            'rolled back' => ['the new release did not answer; switched back', 'Nová verze neodpovídala, web běží dál na předchozí.'],
            'deploy step' => ['Composer failed', 'Krok „Composer“ selhal. Podrobnosti jsou v logu deploye.'],
            'git access' => ['git ls-remote failed: git@github.com: Permission denied (publickey).', 'Repozitář se nepodařilo zkontrolovat: server nemá do repozitáře přístup. Přidejte deploy klíč do Gitu (Deploy → Další → Deploy klíč).'],
            'nested' => ['rollback failed: site shop.example.com does not exist', 'Rollback selhal: Web shop.example.com na serveru neexistuje.'],
            'engine' => ['postgresql is not installed on this server', 'PostgreSQL není na serveru nainstalovaná.'],
            'health' => ['Get "https://a.example.com/": context deadline exceeded (Client.Timeout exceeded while awaiting headers)', 'Vypršel časový limit, web neodpověděl včas.'],
            'command' => ['systemctl: exit status 1', 'Příkaz systemctl skončil chybou (kód 1).'],
            'english tail dropped' => ['port 22 is protected (SSH/HTTP/HTTPS); change it on the server directly', 'Port 22 je chráněný (SSH/HTTP/HTTPS), z panelu ho nejde zavřít.'],
            'games memory budget' => ['memory_mb: the game server would reserve 6256 MB; this server has 8000 MB of RAM and keeps 2000 MB for the sites, databases and the panel, so a game server may use at most 6000 MB', 'Herní server by si vyhradil 6256 MB. Server má 8000 MB RAM a 2000 MB drží pro weby, databáze a panel, jeden herní server tedy smí mít nejvýš 6000 MB. Snižte jeho paměť, nebo ve Zdrojích zmenšete rezervu pro weby.'],
            'games start over the budget' => ['memory: this game server needs 2816 MB; the running ones (Create 5318 MB, Lobby 1536 MB) take 6854 MB of the 8000 MB game servers may use (1146 MB free): stop one of them first', 'Herní server potřebuje 2816 MB, ale běžící servery (Create 5318 MB, Lobby 1536 MB) zabírají 6854 MB z 8000 MB, které herní servery smějí použít (volných 1146 MB). Nejdřív některý z nich zastavte.'],
            'unknown stays' => ['something new the agent says', 'something new the agent says'],
            'czech stays' => ['Agent není dostupný (/run/panel-agent.sock): No such file', 'Agent není dostupný (/run/panel-agent.sock): No such file'],
        ];
    }

    #[DataProvider('messages')]
    public function test_agent_messages_read_in_czech(string $english, string $czech): void
    {
        $this->assertSame($czech, AgentMessages::cs($english));
    }

    public function test_the_exception_keeps_the_agents_text(): void
    {
        $e = new AgentException('conflict', 'database shop already exists', 'db.create');

        $this->assertSame('Databáze shop už existuje.', $e->getMessage());
        $this->assertSame('database shop already exists', $e->raw);
    }
}
