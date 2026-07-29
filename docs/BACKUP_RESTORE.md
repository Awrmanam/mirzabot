# Backup and restore

Before migration, update creates a `--single-transaction` dump including
routines and triggers under:

```text
/var/www/mirzabot/shared/backups/
```

Validate without exposing credentials:

```bash
sudo test -s /var/www/mirzabot/shared/backups/<dump>.sql
sudo head -n 20 /var/www/mirzabot/shared/backups/<dump>.sql
```

Import into an isolated database first. Verify table counts, panels, invoices,
manual sales and application health before production restoration.

Uninstall backs up the database and retains it unless the separate
`MIRZA_CONFIRM_DATABASE_DELETE=delete-mirzabot-database` confirmation is
provided. Application files are moved below `/var/backups`, not erased.
