<?php

/**
 * WSPanel driver for Roundcube's password plugin: the mailbox changes its own
 * password through the panel (POST /internal/webmail/password over the
 * loopback, with the key only the webmail and the panel can read). The panel
 * has the agent check the current password and store the new one hashed.
 */
class rcube_wspanel_password
{
    public function save($curpass, $passwd, $username)
    {
        $rcmail = rcmail::get_instance();
        $host = (string) $rcmail->config->get('wspanel_panel_host');
        $port = (int) $rcmail->config->get('wspanel_panel_port', 443);
        $key = (string) $rcmail->config->get('wspanel_panel_key');
        $address = strtolower((string) $username);
        if ($host === '' || $key === '' || strpos($address, '@') === false) {
            return PASSWORD_ERROR;
        }

        $body = $this->post($host, $port, $key, ['address' => $address, 'current' => $curpass, 'new' => $passwd], $status, $errno);
        // A panel on the server's internal CA (no public certificate): verify
        // against that CA's root, which the mail hosting install copies out.
        $root = '/etc/roundcube/wspanel-edge-root-ca.crt';
        if (in_array($errno, [51, 60], true) && is_readable($root)) { // the peer certificate was not verified
            $body = $this->post($host, $port, $key, ['address' => $address, 'current' => $curpass, 'new' => $passwd], $status, $errno, $root);
        }

        if ($body === false || $status === 0) {
            return PASSWORD_CONNECT_ERROR;
        }
        if ($status === 200) {
            return PASSWORD_SUCCESS;
        }
        $error = (string) (json_decode((string) $body, true)['error'] ?? '');
        if ($error === 'wrong_password') {
            return ['code' => PASSWORD_ERROR, 'message' => $rcmail->gettext('password.passwordincorrect')];
        }
        if ($error === 'too_many_attempts') {
            return ['code' => PASSWORD_ERROR, 'message' => 'Příliš mnoho pokusů, zkuste to za 15 minut.'];
        }

        return PASSWORD_ERROR;
    }

    /** @return string|false the response body */
    private function post($host, $port, $key, array $data, &$status, &$errno, $caFile = null)
    {
        $ch = curl_init('https://'.$host.($port === 443 ? '' : ':'.$port).'/internal/webmail/password');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer '.$key],
            // The panel is on this server: never let DNS send the passwords elsewhere.
            CURLOPT_RESOLVE => [$host.':'.$port.':127.0.0.1'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($caFile !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $body;
    }
}
