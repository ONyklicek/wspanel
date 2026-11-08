#!/usr/bin/env bash
# WSPanel mail hosting: mailboxes (IMAP/POP3, Sieve), inbound mail for the
# panel's mail domains, submission for mail clients, spam filtering.
# Run by install.sh --mail-hosting=yes, or later by the agent (mail.install,
# Server → Moduly). Idempotent.
#
#   mail-hosting.sh <mail host name> [php version for the webmail pool]
#
# Postfix keeps sending the sites' mail as before (OpenDKIM signs it); it now
# also accepts mail for the domains in /etc/postfix/wspanel_vdomains and hands
# it to Dovecot over LMTP. Mailboxes live in /etc/dovecot/wspanel/users (the
# agent writes it; passwords arrive hashed from the panel).
set -euo pipefail
MAIL_HOST=${1:?mail host name}
PHP=${2:-8.4}
[[ "$PHP" =~ ^[0-9]\.[0-9]$ ]] || { echo "invalid PHP version" >&2; exit 2; }
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FILES="$HERE/files"; [[ -d "$FILES/webmail" ]] || FILES=/usr/lib/wspanel/files
[[ "$MAIL_HOST" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo "invalid mail host name" >&2; exit 2; }
export DEBIAN_FRONTEND=noninteractive

# Roundcube from Debian (security updates with the system), without its
# web server and database helpers: WSPanel serves it and keeps its SQLite.
echo "roundcube-core roundcube/dbconfig-install boolean false" | debconf-set-selections
echo "roundcube-core roundcube/reconfigure-webserver multiselect" | debconf-set-selections
apt-get install -y -q --no-install-recommends \
  dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve dovecot-managesieved rspamd \
  roundcube-core roundcube-sqlite3 roundcube-plugins "php$PHP-fpm" "php$PHP-sqlite3" "php$PHP-intl" "php$PHP-mbstring" "php$PHP-xml" "php$PHP-curl" "php$PHP-zip" "php$PHP-gd" sqlite3 >/dev/null

# ---------------------------------------------------------------- mailbox store
getent passwd vmail >/dev/null || useradd --system --home-dir /var/mail/vmail --shell /usr/sbin/nologin vmail
install -d -o vmail -g vmail -m 0750 /var/mail/vmail
install -d -o root -g dovecot -m 0750 /etc/dovecot/wspanel
for f in users blocked; do
  [[ -f /etc/dovecot/wspanel/$f ]] || install -o root -g dovecot -m 0640 /dev/null /etc/dovecot/wspanel/$f
done

# Spam goes to Junk (Rspamd marks it); the mailbox's own rules come after.
cat >/etc/dovecot/wspanel/spam.sieve <<'SIEVE'
require ["fileinto"];
if header :is "X-Spam" "Yes" {
  fileinto "Junk";
  stop;
}
SIEVE
sievec /etc/dovecot/wspanel/spam.sieve 2>/dev/null || true

# Moving a message into Junk teaches Rspamd spam, moving it out teaches ham
# (IMAPSieve, so it works from any mail client and from the webmail).
install -d -m 0755 /etc/dovecot/wspanel/pipe
cat >/etc/dovecot/wspanel/pipe/wspanel-learn <<'LEARN'
#!/bin/sh
# Managed by WSPanel. $1: spam|ham, the message on stdin. The controller's
# password is read from a header file, never from the command line.
case "$1" in spam|ham) ;; *) exit 1 ;; esac
exec /usr/bin/curl -fsS -m 30 -H @/var/mail/vmail/.wspanel-learn/rspamd.header \
  --data-binary @- "http://127.0.0.1:11334/learn$1" >/dev/null 2>&1
LEARN
chmod 0755 /etc/dovecot/wspanel/pipe/wspanel-learn
# The scripts compile where the imap process (vmail) may write the binaries.
LEARN=/var/mail/vmail/.wspanel-learn
install -d -o vmail -g vmail -m 0750 "$LEARN"
rm -f /etc/dovecot/wspanel/learn-spam.sieve /etc/dovecot/wspanel/learn-ham.sieve
cat >"$LEARN/learn-spam.sieve" <<'SIEVE'
require ["vnd.dovecot.pipe", "copy", "imapsieve"];
pipe :copy "wspanel-learn" ["spam"];
SIEVE
cat >"$LEARN/learn-ham.sieve" <<'SIEVE'
require ["vnd.dovecot.pipe", "copy", "imapsieve", "environment", "variables"];
if environment :matches "imap.mailbox" "*" {
  set "mailbox" "${1}";
}
if string "${mailbox}" "Trash" {
  stop;
}
pipe :copy "wspanel-learn" ["ham"];
SIEVE

