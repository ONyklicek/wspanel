<?php

namespace App\Http\Controllers;

use App\Actions\Mail\ManageMailHosting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A mailbox changes its own password from the webmail (Roundcube's password
 * plugin, WSPanel driver). Only the webmail may ask: from the server itself,
 * with the key in /srv/panel/shared/webmail-api.key (readable by the webmail
 * and the panel only). The agent checks the current password against the
 * mailbox's hash; the new one leaves the panel hashed, as everywhere.
 *
 * Outside the web group: no session, no CSRF (the caller is a server process).
 */
class WebmailPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = $this->key();
        $given = (string) $request->bearerToken();
        if ($key === null || $given === '' || ! hash_equals($key, $given) || ! in_array($request->ip(), ['127.0.0.1', '::1'], true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }
        $data = $request->validate([
            'address' => ['required', 'string', 'max:254', 'email'],
            'current' => ['required', 'string', 'max:200'],
            'new' => ['required', 'string', 'min:10', 'max:200', 'different:current'],
        ]);
        $address = strtolower($data['address']);
        $throttle = 'webmail-password:'.$address;
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            return response()->json(['error' => 'too_many_attempts'], 429);
        }
        try {
            app(AgentClient::class)->call('mail.mailbox.password', [
                'address' => $address,
                'current_password' => $data['current'],
                'password_hash' => ManageMailHosting::hash($data['new']),
            ]);
        } catch (AgentException $e) {
            RateLimiter::hit($throttle, 900);

            return response()->json(['error' => $e->errorCode === 'forbidden' ? 'wrong_password' : 'failed'], $e->errorCode === 'forbidden' ? 403 : 500);
        }
        RateLimiter::clear($throttle);

        return response()->json(['ok' => true]);
    }

    private function key(): ?string
    {
        $file = (string) config('panel.webmail_api_key_file');
        $key = $file !== '' && is_readable($file) ? trim((string) file_get_contents($file)) : '';

        return strlen($key) >= 32 ? $key : null;
    }
}
