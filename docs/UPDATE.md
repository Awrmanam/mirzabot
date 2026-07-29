# Staging update from Step 2

These steps migrate `/var/www/html/mirzaprobotconfig` into the release/shared
layout. The legacy directory is preserved below shared storage.

1. Take a VM snapshot.
2. Build and copy the reviewed archive described in `INSTALL.md`.
3. Extract its bootstrap scripts outside the live web root.
4. Run:

```bash
cd /root/mirzabot-bootstrap/mirzabot-release
sudo env \
  MIRZA_RELEASE_ARCHIVE=/root/mirzabot-step3.zip \
  MIRZA_RELEASE_SHA256='<sha256-from-build-machine>' \
  ./update.sh
```

The updater copies legacy config to `shared`, atomically adds a webhook secret
when absent, validates mysqli/PDO, creates a validated `mysqldump`, verifies and
lints the release, runs migrations, switches the symlink, validates/reloads
Apache, runs health checks, then updates and verifies the webhook.

No live release file is overwritten. The previous release and dump remain
available.

## Migration conflicts and orphan review

The panel migration exits 2 when active normalized names conflict. It reports
canonical IDs and deletes nothing. Resolve conflicts explicitly, then rerun.

```bash
php scripts/migrate_panel_identity.php --dry-run
php scripts/cleanup_orphan_panels.php
```

Apply only an ID reported by dry-run:

```bash
php scripts/cleanup_orphan_panels.php --apply --panel-id=123
```

Cleanup only detaches an invalid relation. It never deletes invoices, manual
sales, or other historical rows.
