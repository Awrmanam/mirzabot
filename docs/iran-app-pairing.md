# Iran App account pairing (staged, not production-enabled)

The optional `/app` command is handled by `mirza_app_bridge.php` from the validated
Telegram update path in `index.php`. The app's Iran API must deploy the matching
`/v1/bridge/mirza/pair-code` endpoint **first**.

Configure these environment variables on the PHP bot service (not in config.php,
Android, or the GitHub repo):

```sh
MIRZA_APP_API_URL=https://me2.26arman.ir
MIRZA_APP_BRIDGE_SECRET=<32+ random bytes, exactly the same as MIRZA_BRIDGE_SECRET on Iran API>
```

On the Iran API set `MIRZA_BRIDGE_SECRET` and
`MIRZA_REBECCA_PANEL_CODES` (comma-separated **exact**
`invoice.Service_location` values of the **single** configured Rebecca panel).
Codes for unrelated panels must be excluded. With an empty allowlist users can
link their login but no Rebecca accounts are automatically attached.

Implementation:
- `/app` only works in a private Telegram chat.
- Bot uses authenticated webhook sender `$from_id` and fetches the same active
  `invoice.id_user` purchases as its `/services` command; never accepts a
  user-entered ID or username as proof.
- Bot POSTs verified service candidates using a SHA-256 HMAC over timestamp,
  unique nonce, and exact JSON body; TLS verification is required.
- Iran API issues a 96-bit, one-use, 10-minute pairing code.
- App redeems code, chooses password/login name and stores its opaque session
  in Android secure storage. All future logins use only Iran API.
- Rebecca links are added only after API checks the exact remote username
  and confirmed Mirza panel-code allowlist.

No production changes should be made until a separate Telegram account with
an isolated Rebecca test subscription successfully completes the flow. Pairing
requires the bot to reach the Iran API; existing logins do not require Telegram.
Pairing grants no plan extension or payment fulfillment.
