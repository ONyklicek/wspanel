#!/usr/bin/env bash
# WSPanel: Postfix as the sites' outgoing mail (loopback only, a SMTP login
# per site, OpenDKIM signs). Run by install.sh, and by mail-hosting.sh
# --remove to bring Postfix back to it after mail hosting. Idempotent.
#
#   postfix-base.sh <mail host name>
set -euo pipefail
MAIL_NAME=${1:?usage: postfix-base.sh <mail host name>}
MAIL_NAME=${MAIL_NAME,,}
[[ "$MAIL_NAME" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]] || { echo "invalid mail host name: $MAIL_NAME" >&2; exit 2; }
postconf -e "myhostname=$MAIL_NAME" "myorigin=\$myhostname" "inet_interfaces=loopback-only" \
  "mydestination=" "local_transport=error:local delivery is disabled" "mynetworks=127.0.0.0/8 [::1]/128" \
  "smtpd_sasl_auth_enable=yes" "smtpd_sasl_type=cyrus" "smtpd_sasl_path=smtpd" "smtpd_sasl_security_options=noanonymous" \
  "smtpd_tls_security_level=none" \
  "smtpd_relay_restrictions=permit_sasl_authenticated, reject" \
  "smtpd_recipient_restrictions=permit_sasl_authenticated, reject" \
  "smtpd_sender_login_maps=hash:/etc/postfix/wspanel_sender_logins" \
  "smtpd_sender_restrictions=reject_sender_login_mismatch" \
  "local_login_sender_maps=hash:/etc/postfix/wspanel_login_senders, static:*" \
  "smtpd_milters=inet:127.0.0.1:8891" "non_smtpd_milters=inet:127.0.0.1:8891" "milter_default_action=accept" \
  "smtp_tls_security_level=may" "smtp_tls_CApath=/etc/ssl/certs" "message_size_limit=26214400"
postconf -F 'smtp/inet/chroot = n' # smtpd must reach /etc/sasldb2
