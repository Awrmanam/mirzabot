# Panel identity schema

## Canonical identifier

The canonical identifier is the existing `marzban_panel.id` unsigned,
auto-increment integer. It was selected because it is database-assigned,
independent of display formatting, and already present on every panel row.

No second `panel_id` column is added to `marzban_panel`; that would create two
internal identities. `code_panel` remains a unique external/legacy identifier
for integrations that have not migrated yet, but new CRUD authorization never
uses it.

## Before

`marzban_panel` contained:

- `id` primary key;
- nullable, unconstrained `code_panel`;
- `name_panel`, containing both visible text and `{emoji:key}` tokens;
- operational connection settings.

Dependent tables stored only a name or legacy code:

- `product.Location`;
- `invoice.Service_location`;
- `manualsell.codepanel`;
- `DiscountSell.code_panel`;
- optional legacy `x_ui.codepanel`.

## After the staged migration

`marzban_panel` additionally contains:

- `display_name` — visible text without the internal Custom Emoji token;
- `normalized_name` — stable comparison value, retained for deleted history;
- `active_normalized_name` — comparison value for active rows, NULL after
  deletion;
- `emoji_key` and `custom_emoji_id` — display metadata;
- `deleted_at` — soft-delete timestamp.

Constraints/indexes added only after conflict-free backfill:

- primary key on `id` remains canonical;
- unique `uq_marzban_panel_active_name(active_normalized_name)`;
- unique `uq_marzban_panel_code(code_panel)`;
- index `idx_marzban_panel_deleted_at(deleted_at)`.

The following dependent tables receive nullable, indexed `panel_id`:

- `product`;
- `invoice`;
- `manualsell`;
- `DiscountSell`;
- `x_ui`, only when the legacy table exists.

Legacy name/code columns remain in this release as snapshots and fallback
compatibility fields. Historical tables retain both nullable `panel_id` and the
original name/code snapshot.

Foreign keys are deliberately deferred. The migration first reports ambiguous
and orphaned legacy data; it never forces constraints onto a dirty database.

## Deletion

Deletion sets `deleted_at`, clears `active_normalized_name`, and marks the panel
status deleted in one transaction. Therefore:

- the panel disappears from active lists;
- the same normalized name can be created again under a new canonical ID;
- invoices and sales remain attached to their historical snapshot;
- replaying the old callback cannot target the new panel.
