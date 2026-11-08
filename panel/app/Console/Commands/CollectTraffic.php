<?php

namespace App\Console\Commands;

use App\Actions\Monitoring\SiteTraffic;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:traffic')]
#[Description('Take in the sites\' traffic from the edge\'s access logs; prune old traffic and uptime history')]
class CollectTraffic extends Command
{
    public function handle(SiteTraffic $traffic): int
    {
        $this->info($traffic->collectAll().' sites');

        return self::SUCCESS;
    }
}
