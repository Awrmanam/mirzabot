# Working Premium Emoji Baseline

This branch is a sanitized source snapshot taken from the server copy where the Premium/Custom Emoji administration path was visible and operational.

## Deliberately omitted or replaced

- Live `config.php` credentials were replaced with placeholders.
- Runtime logs were removed.
- Cached update state was removed.
- Database dumps and temporary backup files are ignored.

## Important

This is a behavioral baseline, not a production-ready final release. It retains known performance and migration defects so later fixes can be compared against the last working implementation.