# ---------------------------------------------------------------- TLS
# The agent keeps these in step with the edge's certificate for the mail host
# (mail.certs); until it has one, a self-signed pair.
install -d -m 0750 -o root -g root /etc/wspanel/mail
if [[ ! -f /etc/wspanel/mail/cert.pem ]]; then
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$MAIL_HOST" \
    -keyout /etc/wspanel/mail/key.pem -out /etc/wspanel/mail/cert.pem 2>/dev/null
  chmod 0600 /etc/wspanel/mail/key.pem
fi

# ---------------------------------------------------------------- Dovecot 2.4
[[ -f /etc/dovecot/dovecot.conf.debian ]] || cp -a /etc/dovecot/dovecot.conf /etc/dovecot/dovecot.conf.debian
cat >/etc/dovecot/dovecot.conf <<'CONF'
# Managed by WSPanel (mail-hosting.sh). The original is dovecot.conf.debian.
dovecot_config_version = 2.4.0
dovecot_storage_version = 2.4.0
protocols = imap pop3 lmtp sieve

mail_driver = maildir
mail_home = /var/mail/vmail/%{user | domain}/%{user | username}
mail_path = ~/Maildir
mail_uid = vmail
mail_gid = vmail

namespace inbox {
  inbox = yes
  mailbox Drafts {
    special_use = \Drafts
    auto = subscribe
  }
  mailbox Sent {
    special_use = \Sent
    auto = subscribe
  }
  mailbox Trash {
    special_use = \Trash
    auto = subscribe
  }
  mailbox Junk {
    special_use = \Junk
    auto = subscribe
    sieve_script learn_spam {
      type = before
      cause = copy
      path = /var/mail/vmail/.wspanel-learn/learn-spam.sieve
    }
  }
}
imapsieve_from Junk {
  sieve_script learn_ham {
    type = before
    cause = copy
    path = /var/mail/vmail/.wspanel-learn/learn-ham.sieve
  }
}

# Mailboxes: address:{SHA512-CRYPT}hash::::::<extra fields> (written by the agent).
auth_mechanisms = plain login
auth_allow_cleartext = no
# Blocked mailboxes (written by the agent): no login of any kind, the
# webmail's master login included. Mail still arrives.
passdb blocked {
  driver = passwd-file
  passwd_file_path = /etc/dovecot/wspanel/blocked
  deny = yes
}
# The webmail's master user: acts for a mailbox the panel signed in (SASL
# authorization id), from the loopback only.
passdb master {
  driver = passwd-file
  passwd_file_path = /etc/dovecot/wspanel/master-users
  master = yes
  # The mailbox it acts for must exist and pass the passdbs (blocked = denied).
  result_success = continue
}
passdb wspanel {
  driver = passwd-file
  passwd_file_path = /etc/dovecot/wspanel/users
}
userdb wspanel {
  driver = passwd-file
  passwd_file_path = /etc/dovecot/wspanel/users
}

# Per mailbox: userdb_quota/user/quota_storage_size=<N>M.
mail_plugins {
  quota = yes
}
quota user {
  storage_size = 1G
}
protocol imap {
  mail_plugins {
    imap_quota = yes
    imap_sieve = yes
  }
}
sieve_plugins {
  sieve_imapsieve = yes
  sieve_extprograms = yes
}
sieve_global_extensions {
  vnd.dovecot.pipe = yes
}
sieve_pipe_bin_dir = /etc/dovecot/wspanel/pipe

# Sieve: spam to Junk first, then the mailbox's own rules (autoreply, filters).
protocol lmtp {
  mail_plugins {
    sieve = yes
  }
  auth_username_format = %{user | lower}
}
sieve_script before {
  type = before
  path = /etc/dovecot/wspanel/spam.sieve
}
sieve_script personal {
  type = personal
  path = ~/sieve
  active_path = ~/.dovecot.sieve
}
# The autoreply set in the panel, after the mailbox's own filters (webmail).
sieve_script autoreply {
  type = after
  path = ~/wspanel-autoreply.sieve
}

