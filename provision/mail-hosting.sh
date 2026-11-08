#!/usr/bin/env bash
# WSPanel mail hosting: mailboxes (IMAP/POP3, Sieve), inbound mail for the
# panel's mail domains, submission for mail clients, spam filtering.
# Run by install.sh --mail-hosting=yes, or later by the agent (mail.install,
# Server → Moduly). Idempotent.
#
#   mail-hosting.sh <mail host name>
#
# Postfix keeps sending the sites' mail as before (OpenDKIM signs it); it now
# also accepts mail for the domains in /etc/postfix/wspanel_vdomains and hands
# it to Dovecot over LMTP. Mailboxes live in /etc/dovecot/wspanel/users (the
# agent writes it; passwords arrive hashed from the panel).
set -euo pipefail
MAIL_HOST=${1:?mail host name}
[[ "$MAIL_HOST" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo "invalid mail host name" >&2; exit 2; }
export DEBIAN_FRONTEND=noninteractive

apt-get install -y -q --no-install-recommends \
  dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve dovecot-managesieved rspamd >/dev/null

# ---------------------------------------------------------------- mailbox store
getent passwd vmail >/dev/null || useradd --system --home-dir /var/mail/vmail --shell /usr/sbin/nologin vmail
install -d -o vmail -g vmail -m 0750 /var/mail/vmail
install -d -o root -g dovecot -m 0750 /etc/dovecot/wspanel
[[ -f /etc/dovecot/wspanel/users ]] || install -o root -g dovecot -m 0640 /dev/null /etc/dovecot/wspanel/users

# Spam goes to Junk (Rspamd marks it); the mailbox's own rules come after.
cat >/etc/dovecot/wspanel/spam.sieve <<'SIEVE'
require ["fileinto"];
if header :is "X-Spam" "Yes" {
  fileinto "Junk";
  stop;
}
SIEVE
sievec /etc/dovecot/wspanel/spam.sieve 2>/dev/null || true

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
  }
}

# Mailboxes: address:{SHA512-CRYPT}hash::::::<extra fields> (written by the agent).
auth_mechanisms = plain login
auth_allow_cleartext = no
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
  }
}

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
  "smtpd_relay_restrictions=permit_sasl_authenticated, reject_unauth_destination" \
  "smtpd_recipient_restrictions=permit_sasl_authenticated, reject_non_fqdn_recipient, reject_unknown_recipient_domain, reject_unauth_destination" \
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

# Submission (587, STARTTLS) and SMTPS (465) for mailbox users: Dovecot SASL,
# a mailbox may send only as itself (or its aliases).
postconf -M -e "submission/inet=submission inet n - y - - smtpd"
postconf -P -e \
  "submission/inet/syslog_name=postfix/submission" \
  "submission/inet/smtpd_tls_security_level=encrypt" \
  "submission/inet/smtpd_sasl_auth_enable=yes" \
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
  "smtps/inet/smtpd_sasl_type=dovecot" \
  "smtps/inet/smtpd_sasl_path=private/auth" \
  "smtps/inet/smtpd_client_restrictions=permit_sasl_authenticated,reject" \
  "smtps/inet/smtpd_relay_restrictions=permit_sasl_authenticated,reject" \
  "smtps/inet/smtpd_recipient_restrictions=permit_sasl_authenticated,reject" \
  "smtps/inet/smtpd_sender_login_maps=hash:/etc/postfix/wspanel_mailbox_senders" \
  "smtps/inet/smtpd_sender_restrictions=reject_sender_login_mismatch"

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
