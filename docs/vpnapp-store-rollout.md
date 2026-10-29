# VPN app + Mirza store staging: isolated rollout

**No production bot deployment is authorized by this PR.** Use a disposable
Telegram bot account and a staging MariaDB snapshot, then test together with
`Awrmanam/vpn-client-app` branch `feature/mirza-full-sync-store-20261029`.

## Why separate

The existing Mirza Telegram Mini App (`app/`, `api/miniapp.php`) is not
modified. Its webhook, bot API update polling, panels, active invoices, bot
checkout and scheduled payment approval remain in place. The standalone app's
backend calls an independent `api/vpnapp.php` signed server-to-server adapter.
Panel writes use Mirza's existing `ManagePanel`, `invoice` and
`Payment_report` state, not mobile-supplied panel API keys.

## Opt-in environment

On the **Mirza PHP runtime** (and its cron workers, explicitly exported via
PHP-FPM environment where necessary):

- `MIRZA_APP_PAIRING_ENABLED=1` for `/app` code issuance
- `MIRZA_APP_BRIDGE_SECRET=<same 32+ byte random key as Iran API MIRZA_BRIDGE_SECRET>`
- `MIRZA_VPNAPP_BRIDGE_ENABLED=1` for read-only account/catalog/service sync
- `MIRZA_VPNAPP_CHECKOUT_ENABLED=0` until the write tests below pass
- `MIRZA_VPNAPP_CARD_CAP_TOMAN=150000` optional amount cap

On the **Iran backend**:

- `MIRZA_BRIDGE_SECRET=<same secret>`
- `MIRZA_STORE_API_URL=https://your-mirza-host/api/vpnapp.php`
- `MIRZA_REBECCA_PANEL_CODES=...` containing exact panel codes for the selected
  Rebecca instance, not human-readable display names
- Optional `SERVICE_SOCKS5_PROXY=socks5h://...` if Iran cannot reach Mirza

All service authentication must occur over HTTPS with trusted CA certs; do not
put these secrets inside Flutter. Neither webhook nor Mini App credentials
are changed by adding this bridge.

## Sales and receipt flow

- Account, catalog, wallet and invoices are read directly from the original
  Mirza MariaDB, restricted to verified Telegram account ownership.
- New card orders create an *Unpaid invoice* and an Unpaid `Payment_report`
  entry with original `getconfigafterpay|<username>` convention. The uploaded
  JPEG/PNG receipt moves the payment into `waiting`, keeping the original
  time-based confirm engine and `DirectPayment` for creation. Admin sees the
  receipt and old-style moderation buttons in the existing report group.
- New wallet purchases reserve a price determined from Mirza's product table,
  invoke the same `ManagePanel` create engine, and use the same `invoice`
  and reporting data. A fresh app-only Mirza account does **not** need to have
  used the original Telegram Mini App.
- Wallet renewal calls the existing panel extend function with the same
  ownership and price checks, but does not call the old
  `DirectPayment/getextenduser` branch that changes wallet balance unexpectedly.
- Card renewal creates an ordinary Mirza card-wallet-credit payment; after the
  existing bot auto-confirms and credits the wallet, the separate
  `cronbot/vpnapp_renew.php` uses that wallet balance exactly once to extend
  the owned service.

For card renewal **after** isolated testing, schedule as a separate Cron job:

```sh
* * * * * /usr/bin/php /path/to/mirzabot/cronbot/vpnapp_renew.php
```

Do not wire it to the existing Mirza cronbot script. The new worker is
inactive unless both bridge and checkout feature flags are `1`. It requires
the same environment on the Cron/PHP CLI runtime.

## Required joint QA, before checkout enabled for real customers

1. Confirm all existing Mirza Telegram purchases, renewal, free trials,
   receipts, panel integrations and Mini App menu still behave unchanged.
2. Pair a disposable account with `/app`, verify an invalid code fails and a
   second device attaches to the same account. Revoke a device and confirm its
   bearer token is invalidated in Iran.
3. Compare catalog prices and wallet values with the live Mirza database and
   verify another account cannot read or buy someone else's invoices.
4. With an isolated test panel, simulate a wallet purchase and wallet renewal.
   Verify exactly one invoice, one wallet debit and one panel operation for
   repeated identical order IDs. Disconnect the panel at different points:
   status must remain `unknown` if the outcome cannot be verified.
5. With a disposable payment request and no real transfer, test receipt upload,
   admin alert with moderation controls, auto-confirm pickup and actual
   service delivery on staging. Confirm a second upload does not duplicate it.
6. Test card renewal. Confirm ordinary wallet credit is completed first, then
   independent worker creates **one** extension. Run overlapping worker
   invocations to validate the MySQL advisory lock.
7. Test blocking a Mirza user, changing prices after creating an invoice,
   expired pending requests and simultaneous API/Telegram purchases.
8. Test phone shutdown mid-request and restart; the persisted purchase UUID
   must be queried instead of submitting a new order.

**Financial limitation:** Mirza's time-based approval does **not** verify a
bank transfer. These protections reduce duplicate purchases and damage but
do not prevent fake receipts. The original bot approval policy is unchanged.

**Network limitation:** if the Iran server cannot reach Mirza, it can still
serve previously cached app data and support messages, but it cannot verify
wallet balance or execute a new Mirza purchase. An independent Iran-only
checkout replica would need a separate design.

**Client protocol limitation:** all panel services can be listed as metadata,
but the current Flutter native engine directly imports V2Ray subscriptions,
not every WireGuard/OpenVPN client configuration.
