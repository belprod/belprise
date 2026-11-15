#!/bin/sh
# belprise — container start: data volume writable by the web server, the
# scheduled tasks every minute (once installed), then Apache.
# A new server opens the installation assistant at http://<address>/installation.
set -e

chown www-data:www-data /var/www/belprise/data

# E-mails: PHP's mail() goes through msmtp to the SMTP server given here.
#   BELPRISE_SMTP_HOST (required to send), BELPRISE_SMTP_PORT (587), BELPRISE_SMTP_USER,
#   BELPRISE_SMTP_PASSWORD, BELPRISE_SMTP_TLS: starttls (default, port 587), tls (port 465) or off.
if [ -n "${BELPRISE_SMTP_HOST:-}" ]; then
    {
        echo "defaults"
        case "${BELPRISE_SMTP_TLS:-starttls}" in
            off) echo "tls off" ;;
            tls) echo "tls on"; echo "tls_starttls off" ;;
            *) echo "tls on"; echo "tls_starttls on" ;;
        esac
        echo "tls_trust_file /etc/ssl/certs/ca-certificates.crt"
        echo "account default"
        echo "host ${BELPRISE_SMTP_HOST}"
        echo "port ${BELPRISE_SMTP_PORT:-587}"
        if [ -n "${BELPRISE_SMTP_USER:-}" ]; then
            echo "auth on"
            echo "user ${BELPRISE_SMTP_USER}"
            echo "password ${BELPRISE_SMTP_PASSWORD:-}"
        fi
    } > /etc/msmtprc
    chown root:www-data /etc/msmtprc
    chmod 640 /etc/msmtprc
fi

(
    while true; do
        if [ -f "$BELPRISE_CONFIG" ]; then
            su -s /bin/sh www-data -c "BELPRISE_CONFIG='$BELPRISE_CONFIG' BELPRISE_FFMPEG='$BELPRISE_FFMPEG' php /var/www/belprise/app/bin/cron.php" || true
        fi
        sleep 60
    done
) &

exec docker-php-entrypoint "$@"