ssl = required
ssl_server_cert_file = /etc/wspanel/mail/cert.pem
ssl_server_key_file = /etc/wspanel/mail/key.pem
ssl_min_protocol = TLSv1.2

service imap-login {
  inet_listener imap {
    port = 0
  }
}
service pop3-login {
  inet_listener pop3 {
    port = 0
  }
}
service lmtp {
  unix_listener /var/spool/postfix/private/dovecot-lmtp {
    user = postfix
    group = postfix
    mode = 0600
  }
}
service auth {
  unix_listener /var/spool/postfix/private/auth {
    user = postfix
    group = postfix
    mode = 0660
  }
}
CONF
doveconf -n >/dev/null

# ---------------------------------------------------------------- Rspamd
install -d /etc/rspamd/local.d
cat >/etc/rspamd/local.d/worker-proxy.inc <<'RSPAMD'
# Managed by WSPanel: milter for Postfix on the loopback only.
bind_socket = "127.0.0.1:11332";
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
RSPAMD
cat >/etc/rspamd/local.d/actions.conf <<'RSPAMD'
# Managed by WSPanel.
reject = 15;
add_header = 6;
greylist = null;
RSPAMD
cat >/etc/rspamd/local.d/milter_headers.conf <<'RSPAMD'
# Managed by WSPanel: X-Spam: Yes on spam (Sieve moves it to Junk).
use = ["x-spam-status", "x-spamd-bar", "authentication-results"];
extended_spam_headers = false;
RSPAMD
# The controller (learning, statistics) is password-only, from every address:
# Debian trusts the loopback by default, which would hand it to every site user.
install -d /etc/rspamd/override.d
if [[ ! -s /etc/wspanel/mail/rspamd-controller.pw ]]; then
  ( umask 077; head -c 32 /dev/urandom | base64 | tr -d '/+=\n' > /etc/wspanel/mail/rspamd-controller.pw )
fi
RSPAMD_HASH="$(rspamadm pw -q -p "$(cat /etc/wspanel/mail/rspamd-controller.pw)")"
cat >/etc/rspamd/override.d/worker-controller.inc <<RSPAMD
# Managed by WSPanel.
count = 1;
bind_socket = "127.0.0.1:11334";
password = "$RSPAMD_HASH";
enable_password = "$RSPAMD_HASH";
secure_ip = [];
static_dir = "\${WWWDIR}";
RSPAMD
# Spam learning from Dovecot (runs as vmail) reads it as a header.
rm -f /etc/dovecot/wspanel/rspamd-learn.header
install -o root -g vmail -m 0640 /dev/null /var/mail/vmail/.wspanel-learn/rspamd.header
printf 'Password: %s\n' "$(cat /etc/wspanel/mail/rspamd-controller.pw)" > /var/mail/vmail/.wspanel-learn/rspamd.header
rspamadm configtest >/dev/null

# Rspamd keeps its statistics (Bayes, learned spam/ham), reply tracking and
# rate limits in Valkey: its own instance on a socket only Rspamd can open.
command -v valkey-server >/dev/null || apt-get install -y -q --no-install-recommends valkey-server >/dev/null
install -d -o _rspamd -g _rspamd -m 0700 /var/lib/rspamd/valkey
cat >/etc/rspamd/valkey.conf <<'VALKEY'
# Managed by WSPanel (mail-hosting.sh): Rspamd's own Valkey, no TCP.
port 0
unixsocket /run/rspamd-valkey/valkey.sock
unixsocketperm 700
dir /var/lib/rspamd/valkey
maxmemory 256mb
maxmemory-policy volatile-ttl
save 900 1
appendonly no
VALKEY
cat >/etc/systemd/system/rspamd-valkey.service <<'UNIT'
# Managed by WSPanel (mail-hosting.sh).
[Unit]
Description=Valkey for Rspamd (WSPanel)
Before=rspamd.service
[Service]
User=_rspamd
Group=_rspamd
RuntimeDirectory=rspamd-valkey
RuntimeDirectoryMode=0700
ExecStart=/usr/bin/valkey-server /etc/rspamd/valkey.conf --supervised systemd
Type=notify
NoNewPrivileges=yes
ProtectSystem=strict
ReadWritePaths=/var/lib/rspamd/valkey
PrivateTmp=yes
[Install]
WantedBy=multi-user.target rspamd.service
UNIT
systemctl daemon-reload
systemctl enable --now rspamd-valkey >/dev/null 2>&1
cat >/etc/rspamd/local.d/redis.conf <<'RSPAMD'
# Managed by WSPanel.
servers = "/run/rspamd-valkey/valkey.sock";
RSPAMD
cat >/etc/rspamd/local.d/classifier-bayes.conf <<'RSPAMD'
# Managed by WSPanel: learn from what users move into and out of Junk.
backend = "redis";
autolearn = true;
RSPAMD

