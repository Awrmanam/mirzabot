# Security notes

- `marzban_panel.id` is canonical. Display/normalized names, Custom Emoji
  metadata and `code_panel` are not CRUD authorization identifiers.
- Management callbacks contain only an action and numeric ID, and reload the
  row after each callback.
- Deletion is a transaction-protected soft delete; historical sales remain.
- New installs enforce `X-Telegram-Bot-Api-Secret-Token` with `hash_equals`.
- `health.php` is separate and never processes Telegram updates.
- Installer logs omit secrets; API request logs redact authentication fields.
- MySQL root authentication is unchanged; the app user is localhost-only.
- Shared config is `root:www-data`, mode 0640.
- Release archives require SHA-256 before extraction/cutover.
- First migration adds no foreign key over dirty legacy data.
- Certbot uses webroot without stopping Apache.

Static/mock CI cannot prove public DNS, real certificate issuance, systemd,
Apache/MySQL integration or Telegram delivery. Run `INSTALL.md` on a real VM.
