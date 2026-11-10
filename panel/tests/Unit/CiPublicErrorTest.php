<?php

namespace Tests\Unit;

use App\Http\Controllers\CiDeployController;
use Tests\TestCase;

/** A deploy's error as a public CI log shows it. */
class CiPublicErrorTest extends TestCase
{
    public function test_credentials_and_server_paths_stay_out_of_a_ci_log(): void
    {
        $error = "git clone https://x-access-token:ghs_secret@github.com/acme/app.git /srv/sites/app.example.com/releases/20261110T204325Z failed\nstack trace line";

        $public = CiDeployController::publicError($error);

        $this->assertStringNotContainsString('ghs_secret', $public);
        $this->assertStringNotContainsString('/srv/sites', $public);
        $this->assertStringNotContainsString('stack trace', $public);
        $this->assertStringContainsString('https://***@github.com/acme/app.git', $public);
        $this->assertNull(CiDeployController::publicError(''));
    }
}
