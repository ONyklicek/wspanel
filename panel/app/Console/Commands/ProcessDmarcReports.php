<?php

namespace App\Console\Commands;

use App\Services\Mail\DmarcReports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:dmarc')]
#[Description('Store the DMARC aggregate reports Postfix left in the spool; delete old ones')]
class ProcessDmarcReports extends Command
{
    public function handle(DmarcReports $reports): int
    {
        $done = $reports->processSpool(storage_path('app/dmarc-incoming'));
        $pruned = $reports->prune();
        $this->info("{$done['reports']} reports, {$done['ignored']} ignored, {$pruned} pruned");

        return self::SUCCESS;
    }
}
