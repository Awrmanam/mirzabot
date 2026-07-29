# Changelog

## Unreleased — stable installer and panel identity Step 3

### Added

- canonical panel identity and centralized Unicode normalization;
- separate display name and Custom Emoji metadata;
- staged migration with conflict reporting and dry-run orphan cleanup;
- release/shared/current deployment layout;
- separate update, rollback and ownership-scoped uninstall commands;
- health endpoint and webhook secret validation;
- installer, panel CRUD, migration and Custom Emoji regression tests.

### Changed

- panel selection uses inline callbacks containing canonical IDs;
- deletion is idempotent soft delete and preserves historical sales;
- update validates a database dump before forward-only migrations;
- SSL uses one Certbot webroot flow without stopping Apache;
- Ubuntu repository PHP is used with one Apache SAPI;
- API logs redact authentication and secret fields.

### Removed

- installer self-update;
- MySQL root/plugin mutations and `skip-grant-tables`;
- destructive removal of Apache, PHP, MySQL and `/var/www/html`;
- webhook registration as a schema side effect.
