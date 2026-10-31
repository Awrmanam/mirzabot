# Non-Telegram commercial account safety — staging, 2026-11-01

## Branch divergence — DO NOT MERGE IN BULK

Comparison with declared production branch rebecca-support at
2542033dd9218fd3833e7931e1fb277f79f46b8e:
development branch was 164 commits ahead, one behind, and diverged at
b3ad91b33e0bd328d5a2b238dc9c70778956486f.
There are numerous independent receipt/bank/timer scripts and changed
payment/card.php in this lineage. The live server's checkout had no .git,
so comparing GitHub refs does NOT prove which PHP bytes run in production.

For a future rollout cherry-pick only review-approved modules/patches;
compare live SHA256 of vpnapp.php, payment/card.php, croncard.php,
card_auto_match.php and receipt worker before any staging-like adaptation.
Do not merge/overwrite rebecca-support.

## Compatibility matrix

| Path | Existing type | Web account treatment |
| --- | --- | --- |
| user.id | VARCHAR(500) primary key | Explicit web_<32 hex>, never Telegram numeric spoof |
| user.Balance | INT, same canonical wallet | Topup credit and checkout debit use same row |
| invoice.id_user | VARCHAR(200) | web_ ID fits; renewal checks owner + username |
| Payment_report.id_user | VARCHAR(200) | web_ ID fits; manual topup uses special payment status and type |
| vpnapp_orders.owner_id | Legacy BIGINT | STAGING migration to VARCHAR(64); numeric IDs retained as strings |
| vpnweb_identities | New mapping to canonical Mirza user | one unique app_user_id and commercial_id |
| vpnweb_topups | New manual topup journal | Financial state stays in Payment_report/user.Balance |
| panels.php createUser | from_id inserted in panel metadata string | Real Rebecca integration remains unverified |
| panels.php extend | Takes existing service username, panel | Must verify with a real disposable Rebecca test instance |
| app_pairing.php / Telegram webhook | Numeric Telegram sender | Unchanged; never invoked for web_ identities |
| Admin bot commands | Can assume numeric Telegram destinations | Do NOT send Telegram to web_ IDs |
| Mirza wallet checkout notification | Best effort | Suppressed for web_ identities, provisioning remains independent |
| Mirza renewal notification | Best effort | Suppressed for web_ identities |
| web topup receipt | Private filesystem, 0700 | Receipt != payment, no DirectPayment triggered |
| legacy cronbot/croncard.php | Unverified waiting cart reports, bottype NULL | Topup has private payment state/method/bottype |
| legacy payment/card.php | Amount-only bank SMS match on Unpaid or waiting | **CRITICAL** topup Payment_report uses vpnweb_review_pending |
| legacy card_auto_match.php | Legacy bank automation | Never receives a pending web topup status |
| Browser commercial identity | Existing email session plus signed bridge | Unique map; fresh Telegram user requires /app OTP |
| Funded-account merge | Duplicate wallet risk | Explicitly refused pending audited reconciliation |

## Manual wallet topup safeguards

- The internal bridge uses HMAC, timestamp + nonce; browser cannot call PHP.
- A new topup creates one Payment_report row in the SAME Mirza database.
- Payment_Report.payment_Status is vpnweb_review_pending UNTIL manual approval.
- The private status is deliberately not Unpaid or waiting because the
  original payment/card.php may match a report by amount without
  considering Payment_Method or bottype.
- The payment type vpnweb_manual_wallet_topup and bottype vpnweb_manual
  add a second defense against cronbot/croncard.php.
- Photo persists privately, SHA-256 stored, but no balance change.
- Admin must independently reconcile merchant bank statement transaction
  amount and unique reference. The code cannot independently prove that
  a bank transfer occurred.
- One transactional lock coordinates payment state, increment of user.Balance
  and UNIQUE bank_reference; replay returns already approved, no second credit.
- No bot API, bank auto-match cron or DirectPayment is invoked by new actions.
- Admin approval requires authenticated FastAPI admin AND separately gated
  signed Mirza bridge action. It is disabled by default in both services.

## Remaining blockers before public release

- Real merchant bank statement review workflow and admin audit identity;
  test/admin bank_reference values are NOT legitimate payment evidence.
- Safe fund/discount/open-order reconciliation before linking funded web
  and Telegram identities; no automated merge.
- Mirror/recovery queue for web-wallet topup receipts during a complete
  Mirza outage (the current endpoint is retry-safe but not locally queued).
- Full real Rebecca staging provisioning and renewal with same remote user.
- Independent domestic SMTP/SMS code delivery and recovery.
- Diff against live files/cron settings read-only and with owner consent.
- Regression of actual Telegram bot and native Android with test users.
