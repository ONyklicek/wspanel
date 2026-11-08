<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\Response;

class OpenWebmailController
{
    /**
     * Signs the mailbox in to its domain's webmail: the agent writes a
     * one-time token, this page POSTs it there (never in a URL or a log).
     * The mailbox is found through the owner scope: another client's is a 404.
     */
    public function __invoke(AgentClient $agent, Mailbox $mailbox): Response
    {
        try {
            $login = $agent->call('mail.webmail.login', ['address' => $mailbox->address()]);
        } catch (AgentException $e) {
            return response()->view('dbtools.error', ['message' => $e->getMessage()], 502);
        }

        return response()
            ->view('mail.webmail-open', ['action' => $login['url'], 'token' => $login['token'], 'address' => $mailbox->address()])
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
