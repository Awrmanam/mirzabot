# Standalone VPN app integration (opt-in)

This bridge is **not** Mirza's Telegram Mini App. The existing `app/`,
`api/miniapp.php`, payment workflows, Telegram bot token, and webhook
ownership remain unchanged.

## Behavior

- A real user sends **/app** in the existing Mirza bot's **private chat**.
- The verified Telegram webhook identity is looked up in Mirza's `invoice`
  table; owned service usernames and actual `marzban_panel.code_panel` values
  are sent to Iran API in one server-signed request.
- Iran API creates a high-entropy **single-use 10-minute** code and replies.
- Mirza privately sends that code to its owner; the VPN app exchanges it at
  `POST /v1/auth/mirza/claim-device`. No email, password or extra signup.
- After successful pairing, the VPN app uses its own Iran API session; the bot
  is not required for ordinary app sessions.
- The list of panel codes trusted for Rebecca is configured on the Iran API
  using `MIRZA_REBECCA_PANEL_CODES`. Zero codes = no auto linking.

## Opt-in config (server only)

The **Mirza PHP runtime** must have these environment variables (for PHP-FPM,
ensure that the environment is actually passed to workers):

- `MIRZA_APP_PAIRING_ENABLED=1` (off by default)
- `MIRZA_APP_BRIDGE_SECRET=<32+ random bytes>`
- Optional `MIRZA_APP_BRIDGE_URL=https://me2.26arman.ir/v1/bridge/mirza/pair-code`

The **Iran API** must have `MIRZA_BRIDGE_SECRET` equal to the Mirza server's
`MIRZA_APP_BRIDGE_SECRET`, and the exact `MIRZA_REBECCA_PANEL_CODES`.
Never place these secrets inside the Android/iOS app or a GitHub file.

Requires outbound HTTPS from Mirza to Iran API with normal CA validation.
This feature is not yet deployed. Confirm on a test account before enabling.

## Staging checks

1. With feature flag off, `/app` reports unavailable and other bot commands,
   the Telegram Mini App, checkout and renewals remain unchanged.
2. With a disposable verified Telegram account, issue a code, use it once,
   verify expiry and replay rejection.
3. Repeat `/app` on another device: same account, not a second identity.
4. Test a blocked Telegram account: the existing Mirza blocked-user check
   must reject it before the command handler.
5. Verify only the account's true invoices are sent; no raw panel API tokens
   or subscription credentials are passed in pairing requests.
6. Rollback by setting `MIRZA_APP_PAIRING_ENABLED=0` without impacting sales.

**Not implemented in this phase:** purchases through the VPN app, receipt
relay, wallet state synchronization and cross-panel storefront catalog.
Those need a separate audited order adapter. Do not enable production purchases
by directly reusing a Telegram token in the VPN app.
