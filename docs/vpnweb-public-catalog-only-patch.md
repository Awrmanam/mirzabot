# Public catalog only — independent one-file Mirza patch

**STOP: not an approval to deploy.** All changes exist on the development
branch until the owner explicitly authorizes a production installation.
This is NOT the VPNAPP financial bridge. No existing Mirza files, wallet,
payment, bot, Cron, table or migrations need to change.

## Exactly one production code file after explicit approval

Source: api/vpnweb_public_catalog.php

Production destination (verify the actual web document root first):
/var/www/mirzaprobotconfig/api/vpnweb_public_catalog.php

Use the version pinned to the reviewed commit, never a moving branch.
Check SHA256 and `php -l` on a separately downloaded temporary copy.
Review its diff independently and stage it on a separate test Mirza first.
The destination file MUST be absent before first installation.

Requirements: PHP 8+, PDO MySQL, MySQL read privileges for product
and marzban_panel only. The new script imports ONLY the existing
config.php for its PDO connection, and performs SELECT/SHOW queries.
It does not call Telegram, create orders, generate panel users or update
the Mirza schema.

## Dedicated non-financial credentials

Create a fresh random 32+-byte credential, never reuse
MIRZA_APP_BRIDGE_SECRET or any panel/API/Telegram token.
Prefer a 0600 secret file owned by the PHP worker, OUTSIDE public web root:
MIRZA_PUBLIC_CATALOG_SECRET_FILE=/protected/catalog-secret
MIRZA_PUBLIC_CATALOG_ENABLED=1

Alternatively set MIRZA_PUBLIC_CATALOG_SECRET as a protected server
environment variable. Define these through the actual isolated PHP-FPM
pool/Apache service configuration, not in any public .env asset or
GET parameter. Restrict network access at firewall/Nginx to the
staging Iran backend egress IP where possible. Never publish either
credential in JavaScript, PWA, static files or Git.

POST is forbidden. GET requires X-Catalog-Timestamp within +/-90 sec and
X-Catalog-Signature = hex HMAC-SHA256 of:
GET + newline + /api/vpnweb_public_catalog.php + newline + timestamp.
Do not send the key in query strings. No signed request can perform
money, wallet, order, customer, panel or Telegram actions.

## Mirza filtering and price scope

- marzban_panel.status = active
- marzban_panel.agent IN (f, all); panel.type != Manualsale
- product.agent = f; Location = panel code, panel name or /all
- hide_panel does not contain panel name or panel code; malformed JSON excluded
- numeric positive base price_product, valid volume/time/title
- only base public price, never personalized user.pricediscount nor reseller tiers

The original Mirza product schema has NO dedicated product-active column.
Its one_buy_status limits purchase count, not product activity.
If an upgraded schema contains status_product, is_active, or status,
the code recognizes only explicit enabled values. Do not treat
one_buy_status=1 as inactive. For original Mirza, availability is governed
by public agent, panel eligibility, and hide_panel.

The JSON consists exclusively of:
panel_code, panel_name, provider_type, plan_code, title, category,
volume_gb, days, price_toman, plus envelope price_type=public_base
and scope=public. Never expose arbitrary product columns or row SELECT *.

## Tests, before any real install

In an isolated PHP environment:
php -l api/vpnweb_public_catalog.php
php tests/vpnweb_public_catalog_security.php

The MySQL regression test requires an **independent disposable staging
database**, with APP_ENV=staging and MIRZA_STAGING_PANEL_MOCK=1:
php tests/vpnweb_public_catalog_mysql.php

That test opens a transaction, inserts synthetic rows covering active,
hidden, reseller-only, inactive panel and Manualsale cases, verifies
public base prices and outputs, and ALWAYS rolls back. Never execute this
test on a production database.

Without a catalog key, the endpoint returns 403; without the explicit
enable flag, it returns 503; any non-GET method returns 403.
Never use a purchase-capable HMAC to test this endpoint.

## Iran staging site wiring — not a finance connection

On the staging FASTAPI service (not the browser):
MIRZA_PUBLIC_CATALOG_URL=https://<mirza-readonly-host>/api/vpnweb_public_catalog.php
MIRZA_PUBLIC_CATALOG_SECRET_FILE=/run/secrets/mirza-public-catalog
# Optional for a private TEST CA only:
MIRZA_PUBLIC_CATALOG_CA_CERT_FILE=/run/certs/mirza-public-ca.crt

When these are set, /v1/store/public-catalog and the existing
/web/api/public/catalog strictly use the dedicated read-only connector.
If it fails, the page reports unavailable: it does NOT silently switch
to the financially capable vpnapp.php bridge. No login or wallet
credentials are sent to the read-only endpoint.

Keep the existing Mirza financial bridge URL/key ONLY pointing at isolated
staging Mirza, if it is needed for other stage tests. Do NOT change it to
the operational server. Keep web commerce, top-up and wallet test flags
disabled while displaying the real public catalog.

The Iran app maintains a 60-second server-side cache per URL/credential;
the browser's public catalog has a 30-second cache hint, and the site
gateway currently marks results no-store. Allow ~60-90 s for price
changes depending on proxy settings. Do not cache failed or private data.

## Post-approval deployment procedure (manual)

1. Verify independent backup/snapshot and the true document root.
2. Read and review the pinned new PHP file (no other source file).
3. Create the new catalog-only secret, with 0600 access; set enable flag
   in the PHP process environment. Never reuse the checkout bridge key.
4. First place the single new PHP file and run php -l. Test unauthenticated
   GET (403), disabled flag (503), signed GET (200), POST (403), and
   inspect every returned JSON field and public price.
5. Only then set the catalog URL and same key on the isolated Iran
   Staging API; keep financial URL/key and every purchase flag unchanged.
6. GET /web/api/public/catalog on Staging twice, change a harmless TEST
   catalog row only on an isolated Mirza staging DB, confirm cache expiry,
   restore it. On operational Mirza, verify only against existing
   legitimate public prices; NEVER modify live prices to test.
7. Verify the site in logged-out and logged-in read-only modes. No
   purchases, renewals or payments should be enabled by this deployment.

## Rollback

1. Unset MIRZA_PUBLIC_CATALOG_URL and the catalog-only key from the
   Iran Staging API. Reload ONLY the staging API; public read-only
   catalog should fall back to the already-isolated staging Mirza
   connector; it must NEVER be pointed at operational Mirza finance.
2. Set MIRZA_PUBLIC_CATALOG_ENABLED=0, reload the relevant PHP service,
   then delete ONLY the newly added api/vpnweb_public_catalog.php file
   from the authorized Mirza host.
3. Delete/revoke the separate secret. There is NO schema migration,
   balance change, purchase-order change or financial rollback required.

Never remove or edit existing api/vpnapp.php, bot files, payment approval,
Cron, panel configuration or customer data as part of this patch.
