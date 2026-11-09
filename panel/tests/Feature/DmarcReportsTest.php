<?php

namespace Tests\Feature;

use App\Actions\Mail\ManageMail;
use App\Livewire\Mail\DmarcReportTable;
use App\Models\Client;
use App\Models\DmarcRecord;
use App\Models\DmarcReport;
use App\Models\MailDomain;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Mail\DmarcReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class DmarcReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
    }

    private function report(string $domain = 'firma.cz', string $id = 'r-1'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<feedback>
  <report_metadata><org_name>google.com</org_name><email>noreply-dmarc-support@google.com</email>
    <report_id>{$id}</report_id><date_range><begin>1794009600</begin><end>1794096000</end></date_range></report_metadata>
  <policy_published><domain>{$domain}</domain><p>quarantine</p></policy_published>
  <record><row><source_ip>203.0.113.10</source_ip><count>40</count>
      <policy_evaluated><disposition>none</disposition><dkim>pass</dkim><spf>pass</spf></policy_evaluated></row>
    <identifiers><header_from>{$domain}</header_from></identifiers>
    <auth_results><dkim><domain>{$domain}</domain><result>pass</result></dkim><spf><domain>{$domain}</domain><result>pass</result></spf></auth_results></record>
  <record><row><source_ip>198.51.100.7</source_ip><count>3</count>
      <policy_evaluated><disposition>quarantine</disposition><dkim>fail</dkim><spf>fail</spf></policy_evaluated></row>
    <identifiers><header_from>{$domain}</header_from></identifiers>
    <auth_results><spf><domain>spammer.example</domain><result>fail</result></spf></auth_results></record>
</feedback>
XML;
    }

    /** A message as a reporting server sends it: the report as a base64 attachment. */
    private function message(string $attachment, string $type, string $name): string
    {
        return "From: noreply-dmarc-support@google.com\r\nTo: dmarc-reports@firma.cz\r\nSubject: Report domain: firma.cz\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n\r\n"
            ."--b1\r\nContent-Type: text/plain\r\n\r\nThis is an aggregate report.\r\n"
            ."--b1\r\nContent-Type: {$type}; name=\"{$name}\"\r\nContent-Transfer-Encoding: base64\r\n"
            ."Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
            .chunk_split(base64_encode($attachment), 76, "\r\n")
            ."--b1--\r\n";
    }

    public function test_a_gzipped_report_is_stored_with_its_sources(): void
    {
        $domain = MailDomain::create(['domain' => 'firma.cz']);

        $stored = app(DmarcReports::class)->receive($this->message(gzencode($this->report()), 'application/gzip', 'google.com!firma.cz!1!2.xml.gz'));

        $this->assertSame(1, $stored);
        $report = DmarcReport::query()->sole();
        $this->assertSame($domain->id, $report->mail_domain_id);
        $this->assertSame(['google.com', 43, 3, 'quarantine'], [$report->org_name, $report->messages, $report->failed, $report->policy]);
        $failing = DmarcRecord::query()->where('source_ip', '198.51.100.7')->sole();
        $this->assertSame(['fail', 'fail', 'quarantine', 'spammer.example'], [$failing->dkim, $failing->spf, $failing->disposition, $failing->spf_domain]);
        $this->assertFalse($failing->passed());
    }

    public function test_a_zipped_report_is_stored_once(): void
    {
        MailDomain::create(['domain' => 'firma.cz']);
        $zipFile = tempnam(sys_get_temp_dir(), 'z');
        $zip = new ZipArchive;
        $zip->open($zipFile, ZipArchive::OVERWRITE);
        $zip->addFromString('report.xml', $this->report());
        $zip->close();
        $message = $this->message((string) file_get_contents($zipFile), 'application/zip', 'report.zip');
        unlink($zipFile);

        $this->assertSame(1, app(DmarcReports::class)->receive($message));
        $this->assertSame(0, app(DmarcReports::class)->receive($message), 'the same report again is not stored twice');
        $this->assertSame(1, DmarcReport::query()->count());
    }

    public function test_what_is_not_a_report_of_a_hosted_domain_is_dropped(): void
    {
        MailDomain::create(['domain' => 'firma.cz']);
        $reports = app(DmarcReports::class);

        $this->assertSame(0, $reports->receive($this->message($this->report('cizi.cz'), 'text/xml', 'r.xml')), 'a domain not hosted here');
        $this->assertSame(0, $reports->receive("Subject: hello\r\n\r\nJust a message."), 'not a report');
        $xxe = '<?xml version="1.0"?><!DOCTYPE feedback [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.substr($this->report(), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
        $this->assertSame(0, $reports->receive($this->message($xxe, 'text/xml', 'r.xml')), 'a DOCTYPE (entities) is refused');
        $this->assertSame(0, DmarcReport::query()->count());
    }

    public function test_the_command_empties_the_spool_and_prunes_old_reports(): void
    {
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        $old = DmarcReport::create(['mail_domain_id' => $domain->id, 'org_name' => 'x', 'report_id' => 'old',
            'begins_at' => now()->subDays(200), 'ends_at' => now()->subDays(199)]);
        $spool = storage_path('app/dmarc-incoming');
        File::ensureDirectoryExists($spool);
        file_put_contents("$spool/1.1.eml", $this->message(gzencode($this->report()), 'application/gzip', 'r.xml.gz'));
        file_put_contents("$spool/2.2.eml", "Subject: spam\r\n\r\nnot a report");

        $this->artisan('panel:dmarc')->expectsOutputToContain('1 reports, 1 ignored, 1 pruned')->assertSuccessful();

        $this->assertSame([], glob("$spool/*.eml"));
        $this->assertNull(DmarcReport::query()->find($old->id));
        $this->assertSame(1, DmarcReport::query()->count());
    }

    public function test_a_client_sees_only_the_reports_of_its_own_domains(): void
    {
        $client = Client::factory()->create();
        MailDomain::create(['domain' => 'firma.cz']);
        MailDomain::create(['domain' => 'klient.cz', 'client_id' => $client->id]);
        app(DmarcReports::class)->receive($this->message($this->report(), 'text/xml', 'a.xml'));
        app(DmarcReports::class)->receive($this->message($this->report('klient.cz', 'r-2'), 'text/xml', 'b.xml'));

        $this->assertSame($client->id, DmarcReport::withoutGlobalScopes()->where('report_id', 'r-2')->value('client_id'));
        $this->actingAs(User::factory()->forClient($client)->create());
        $this->assertSame(['r-2'], DmarcReport::query()->pluck('report_id')->all());
    }

    public function test_the_mail_domain_page_shows_the_sources(): void
    {
        $domain = MailDomain::create(['domain' => 'firma.cz']);
        app(DmarcReports::class)->receive($this->message($this->report(), 'text/xml', 'a.xml'));
        $this->actingAs(User::factory()->create());

        Livewire::test(DmarcReportTable::class, ['domain' => $domain->id])
            ->assertSee('198.51.100.7')->assertSee('203.0.113.10')->assertSee('google.com')
            ->assertSee('3 neprošlo');
    }

    public function test_the_recommended_dmarc_record_sends_reports_to_the_panel_for_hosted_domains(): void
    {
        MailDomain::create(['domain' => 'firma.cz']);

        $hosted = collect(ManageMail::dnsRecords('firma.cz', null, null, null))->firstWhere('name', '_dmarc.firma.cz');
        $other = collect(ManageMail::dnsRecords('jinde.cz', null, null, null))->firstWhere('name', '_dmarc.jinde.cz');

        $this->assertStringContainsString('rua=mailto:dmarc-reports@firma.cz', $hosted['value']);
        $this->assertStringContainsString('rua=mailto:postmaster@jinde.cz', $other['value']);
    }
}
