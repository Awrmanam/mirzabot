# Staging installation

Exercise this release on a disposable Ubuntu VM before production. Supported
systems are Ubuntu 22.04 and 24.04. The installer uses repository-default PHP
with Apache `mod_php`; it does not enable a PHP PPA or install PHP-FPM.

## Build the reviewed archive

From the final reviewed commit:

```bash
git archive --format=zip --prefix=mirzabot-release/ \
  --output=mirzabot-step3.zip HEAD
sha256sum mirzabot-step3.zip
```

Copy the archive to the VM and extract a bootstrap copy outside the live root:

```bash
sudo install -d -m 0700 /root/mirzabot-bootstrap
sudo unzip -q mirzabot-step3.zip -d /root/mirzabot-bootstrap
cd /root/mirzabot-bootstrap/mirzabot-release
```

## DNS prerequisites

Create the domain A record before installation. If AAAA exists, it must point
to this VM. Explicit expected addresses can be supplied through
`MIRZA_EXPECTED_IPV4` and `MIRZA_EXPECTED_IPV6`.

## Fresh staging install

Keep secrets out of shell history; use a temporary root-readable environment
file or a fresh administrative session:

```bash
sudo env \
  MIRZA_RELEASE_ARCHIVE=/root/mirzabot-step3.zip \
  MIRZA_RELEASE_SHA256='<sha256-from-build-machine>' \
  MIRZA_DOMAIN='bot-staging.example.com' \
  MIRZA_CERTBOT_EMAIL='ops@example.com' \
  MIRZA_BOT_TOKEN='<telegram-bot-token>' \
  MIRZA_ADMIN_ID='<numeric-telegram-admin-id>' \
  MIRZA_BOT_USERNAME='<bot-username-without-at>' \
  ./install.sh
```

Optional values include `MIRZA_DB_NAME`, `MIRZA_DB_USER`,
`MIRZA_DB_PASSWORD`, `MIRZA_WEBHOOK_SECRET`, `MIRZA_EXPECTED_IPV4`, and
`MIRZA_EXPECTED_IPV6`.

The deployment layout is:

```text
/var/www/mirzabot/
├── current -> releases/<release-id>
├── releases/<release-id>/
└── shared/
    ├── config.php
    ├── acme/
    └── backups/
```

`/var/www/html/mirzaprobotconfig` becomes a compatibility symlink to
`/var/www/mirzabot/current`.

## Post-install checks

```bash
sudo apache2ctl configtest
php --version
php -m | grep -Ei 'mysqli|pdo_mysql|curl|mbstring|xml|zip|intl'
curl -fsS https://bot-staging.example.com/health.php
sudo certbot renew --dry-run
sudo tail -n 100 /var/log/mirzabot-installer.log
```

`health.php` must return `"ok":true`. A request to `index.php` without
`X-Telegram-Bot-Api-Secret-Token` must return HTTP 403.

The installer never updates itself. A remote archive requires an explicit
release ref and every archive requires SHA-256 verification.
