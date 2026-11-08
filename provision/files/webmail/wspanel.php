<?php

/**
 * WSPanel: every mail domain has its own webmail (webmail.<domain>). The edge
 * tells which one in X-Wsp-Mail-Domain (it sets the header, a browser cannot).
 *
 *  - Sign-in with the mailbox name alone: "info" on webmail.example.com is
 *    info@example.com. A full address of another domain is refused there.
 *  - Sign-in from the panel: a one-time token written by the agent
 *    (/run/wspanel-webmail, valid 60 s, deleted on first use), POSTed by the
 *    panel's page so it never appears in a URL or an access log. The session then
 *    reaches IMAP, SMTP and ManageSieve as the webmail master user acting for
 *    the mailbox, which Dovecot allows from the loopback only.
 */
class wspanel extends rcube_plugin
{
    public $task = '.*';

    /** @var bool the current request signs in through a panel token */
    private $sso = false;

    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
        $this->add_hook('login_after', [$this, 'login_after']);
        $this->add_hook('template_object_loginform', [$this, 'loginform']);
    }

    /** The mail domain this webmail is for (set by the edge). */
    private function domain()
    {
        $d = strtolower((string) ($_SERVER['HTTP_X_WSP_MAIL_DOMAIN'] ?? ''));

        return preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $d) ? $d : '';
    }

    public function startup($args)
    {
        if ($this->domain() === '') {
            http_response_code(404);
            exit;
        }
        if ($this->sso_token() !== null) {
            $args['task'] = 'login';
            $args['action'] = 'login';
        }
        if (!empty($_SESSION['wsp_sso'])) {
            $this->act_for_mailbox();
            // Signed in from the panel: the session never knew the mailbox password.
            rcmail::get_instance()->config->set('password_disabled', 'Heslo změníte po přihlášení heslem schránky (ne z panelu), nebo ho změní správce v panelu.');
        }

        return $args;
    }

    public function authenticate($args)
    {
        $domain = $this->domain();
        if (($token = $this->sso_token()) !== null) {
            $address = $this->consume($token, $domain);
            if ($address === null) {
                $args['abort'] = true;
                $args['error'] = 'Odkaz z panelu vypršel, otevřete webmail z panelu znovu.';

                return $args;
            }
            rcmail::get_instance()->kill_session();
            $this->sso = true;
            $this->act_for_mailbox();
            $args['user'] = $address;
            $args['pass'] = bin2hex(random_bytes(16)); // unused: the master user signs in
            $args['cookiecheck'] = false;
            $args['valid'] = true;

            return $args;
        }
        $user = strtolower(trim((string) $args['user']));
        if ($user === '') {
            return $args;
        }
        if (strpos($user, '@') === false) {
            $user .= '@'.$domain;
        } elseif (substr($user, -strlen('@'.$domain)) !== '@'.$domain) {
            $args['abort'] = true;
            $args['error'] = 'Zde se přihlašují jen schránky domény '.$domain.'.';

            return $args;
        }
        $args['user'] = $user;

        return $args;
    }

    public function login_after($args)
    {
        if ($this->sso) {
            $_SESSION['wsp_sso'] = true;
        }

        return $args;
    }

    /** The login form asks for the mailbox name and shows the domain. */
    public function loginform($args)
    {
        $domain = rcube::Q($this->domain());
        $args['content'] = str_replace('</form>', '<p class="formbuttons" style="opacity:.7">@'.$domain.'</p></form>', $args['content']);

        return $args;
    }

    private function sso_token()
    {
        $t = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['_wsp_sso'] ?? null) : null;

        return is_string($t) ? $t : null;
    }

    /** IMAP, SMTP and ManageSieve as the master user acting for the mailbox. */
    private function act_for_mailbox()
    {
        $rc = rcmail::get_instance();
        $cid = $rc->config->get('wspanel_master_user');
        $pw = $rc->config->get('wspanel_master_password');
        foreach (['imap', 'smtp', 'managesieve'] as $p) {
            $rc->config->set($p.'_auth_cid', $cid);
            $rc->config->set($p.'_auth_pw', $pw);
        }
    }

    /** @return string|null the mailbox the token signs in, if it is valid here */
    private function consume($token, $domain)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $file = '/run/wspanel-webmail/'.$token.'.json';
        $raw = @file_get_contents($file);
        @unlink($file);
        $data = $raw === false ? null : json_decode($raw, true);
        if (!is_array($data) || ($data['expires'] ?? 0) < time()) {
            return null;
        }
        $address = strtolower((string) ($data['address'] ?? ''));

        return substr($address, -strlen('@'.$domain)) === '@'.$domain ? $address : null;
    }
}