# Signing stays with OpenDKIM (it already holds the sites' keys).
cat >/etc/rspamd/local.d/dkim_signing.conf <<'RSPAMD'
enabled = false;
RSPAMD

# ---------------------------------------------------------------- Postfix
for f in wspanel_vdomains wspanel_vmailboxes wspanel_valiases wspanel_mailbox_senders; do
  [[ -f /etc/postfix/$f ]] || install -m 0644 /dev/null /etc/postfix/$f
  postmap /etc/postfix/$f
done
postconf -e \
  "inet_interfaces=all" \
  "myhostname=$MAIL_HOST" \
  "virtual_mailbox_domains=hash:/etc/postfix/wspanel_vdomains" \
  "virtual_mailbox_maps=hash:/etc/postfix/wspanel_vmailboxes" \
  "virtual_alias_maps=hash:/etc/postfix/wspanel_valiases" \
  "virtual_transport=lmtp:unix:private/dovecot-lmtp" \
  "smtpd_relay_restrictions=check_client_access cidr:/etc/postfix/wspanel_loopback_sasl, reject_unauth_destination" \
  "smtpd_recipient_restrictions=check_client_access cidr:/etc/postfix/wspanel_loopback_sasl, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination" \
  "smtpd_sasl_exceptions_networks=!127.0.0.0/8, ![::1]/128, 0.0.0.0/0, [::]/0" \
  "smtpd_discard_ehlo_keyword_address_maps=cidr:/etc/postfix/wspanel_loopback_ehlo" \
  "smtpd_helo_required=yes" \
  "disable_vrfy_command=yes" \
  "smtpd_tls_security_level=may" \
  "smtpd_tls_cert_file=/etc/wspanel/mail/cert.pem" \
  "smtpd_tls_key_file=/etc/wspanel/mail/key.pem" \
  "smtpd_tls_mandatory_protocols=>=TLSv1.2" \
  "smtpd_tls_protocols=>=TLSv1.2" \
  "message_size_limit=52428800" \
  "smtpd_milters=inet:127.0.0.1:11332, inet:127.0.0.1:8891" \
  "non_smtpd_milters=inet:127.0.0.1:8891" \
  "milter_default_action=accept"

# Port 25 is public now, but the sites' SMTP logins (sasldb) stay a loopback
# thing: AUTH is not offered outside, and even a login that succeeds from
# outside relays nothing (only loopback clients get permit_sasl_authenticated).
# Mailboxes send through 587/465 below. Sites keep talking plain SMTP on the
# loopback: STARTTLS is not offered there (the certificate is for the mail host,
# not 127.0.0.1, and a verifying client would refuse it).
cat >/etc/postfix/wspanel_loopback_sasl <<'CIDR'
# Managed by WSPanel.
127.0.0.0/8 permit_sasl_authenticated
[::1]/128 permit_sasl_authenticated
CIDR
cat >/etc/postfix/wspanel_loopback_ehlo <<'CIDR'
# Managed by WSPanel.
127.0.0.0/8 starttls
[::1]/128 starttls
CIDR

