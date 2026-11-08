<?php

namespace App\Http\Controllers;

use App\Actions\Mail\ManageMailHosting;
use App\Models\Mailbox;
use Illuminate\Http\Response;

class AppleMailProfileController
{
    /** The mailbox's settings for iPhone, iPad and Mac Mail (no password inside). */
    public function __invoke(Mailbox $mailbox): Response
    {
        return response(ManageMailHosting::appleProfile($mailbox), 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="'.$mailbox->address().'.mobileconfig"',
        ]);
    }
}
