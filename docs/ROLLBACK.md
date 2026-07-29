# Rollback

List retained releases:

```bash
readlink -f /var/www/mirzabot/current
find /var/www/mirzabot/releases -mindepth 1 -maxdepth 1 -type d -print
```

Switch to a verified previous release:

```bash
sudo env \
  MIRZA_ROLLBACK_RELEASE='/var/www/mirzabot/releases/<previous-release-id>' \
  ./rollback.sh
```

This atomically changes the symlink, validates Apache, and reloads it.

MySQL/MariaDB may implicitly commit DDL, so release rollback does not claim to
reverse every schema change. Additive columns normally remain compatible.

Database restore is a separate destructive recovery action. Rehearse the dump
on an isolated database first:

```bash
sudo env \
  MIRZA_ROLLBACK_RELEASE='/var/www/mirzabot/releases/<previous-release-id>' \
  MIRZA_RESTORE_DATABASE=yes \
  MIRZA_ALLOW_DB_RESTORE=yes \
  MIRZA_DATABASE_BACKUP='/var/www/mirzabot/shared/backups/<validated-dump>.sql' \
  ./rollback.sh
```

The command rejects empty files and files without a mysqldump header. Data
created after the dump is lost by a database restore.