# Submission (587, STARTTLS) and SMTPS (465) for mailbox users: Dovecot SASL,
# a mailbox may send only as itself (or its aliases).
postconf -M -e "submission/inet=submission inet n - y - - smtpd"
postconf -P -e \
  "submission/inet/syslog_name=postfix/submission" \
  "submission/inet/smtpd_tls_security_level=encrypt" \
  "submission/inet/smtpd_sasl_auth_enable=yes" \
  "submission/inet/milter_macro_daemon_name=ORIGINATING" \
  "submission/inet/smtpd_sasl_exceptions_networks=" \
  "submission/inet/smtpd_discard_ehlo_keyword_address_maps=" \
  "submission/inet/smtpd_sasl_type=dovecot" \
  "submission/inet/smtpd_sasl_path=private/auth" \
  "submission/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
  "submission/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
  "submission/inet/smtpd_recipient_restrictions=permit_sasl_authenticated,reject" \
  "submission/inet/smtpd_sender_login_maps=hash:/etc/postfix/wspanel_mailbox_senders" \
  "submission/inet/smtpd_sender_restrictions=reject_sender_login_mismatch"
postconf -M -e "smtps/inet=smtps inet n - y - - smtpd"
postconf -P -e \
  "smtps/inet/syslog_name=postfix/smtps" \
  "smtps/inet/smtpd_tls_wrappermode=yes" \
  "smtps/inet/smtpd_sasl_auth_enable=yes" \
  "smtps/inet/milter_macro_daemon_name=ORIGINATING" \
  "smtps/inet/smtpd_sasl_exceptions_networks=" \
  "smtps/inet/smtpd_discard_ehlo_keyword_address_maps=" \
  "smtps/inet/smtpd_sasl_type=dovecot" \
  "smtps/inet/smtpd_sasl_path=private/auth" \
  "smtps/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
  "smtps/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
  "smtps/inet/smtpd_recipient_restrictions=permit_sasl_authenticated,reject" \
  "smtps/inet/smtpd_sender_login_maps=hash:/etc/postfix/wspanel_mailbox_senders" \
  "smtps/inet/smtpd_sender_restrictions=reject_sender_login_mismatch"

# ---------------------------------------------------------------- webmail (Roundcube)
# One install for every mail domain: the edge serves webmail.<domain> (agent,
# per domain) and the plugin signs in "name" as name@<domain>.
getent passwd webmail >/dev/null || useradd --system --user-group --no-create-home --home-dir /var/lib/wspanel-webmail --shell /usr/sbin/nologin webmail
install -d -o webmail -g webmail -m 0700 /var/lib/wspanel-webmail /var/lib/wspanel-webmail/db /var/lib/wspanel-webmail/temp
echo "d /run/wspanel-webmail 0700 webmail webmail -" > /etc/tmpfiles.d/wspanel-webmail.conf
systemd-tmpfiles --create /etc/tmpfiles.d/wspanel-webmail.conf
if [[ ! -s /etc/wspanel/mail/webmail-master.pw ]]; then
  ( umask 077; head -c 32 /dev/urandom | base64 | tr -d '/+=\n' > /etc/wspanel/mail/webmail-master.pw )
fi
MASTER_PW="$(cat /etc/wspanel/mail/webmail-master.pw)"
if [[ ! -s /etc/dovecot/wspanel/master-users ]] || ! doveadm pw -t "$(cut -d: -f2 /etc/dovecot/wspanel/master-users)" -p "$MASTER_PW" >/dev/null 2>&1; then
  install -o root -g dovecot -m 0640 /dev/null /etc/dovecot/wspanel/master-users
  printf 'webmail:%s::::::allow_real_nets=127.0.0.1/32,::1/128\n' "$(doveadm pw -s SHA512-CRYPT -p "$MASTER_PW")" > /etc/dovecot/wspanel/master-users
fi
DES_KEY_FILE=/etc/wspanel/mail/webmail-des.key
[[ -s $DES_KEY_FILE ]] || ( umask 077; head -c 18 /dev/urandom | base64 | tr -d '\n' > $DES_KEY_FILE )
[[ -f /etc/roundcube/config.inc.php.debian ]] || cp -a /etc/roundcube/config.inc.php /etc/roundcube/config.inc.php.debian
cat >/etc/roundcube/config.inc.php <<RCCONF
<?php
// Managed by WSPanel (mail-hosting.sh). The original is config.inc.php.debian.
\$config = [];
\$config['db_dsnw'] = 'sqlite:////var/lib/wspanel-webmail/db/roundcube.db?mode=0600';
// Dovecot and Postfix on the loopback (their certificate names the mail host).
\$tls = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]];
\$config['imap_host'] = 'ssl://127.0.0.1:993';
\$config['imap_conn_options'] = \$tls;
\$config['smtp_host'] = 'ssl://127.0.0.1:465';
\$config['smtp_conn_options'] = \$tls;
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['managesieve_host'] = 'tls://127.0.0.1:4190';
\$config['managesieve_conn_options'] = \$tls;
\$config['managesieve_vacation'] = 0; // the autoreply is set in the panel
\$config['des_key'] = '$(cat $DES_KEY_FILE)';
\$config['wspanel_master_user'] = 'webmail';
\$config['wspanel_master_password'] = '$MASTER_PW';
\$config['plugins'] = ['wspanel', 'archive', 'zipdownload', 'managesieve', 'markasjunk'];
\$config['product_name'] = 'Webmail';
\$config['skin'] = 'elastic';
\$config['language'] = 'cs_CZ';
\$config['identities_level'] = 1; // own address only (Postfix enforces it too)
\$config['junk_mbox'] = 'Junk';
\$config['temp_dir'] = '/var/lib/wspanel-webmail/temp/';
\$config['log_driver'] = 'syslog';
\$config['syslog_id'] = 'roundcube';
\$config['enable_installer'] = false;
\$config['use_https'] = true;
\$config['ip_check'] = true;
\$config['session_samesite'] = 'Lax';
\$config['x_frame_options'] = 'sameorigin';
\$config['login_rate_limit'] = 5;
\$config['max_message_size'] = '25M';
\$config['support_url'] = '';
RCCONF
chown root:webmail /etc/roundcube/config.inc.php && chmod 0640 /etc/roundcube/config.inc.php
# The Debian DB helper file is not used (and is www-data's).
install -d -m 0755 /usr/share/roundcube/plugins/wspanel
install -m 0644 "$FILES/webmail/wspanel.php" /usr/share/roundcube/plugins/wspanel/wspanel.php
ln -sfn /usr/share/roundcube/plugins/wspanel /var/lib/roundcube/plugins/wspanel
if [[ ! -s /var/lib/wspanel-webmail/db/roundcube.db ]]; then
  runuser -u webmail -- sqlite3 /var/lib/wspanel-webmail/db/roundcube.db < /usr/share/roundcube/SQL/sqlite.initial.sql
else
  runuser -u webmail -- php /usr/share/roundcube/bin/updatedb.sh --package=roundcube --dir=/usr/share/roundcube/SQL >/dev/null
fi
chmod 0600 /var/lib/wspanel-webmail/db/roundcube.db
# Debian's cleanup timer runs as www-data, which cannot read this setup.
systemctl disable --now roundcube-gc.timer >/dev/null 2>&1 || true
sed "s/__PHP__/$PHP/g" "$FILES/webmail/webmail-pool.conf" > "/etc/php/$PHP/fpm/pool.d/webmail.conf"
sed "s/__PHP__/$PHP/g" "$FILES/webmail/webmail.caddy" > /etc/panel-web/webmail.caddy
"php-fpm$PHP" -t 2>/dev/null
systemctl reload "php$PHP-fpm" 2>/dev/null || systemctl restart "php$PHP-fpm"
systemctl reload panel-web 2>/dev/null || true

# OpenDKIM signs what mailbox users send (marked ORIGINATING above).
if ! grep -q '^MacroList' /etc/opendkim.conf; then
  echo 'MacroList               daemon_name|ORIGINATING' >> /etc/opendkim.conf
  systemctl restart opendkim
fi

# ---------------------------------------------------------------- firewall, fail2ban
if command -v ufw >/dev/null; then
  for p in 25 465 587 993 995 4190; do ufw allow "$p/tcp" comment mail >/dev/null; done
fi
cat >/etc/fail2ban/jail.d/wspanel-mail.conf <<'F2B'
# Managed by WSPanel.
[postfix-sasl]
enabled = true
backend = systemd
[dovecot]
enabled = true
backend = systemd
F2B

systemctl enable --now dovecot rspamd >/dev/null 2>&1
systemctl restart dovecot rspamd postfix
systemctl reload fail2ban 2>/dev/null || true
touch /etc/wspanel/mail/hosting
echo "mail hosting ready for $MAIL_HOST"
